<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Backjob;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Guest;
use App\Support\Warranty as Coverage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Warranty checker: look up a finished job by plate, customer name, phone
 * or booking reference and see, for each service, when it was finished,
 * when its warranty ends, whether it is still active, the backjobs it
 * covers, and the backjobs logged so far. Staff see only their branches.
 */
class Warranty extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Warranty';

    protected static ?string $slug = 'warranty';

    protected string $view = 'filament.pages.warranty';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $customer = null;

    public function mount(): void
    {
        if ($this->customer !== null) {
            abort_unless(Guest::query()->visibleTo(auth()->user())->whereKey($this->customer)->exists(), 404);
        }
    }

    public function clearCustomer(): void
    {
        $this->customer = null;
    }

    public function logBackjobAction(): Action
    {
        return Action::make('logBackjob')
            ->label('Log a backjob')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->modalHeading('Log a backjob')
            ->modalSubmitActionLabel('Log backjob')
            ->fillForm(fn (array $arguments) => ['booking_id' => $arguments['booking'] ?? null, 'noted_on' => today()->toDateString()])
            ->schema([
                Hidden::make('booking_id'),
                Select::make('booking_item_id')
                    ->label('Service')
                    ->options(fn (Get $get) => $this->visibleBooking($get('booking_id'))?->items->pluck('service_name', 'id')->all() ?? [])
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('backjob_type_id', null)),
                Select::make('backjob_type_id')
                    ->label('Type')
                    ->options(function (Get $get) {
                        $item = $this->visibleBooking($get('booking_id'))?->items->firstWhere('id', (int) $get('booking_item_id'));
                        $covered = $item?->service?->backjobTypes->where('is_active', true)->pluck('name', 'id')->all() ?? [];

                        return $covered + ['other' => 'Other'];
                    })
                    ->helperText('Only the types this service covers, or Other.')
                    ->required(),
                DatePicker::make('noted_on')
                    ->label('Date')
                    ->displayFormat('D, M j, Y')
                    ->maxDate(today())
                    ->required(),
                Textarea::make('note')->rows(3)->maxLength(2000),
            ])
            ->action(function (array $data) {
                $booking = $this->visibleBooking($data['booking_id']);
                $item = $booking?->items->firstWhere('id', (int) $data['booking_item_id']);

                if (! $booking || ! $item) {
                    Notification::make()->danger()->title('Not logged')->body('Pick a service from this booking.')->send();

                    return;
                }

                $typeId = $data['backjob_type_id'] === 'other' ? null : (int) $data['backjob_type_id'];

                if ($typeId !== null && ! $item->service?->backjobTypes->contains('id', $typeId)) {
                    Notification::make()->danger()->title('Not logged')->body('This service doesn’t cover that type. Pick Other.')->send();

                    return;
                }

                Backjob::create([
                    'booking_id' => $booking->id,
                    'booking_item_id' => $item->id,
                    'backjob_type_id' => $typeId,
                    'branch_id' => $booking->branch_id,
                    'noted_on' => $data['noted_on'],
                    'note' => $data['note'] ?? null,
                    'status' => 'open',
                    'created_by' => auth()->id(),
                ]);

                Notification::make()->success()->title('Backjob logged')->send();
            });
    }

    public function markBackjobDoneAction(): Action
    {
        return Action::make('markBackjobDone')
            ->label('Mark done')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('The backjob is finished.')
            ->action(function (array $arguments) {
                $backjob = Backjob::query()
                    ->whereHas('booking', fn ($bookings) => $bookings->visibleTo(auth()->user()))
                    ->findOrFail($arguments['backjob'] ?? 0);

                $backjob->update(['status' => 'done']);

                Notification::make()->success()->title('Backjob done')->send();
            });
    }

    private function visibleBooking(mixed $id): ?Booking
    {
        return is_numeric($id)
            ? Booking::query()->visibleTo(auth()->user())->where('status', 'exited')->with('items.service.backjobTypes')->find((int) $id)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $guest = $this->customer ? Guest::query()->visibleTo(auth()->user())->find($this->customer) : null;

        $results = Coverage::search(auth()->user(), $this->search, $guest?->id)->map(fn (Booking $booking) => [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'name' => $booking->name,
            'phone' => $booking->phone,
            'unit' => $booking->vehicleLabel(),
            'branch' => $booking->branch?->name,
            'finished' => $booking->exited_at?->format('M j, Y'),
            'url' => BookingResource::getUrl('view', ['record' => $booking]),
            'items' => $booking->items->map(fn (BookingItem $item) => [
                'name' => $item->service_name,
                'months' => $item->warranty_months,
                'ends' => Coverage::endsOn($booking, $item)?->format('M j, Y'),
                'status' => Coverage::status($booking, $item),
                'covers' => $item->service?->backjobTypes->pluck('name')->all() ?? [],
            ])->all(),
            'backjobs' => $booking->backjobs->map(fn (Backjob $backjob) => [
                'id' => $backjob->id,
                'on' => $backjob->noted_on->format('M j, Y'),
                'service' => $backjob->item?->service_name,
                'type' => $backjob->type?->name ?? 'Other',
                'note' => $backjob->note,
                'open' => $backjob->isOpen(),
            ])->all(),
        ])->all();

        return [
            'results' => $results,
            'guest' => $guest,
            'guestUrl' => $guest ? CustomerResource::getUrl('view', ['record' => $guest]) : null,
            'searched' => trim($this->search) !== '' || $guest !== null,
        ];
    }
}
