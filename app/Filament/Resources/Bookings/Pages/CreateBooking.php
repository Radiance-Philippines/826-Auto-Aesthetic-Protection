<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Support\BookingDesk;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * A booking the team takes at the counter, over the phone or on Messenger.
 * Onsite ones are checked against the day's cap like the site's (a full day
 * needs "Book even if it's full") and confirmed straight away; the unit
 * waits for its size review unless the team already knows the size.
 */
class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return BookingDesk::book(auth()->user(), $data);
        } catch (ValidationException $e) {
            $this->failWith($e);
        } catch (LockTimeoutException) {
            Notification::make()->danger()->title('Busy, try again')->send();
            $this->halt();
        }
    }

    private function failWith(ValidationException $e): never
    {
        $message = collect($e->errors())->flatten()->first();
        Notification::make()->danger()->title('Not booked')->body($message)->persistent()->send();

        throw ValidationException::withMessages(['data.drop_off_on' => $message]);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        /** @var Booking $booking */
        $booking = $this->getRecord();

        return BookingDesk::bookedTitle($booking);
    }
}
