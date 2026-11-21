<?php

namespace App\Support\Schedule;

use App\Models\Branch;
use App\Models\BranchBlock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which days a branch works: a weekday with opening hours, minus closure
 * blocks (holidays, brownouts). Works out the day a job dropped off on a
 * given date is ready: its Nth open day, the drop-off day counting as the
 * first. Bookings and the daily cap don't come into it; that's Availability.
 */
final class OpenCalendar
{
    /**
     * How far ahead a job's ready date is looked for before giving up.
     */
    public const MAX_WALK_DAYS = 120;

    /**
     * @param  Collection<int, BranchBlock>  $closures
     */
    public function __construct(
        private readonly Branch $branch,
        private readonly Collection $closures,
    ) {}

    /**
     * The branch's calendar, with every closure that a job dropped off by
     * $to could run into (one query).
     */
    public static function for(Branch $branch, CarbonImmutable $from, CarbonImmutable $to): self
    {
        $closures = BranchBlock::query()
            ->closures()
            ->forBranch($branch)
            ->overlapping($from->startOfDay(), $to->startOfDay()->addDays(self::MAX_WALK_DAYS + 2))
            ->get(['id', 'branch_id', 'kind', 'starts_at', 'ends_at']);

        return new self($branch, $closures);
    }

    public function isOpen(CarbonImmutable $date): bool
    {
        return $this->branch->opensOn($date) && ! $this->closures->contains(fn (BranchBlock $block) => $block->coversDate($date));
    }

    /**
     * The date a job of $days open days is ready when dropped off on
     * $dropOff, or null when that is more than MAX_WALK_DAYS out.
     */
    public function readyOn(CarbonImmutable $dropOff, int $days): ?CarbonImmutable
    {
        if ($days <= 0) {
            return null;
        }

        $date = $dropOff->startOfDay();
        $counted = 0;

        for ($i = 0; $i <= self::MAX_WALK_DAYS; $i++, $date = $date->addDay()) {
            if ($this->isOpen($date) && ++$counted === $days) {
                return $date;
            }
        }

        return null;
    }
}
