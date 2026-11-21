<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BranchBlockFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whole days a branch is off the calendar. "closed" shuts the shop (a
 * holiday, a brownout): no drop-offs, and the day doesn't count toward a
 * job's estimate. "full" marks the day fully booked: the shop still works,
 * but online bookings stop for it. A block without a branch covers every
 * branch. Stored as midnight to midnight (the end is the day after the last).
 *
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class BranchBlock extends Model
{
    /** @use HasFactory<BranchBlockFactory> */
    use HasFactory;

    public const KINDS = [
        'closed' => 'Closed (no drop-offs, work pauses)',
        'full' => 'Fully booked (no new bookings)',
    ];

    protected $fillable = ['branch_id', 'kind', 'starts_at', 'ends_at', 'title', 'note', 'created_by'];

    protected $attributes = [
        'kind' => 'closed',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeClosures(Builder $query): void
    {
        $query->where('kind', 'closed');
    }

    public function scopeFullDays(Builder $query): void
    {
        $query->where('kind', 'full');
    }

    /**
     * The branch's own blocks, plus the ones for every branch.
     */
    public function scopeForBranch(Builder $query, Branch|int $branch): void
    {
        $id = $branch instanceof Branch ? $branch->getKey() : $branch;

        $query->where(fn (Builder $scope) => $scope->where('branch_id', $id)->orWhereNull('branch_id'));
    }

    public function scopeOverlapping(Builder $query, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    public function isEveryBranch(): bool
    {
        return $this->branch_id === null;
    }

    public function coversDate(CarbonImmutable $date): bool
    {
        $day = $date->startOfDay();

        return $this->starts_at < $day->addDay() && $this->ends_at > $day;
    }

    /**
     * The first and the last day it covers.
     */
    public function firstDay(): CarbonImmutable
    {
        return $this->starts_at->startOfDay();
    }

    public function lastDay(): CarbonImmutable
    {
        return $this->ends_at->subSecond()->startOfDay()->max($this->firstDay());
    }

    /**
     * "Mon, Nov 30", "Dec 24 – Dec 26".
     */
    public function dateLabel(): string
    {
        $first = $this->firstDay();
        $last = $this->lastDay();

        return $last->isSameDay($first) ? $first->format('D, M j') : $first->format('M j').' – '.$last->format('M j');
    }
}
