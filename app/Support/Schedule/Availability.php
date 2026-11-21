<?php

namespace App\Support\Schedule;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchBlock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which drop-off dates a branch has room for: a month at a glance, and the
 * final check made (under the branch's lock) before a reservation is saved
 * or moved. A day is bookable when it is an open day inside the booking
 * window (lead days to the end of the month months-ahead out), isn't marked
 * fully booked, and the units dropping off that day are fewer than the cap.
 */
final class Availability
{
    /**
     * The earliest drop-off date: today plus the branch's notice in days.
     */
    public static function earliest(Branch $branch): CarbonImmutable
    {
        return today()->toImmutable()->addDays($branch->leadDays());
    }

    /**
     * The last day that can be booked: the end of the month so many months out.
     */
    public static function lastDay(Branch $branch): CarbonImmutable
    {
        return today()->toImmutable()->addMonthsNoOverflow($branch->monthsAhead())->endOfMonth()->startOfDay();
    }

    /**
     * Units counting toward the cap, per date, within a range.
     *
     * @return array<string, int>
     */
    public static function counts(Branch $branch, CarbonImmutable $from, CarbonImmutable $to, ?int $exceptBookingId = null): array
    {
        return Booking::query()
            ->countingTowardCap()
            ->where('branch_id', $branch->getKey())
            ->whereBetween('drop_off_on', [$from->toDateString(), $to->toDateString()])
            ->when($exceptBookingId, fn ($query) => $query->whereKeyNot($exceptBookingId))
            ->selectRaw('drop_off_on as day, count(*) as total')
            ->groupBy('drop_off_on')
            ->pluck('total', 'day')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Each day of a month: "open" (room left), "full" (an open day whose
     * cap is reached, or marked fully booked) or "closed" (not an open
     * day, past, too soon, or past the booking window).
     *
     * @return array{month: string, first: string, last: string, days: array<string, string>}
     */
    public static function month(Branch $branch, CarbonImmutable $month, ?int $exceptBookingId = null): array
    {
        $start = $month->startOfMonth()->startOfDay();
        $end = $start->endOfMonth()->startOfDay();
        $earliest = self::earliest($branch);
        $last = self::lastDay($branch);
        $cap = $branch->dailyCap();

        $calendar = OpenCalendar::for($branch, $start, $end);
        $counts = self::counts($branch, $start, $end, $exceptBookingId);
        $full = self::fullBlocks($branch, $start, $end);
        $days = [];

        for ($date = $start; $date <= $end; $date = $date->addDay()) {
            $key = $date->toDateString();

            $days[$key] = match (true) {
                $cap <= 0, $date < $earliest, $date > $last, ! $calendar->isOpen($date) => 'closed',
                $full->contains(fn (BranchBlock $block) => $block->coversDate($date)), ($counts[$key] ?? 0) >= $cap => 'full',
                default => 'open',
            };
        }

        return [
            'month' => $start->format('Y-m'),
            'first' => $earliest->toDateString(),
            'last' => $last->toDateString(),
            'days' => $days,
        ];
    }

    /**
     * The final word on one drop-off date: "ok", else why not ("closed",
     * "full", "too_soon" or "too_far"). The team's check ignores the notice
     * and the booking window.
     */
    public static function check(Branch $branch, CarbonImmutable $date, ?int $exceptBookingId = null, bool $byStaff = false): string
    {
        $date = $date->startOfDay();

        if (! $byStaff && $date < self::earliest($branch)) {
            return 'too_soon';
        }

        if (! $byStaff && $date > self::lastDay($branch)) {
            return 'too_far';
        }

        if ($branch->dailyCap() <= 0 || ! OpenCalendar::for($branch, $date, $date)->isOpen($date)) {
            return 'closed';
        }

        if (self::fullBlocks($branch, $date, $date)->isNotEmpty()) {
            return 'full';
        }

        return (self::counts($branch, $date, $date, $exceptBookingId)[$date->toDateString()] ?? 0) >= $branch->dailyCap() ? 'full' : 'ok';
    }

    /**
     * Units already dropping off on a date, for the queue's header.
     */
    public static function unitsOn(Branch $branch, CarbonImmutable $date): int
    {
        return self::counts($branch, $date, $date)[$date->toDateString()] ?? 0;
    }

    /**
     * "Fully booked" blocks touching a range.
     *
     * @return Collection<int, BranchBlock>
     */
    private static function fullBlocks(Branch $branch, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return BranchBlock::query()
            ->fullDays()
            ->forBranch($branch)
            ->overlapping($from->startOfDay(), $to->startOfDay()->addDay())
            ->get(['id', 'branch_id', 'kind', 'starts_at', 'ends_at']);
    }
}
