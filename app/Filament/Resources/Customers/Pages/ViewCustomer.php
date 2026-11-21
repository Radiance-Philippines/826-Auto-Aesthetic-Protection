<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Pages\Warranty;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Guest;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('warranty')
                ->label('Warranty')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray')
                ->url(fn (Guest $record) => Warranty::getUrl(['customer' => $record->id])),
            DeleteAction::make()
                ->before(function (DeleteAction $action, Guest $record) {
                    if ($record->bookings()->exists()) {
                        Notification::make()->danger()->title('Not deleted')->body('This customer has bookings. Keep them, or move the bookings first.')->persistent()->send();
                        $action->halt();
                    }
                }),
        ];
    }
}
