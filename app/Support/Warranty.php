<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

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
}
