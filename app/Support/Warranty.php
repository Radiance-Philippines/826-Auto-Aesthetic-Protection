<?php

namespace App\Support;

use App\Models\Backjob;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Warranty coverage: each service on a finished booking is covered from
 * the day the unit exited, for the months its service had when it was
 * booked. No months stated means no warranty.
 */
final class Warranty
{
    /**
     * The last covered day, or null (no warranty stated, or not finished).
     * A month that is shorter ends on its last day: Jan 31 plus 1 month is Feb 28.
     */
    public static function endsOn(Booking $booking, BookingItem $item): ?CarbonImmutable
    {
        if ($booking->exited_at === null || ! $item->warranty_months) {
            return null;
        }

        return $booking->exited_at->startOfDay()->addMonthsNoOverflow($item->warranty_months);
    }

    /**
     * active, expired, or none (no warranty stated).
     */
    public static function status(Booking $booking, BookingItem $item): string
    {
        $ends = self::endsOn($booking, $item);

        return match (true) {
            $ends === null => 'none',
            $ends->greaterThanOrEqualTo(today()) => 'active',
            default => 'expired',
        };
    }

    /**
     * Finished bookings matching a plate, customer name, phone number or
     * booking reference, or all of one customer's; only the branches the
     * team member works at.
     *
     * @return Collection<int, Booking>
     */
    public static function search(?User $user, string $term, ?int $guestId = null, int $limit = 25): Collection
    {
        $term = trim($term);

        if ($term === '' && $guestId === null) {
            return new Collection;
        }

        // +63 917… and 0917… are the same number: compare the last 10 digits
        $digits = preg_replace('/\D/', '', $term);
        $digits = strlen($digits) >= 10 ? substr($digits, -10) : $digits;
        $plate = Booking::normalizePlate($term);

        return Booking::query()
            ->visibleTo($user)
            ->where('status', 'exited')
            ->when($guestId, fn ($bookings) => $bookings->where('guest_id', $guestId))
            ->when($term !== '', fn ($bookings) => $bookings->where(fn ($match) => $match
                ->where('reference', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->when($plate !== '', fn ($or) => $or->orWhere('plate_number', 'like', "%{$plate}%"))
                ->when(strlen($digits) >= 4, fn ($or) => $or->orWhereRaw("replace(replace(replace(replace(phone, ' ', ''), '-', ''), '+', ''), '(', '') like ?", ["%{$digits}%"]))))
            ->with(['branch', 'items.service.backjobTypes', 'backjobs.type', 'backjobs.item'])
            ->latest('exited_at')
            ->limit($limit)
            ->get();
    }

    /**
     * A finished job this person may log a backjob against; null otherwise.
     */
    public static function finishedJob(?User $user, mixed $id): ?Booking
    {
        return is_numeric($id)
            ? Booking::query()->visibleTo($user)->where('status', 'exited')->with('items.service.backjobTypes')->find((int) $id)
            : null;
    }

    /**
     * The backjob types this service covers, and "Other".
     *
     * @return array<int|string, string>
     */
    public static function typeOptions(?BookingItem $item): array
    {
        $covered = $item?->service?->backjobTypes->where('is_active', true)->pluck('name', 'id')->all() ?? [];

        return $covered + ['other' => 'Other'];
    }

    /**
     * @param  array{booking_id: mixed, booking_item_id: mixed, backjob_type_id: mixed, noted_on: string, note?: string|null}  $data
     *
     * @throws ValidationException
     */
    public static function logBackjob(?User $user, array $data): Backjob
    {
        $booking = self::finishedJob($user, $data['booking_id']);
        $item = $booking?->items->firstWhere('id', (int) $data['booking_item_id']);

        if (! $booking || ! $item) {
            throw ValidationException::withMessages(['booking_item_id' => 'Pick a service from this booking.']);
        }

        $typeId = $data['backjob_type_id'] === 'other' ? null : (int) $data['backjob_type_id'];

        if ($typeId !== null && ! $item->service?->backjobTypes->contains('id', $typeId)) {
            throw ValidationException::withMessages(['backjob_type_id' => 'This service doesn’t cover that type. Pick Other.']);
        }

        return Backjob::create([
            'booking_id' => $booking->id,
            'booking_item_id' => $item->id,
            'backjob_type_id' => $typeId,
            'branch_id' => $booking->branch_id,
            'noted_on' => $data['noted_on'],
            'note' => $data['note'] ?? null,
            'status' => 'open',
            'created_by' => $user?->id,
        ]);
    }

    /**
     * A backjob on one of this person's branches' bookings (404 otherwise) is finished.
     */
    public static function finishBackjob(?User $user, mixed $id): void
    {
        Backjob::query()
            ->whereHas('booking', fn ($bookings) => $bookings->visibleTo($user))
            ->findOrFail($id)
            ->update(['status' => 'done']);
    }
}
