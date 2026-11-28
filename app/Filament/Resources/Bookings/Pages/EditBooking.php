<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Support\HeadsUp;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * The customer's details, vehicle and notes. The date and services change
 * through Move and Change ready date, so the daily cap is always re-checked.
 */
class EditBooking extends EditRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * A changed unit needs its size looked at again, and sales is told.
     */
    protected function afterSave(): void
    {
        /** @var Booking $booking */
        $booking = $this->getRecord();

        // A finished or cancelled job keeps its price: nothing could price it again
        if (! $booking->isOpen() || ! $booking->wasChanged(['vehicle_year', 'vehicle_model', 'vehicle_type', 'plate_number'])) {
            return;
        }

        $change = $booking->events()->where('kind', 'unit')->first();

        $booking->update(['priced_at' => null]);

        HeadsUp::send($booking, 'the unit', $change?->from, $change?->to);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
