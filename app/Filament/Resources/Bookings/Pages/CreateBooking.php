<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Support\Reservations;
use Carbon\CarbonImmutable;
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
        $branch = Branch::query()->visibleTo(auth()->user())->findOrFail($data['branch_id']);
        $home = ($data['kind'] ?? 'onsite') === 'home';

        try {
            $quote = Reservations::quote($branch, array_map('intval', $data['services'] ?? []), home: $home);
        } catch (ValidationException $e) {
            $this->failWith($e);
        }

        // The same phone number is the same customer: their units and history stay together
        $guest = Guest::findOrCreateByPhone($data['name'], $data['phone'], $branch->id, $data['email'] ?? null, in_array($data['source'] ?? null, ['walk_in', 'phone'], true) ? $data['source'] : 'admin');

        $details = [
            ...$data,
            'admin_note' => $data['admin_note'] ?? null,
        ];

        if ($home) {
            $booking = Reservations::requestHomeService($branch, $guest, $quote, [
                ...$details,
                'preferred_date' => $data['preferred_date'] ?? (filled($data['drop_off_on'] ?? null) ? CarbonImmutable::parse($data['drop_off_on'])->toDateString() : today()->toDateString()),
                'preferred_window' => $data['preferred_window'] ?? 'any',
            ], byStaff: true);

            $booking->update(array_filter([
                'source' => $data['source'],
                'admin_note' => $data['admin_note'] ?? null,
                'read_at' => now(),
            ], fn ($value) => $value !== null));

            // "Team comes on" filled in: the day is already agreed
            if (filled($data['drop_off_on'] ?? null)) {
                Reservations::scheduleHomeVisit($booking, CarbonImmutable::parse($data['drop_off_on']));
            }

            return $booking;
        }

        try {
            return Reservations::reserve(
                $branch,
                $guest,
                $quote,
                CarbonImmutable::parse($data['drop_off_on']),
                $details,
                source: $data['source'] ?? 'admin',
                force: (bool) ($data['force'] ?? false),
            );
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

        return $booking->isHome() ? 'Home service request added' : 'Booked: '.$booking->whenLabel();
    }
}
