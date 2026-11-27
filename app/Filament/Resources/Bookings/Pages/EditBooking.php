<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Support\BookingDesk;
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

        BookingDesk::afterEdit($booking);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
