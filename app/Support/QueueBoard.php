<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a branch's queue board holds for a day: the units in the shop, and
 * the ones booked to drop off that day. Shared by the admin Queue page and
 * the staff app.
 */
final class QueueBoard
{
    /**
     * @return Collection<int, Booking>
     */
    public static function bookings(Branch $branch, CarbonImmutable $day): Collection
    {
        return Booking::query()
            ->where('branch_id', $branch->id)
            ->where(fn ($board) => $board
                ->whereIn('status', Booking::IN_SHOP)
                ->orWhere(fn ($booked) => $booked
                    ->where('drop_off_on', $day->toDateString())
                    ->where(fn ($status) => $status
                        ->whereIn('status', ['confirmed', 'payment_review'])
                        ->orWhere(fn ($hold) => $hold->where('status', 'awaiting_payment')->where('hold_expires_at', '>', now())))))
            ->with('items')
            ->orderBy('drop_off_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * The board column a booking sits in: unpaid and screenshot-waiting
     * bookings are still "booked" (confirmed).
     */
    public static function column(Booking $booking): string
    {
        return in_array($booking->status, ['confirmed', 'payment_review', 'awaiting_payment'], true) ? 'confirmed' : $booking->status;
    }
}
