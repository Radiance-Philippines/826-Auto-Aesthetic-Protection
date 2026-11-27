<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Pages\Inbox;
use App\Filament\Pages\Warranty;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\ReferralSetting;
use App\Support\CustomerDesk;
use App\Support\Rewards;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    private function chatOf(Guest $guest): ?int
    {
        return CustomerDesk::chatId($guest, auth()->user());
    }

    /**
     * Spends some of the customer's points: for a discount on one of their
     * bookings (once the owner has set what a point is worth) or as an
     * adjustment with a note.
     */
    public static function redeemPoints(): Action
    {
        return Action::make('redeemPoints')
            ->label('Redeem points')
            ->icon(Heroicon::OutlinedGift)
            ->color('gray')
            ->authorize(fn () => (bool) auth()->user()?->canAccessArea('customers.write') && (bool) auth()->user()?->canAccessArea('bookings.sales'))
            ->modalHeading(fn (Guest $record) => "Redeem points: {$record->pointsBalance()} available")
            ->modalDescription(fn () => CustomerDesk::redeemDescription())
            ->schema([
                TextInput::make('points')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(fn (Guest $record) => max(1, $record->pointsBalance()))
                    ->required(),
                Select::make('booking_id')
                    ->label('Take it off this booking')
                    ->options(fn (Guest $record) => CustomerDesk::redeemableBookings($record, auth()->user()))
                    ->visible(fn () => (bool) ReferralSetting::current()->peso_per_point),
                TextInput::make('note')
                    ->maxLength(190)
                    ->helperText('Required when no booking is picked.'),
            ])
            ->modalSubmitActionLabel('Redeem')
            ->action(function (Guest $record, array $data, Action $action) {
                try {
                    $redemption = Rewards::redeem(
                        $record,
                        auth()->user(),
                        (int) $data['points'],
                        filled($data['booking_id'] ?? null) ? Booking::query()->findOrFail($data['booking_id']) : null,
                        $data['note'] ?? null,
                    );
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Not redeemed')->body(collect($e->errors())->flatten()->first())->send();
                    $action->halt();
                }

                Notification::make()->success()->title("{$redemption->points} points redeemed")->body($redemption->peso_value ? '₱'.number_format($redemption->peso_value).' off the booking' : null)->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            self::redeemPoints(),
            Action::make('warranty')
                ->label('Warranty')
                ->visible(fn () => Warranty::canAccess())
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray')
                ->url(fn (Guest $record) => Warranty::getUrl(['customer' => $record->id])),
            Action::make('messenger')
                ->label('Messenger chat')
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->color('gray')
                ->visible(fn (Guest $record) => Inbox::canAccess() && $this->chatOf($record) !== null)
                ->url(fn (Guest $record) => Inbox::getUrl(['conversation' => $this->chatOf($record)])),
            DeleteAction::make()
                ->before(function (DeleteAction $action, Guest $record) {
                    if ($reason = CustomerDesk::keepReason($record)) {
                        Notification::make()->danger()->title('Not deleted')->body($reason)->persistent()->send();
                        $action->halt();
                    }
                }),
        ];
    }
}
