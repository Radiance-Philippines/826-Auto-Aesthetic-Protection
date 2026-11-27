<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\User;
use App\Support\Schedule\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a branch's queue board holds for a day: the units in the shop, the
 * ones booked to drop off that day, the day's count against the cap and
 * today's arrivals. Shared by the admin Queue pages (Filament and React)
 * and the staff app.
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

    /**
     * The day asked for ("2026-11-14"), else today.
     */
    public static function day(mixed $date): CarbonImmutable
    {
        return is_string($date) && CarbonImmutable::canBeCreatedFromFormat($date, 'Y-m-d')
            ? CarbonImmutable::parse($date)->startOfDay()
            : CarbonImmutable::today();
    }

    /**
     * How many units drop off that day against the daily cap.
     *
     * @return array{cap: int, units: int, state: string}
     */
    public static function dropOffs(Branch $branch, CarbonImmutable $day): array
    {
        $cap = $branch->dailyCap();

        return [
            'cap' => $cap,
            'units' => Availability::unitsOn($branch, $day),
            'state' => $cap > 0 ? Availability::check($branch, $day, byStaff: true) : 'closed',
        ];
    }

    /**
     * Booked units still to arrive today.
     *
     * @return Collection<int, Booking>
     */
    public static function arrivals(Branch $branch): Collection
    {
        return Booking::query()
            ->where('branch_id', $branch->id)
            ->where('status', 'confirmed')
            ->where('drop_off_on', today()->toDateString())
            ->with('items')
            ->orderBy('id')
            ->get();
    }

    public static function sizeReviews(Branch $branch): int
    {
        return Booking::query()->where('branch_id', $branch->id)->needsSizeReview()->count();
    }

    public static function homeRequests(Branch $branch): int
    {
        return Booking::query()->where('branch_id', $branch->id)->where('kind', 'home')->where('status', 'requested')->count();
    }

    /**
     * A card on the admin's board, for what this person may do with it.
     *
     * @return array<string, mixed>
     */
    public static function card(Booking $booking, User $user): array
    {
        $moves = $user->canAccessArea('queue.update');
        $next = $booking->nextStatus();

        return [
            'id' => $booking->id,
            'name' => $booking->name,
            'unit' => $booking->vehicleLabel() ?: '—',
            'assignedTo' => $booking->assigned_to,
            'canAssign' => $booking->isOpen() && $moves,
            'services' => $booking->servicesLabel(),
            'readyOn' => $booking->ready_on?->format('D, M j'),
            'home' => $booking->isHome(),
            'needsSize' => $booking->needsSizeReview(),
            'note' => match ($booking->stage()) {
                'awaiting_payment' => 'Waiting for the fee',
                'payment_review' => 'Screenshot to check',
                default => null,
            },
            'next' => $next,
            'canAdvance' => $next !== null && $moves,
            'button' => $next ? 'Mark '.strtolower(Booking::STATUSES[$next]) : null,
        ];
    }
}
