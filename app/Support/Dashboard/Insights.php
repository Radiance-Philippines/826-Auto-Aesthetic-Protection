<?php

namespace App\Support\Dashboard;

use App\Models\Backjob;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\BookingItem;
use App\Models\Branch;
use App\Models\BranchBlock;
use App\Models\Conversation;
use App\Models\Guest;
use App\Models\ReferralRedemption;
use App\Models\Review;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Support\Access;
use App\Support\InboxAccess;
use App\Support\Schedule\OpenCalendar;
use App\Support\Warranty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Every number on the owner dashboard, worked out from the bookings,
 * customers, reviews and chats the signed-in user may see, for the chosen
 * period and branch. Nothing is a target or an estimate: a figure is a
 * count or a sum of recorded rows. Each public method is one widget's
 * numbers, and uses one query per number family.
 *
 * Definitions, used on the dashboard's helper lines:
 * - Finished job: a booking that reached "exited"; it counts on the day it exited.
 * - Sales: total price (after discount) of finished jobs.
 * - Collected: money recorded as received on its paid date: the amount a
 *   manual entry says was paid, else the reservation fee marked paid.
 * - Lost: a booking that ended as cancelled, declined, expired or no-show.
 */
final class Insights
{
    /**
     * @var list<string>
     */
    public const LOST = ['cancelled', 'declined', 'expired', 'no_show'];

    /**
     * A period longer than this many days is charted by week.
     */
    public const WEEKLY_AFTER_DAYS = 62;

    public const UTILIZATION_DAYS = 14;

    public const WARRANTY_DAYS = 30;

    /**
     * @var Collection<int, Branch>|null
     */
    private ?Collection $branches = null;

    public function __construct(
        public readonly User $user,
        public readonly Period $period,
        public readonly ?int $branchId = null,
    ) {}

    /**
     * From the page filters. The branch is ignored when this user can't see it.
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function for(?User $user, ?array $filters): self
    {
        abort_unless($user, 403);

        $insights = new self($user, Period::from($filters));
        $branch = filled($filters['branch'] ?? null) ? (int) $filters['branch'] : null;

        return $branch !== null && $insights->visibleBranches()->contains('id', $branch)
            ? new self($user, $insights->period, $branch)
            : $insights;
    }

    // Scope

    /**
     * Every branch this user may see, whatever branch is chosen.
     *
     * @return Collection<int, Branch>
     */
    public function visibleBranches(): Collection
    {
        return $this->branches ??= Branch::query()->visibleTo($this->user)->ordered()->get();
    }

    public function seesManyBranches(): bool
    {
        return $this->visibleBranches()->count() > 1;
    }

    /**
     * The branches the numbers cover: the chosen one, else all this user may see.
     *
     * @return Collection<int, Branch>
     */
    public function branches(): Collection
    {
        return $this->branchId === null ? $this->visibleBranches() : $this->visibleBranches()->where('id', $this->branchId)->values();
    }

    public function can(string $area): bool
    {
        return $this->user->canAccessArea($area);
    }

    public function sees(string ...$areas): bool
    {
        return $this->user->canAccessAnyArea(...$areas);
    }

    /**
     * Bookings at this user's branches (and the chosen branch).
     *
     * @return Builder<Booking>
     */
    public function bookings(bool $everyBranch = false): Builder
    {
        return Booking::query()
            ->visibleTo($this->user)
            ->when(! $everyBranch && $this->branchId !== null, fn (Builder $query) => $query->where('branch_id', $this->branchId));
    }

    /**
     * Jobs that exited inside the period.
     *
     * @return Builder<Booking>
     */
    public function finished(bool $everyBranch = false): Builder
    {
        return $this->bookings($everyBranch)->where('status', 'exited')->whereBetween('exited_at', [$this->period->start(), $this->period->end()]);
    }

    // Needs attention

    /**
     * What is waiting right now (not limited to the period). A key is null
     * when this user's role doesn't act on it.
     *
     * @return array{size: int|null, screenshots: int|null, home: int|null, reviews: int|null, chats: int|null, late: int|null}
     */
    public function attention(): array
    {
        $sales = $this->can('bookings.sales');

        $counts = $sales
            ? $this->bookings()->toBase()->selectRaw("sum(case when status = 'payment_review' then 1 else 0 end) as screenshots, sum(case when status = 'requested' then 1 else 0 end) as home")->first()
            : null;

        return [
            'size' => $sales ? $this->bookings()->needsSizeReview()->count() : null,
            'screenshots' => $sales ? (int) $counts->screenshots : null,
            'home' => $sales ? (int) $counts->home : null,
            'reviews' => $this->can('reviews') ? $this->reviews()->pending()->count() : null,
            'chats' => InboxAccess::allows($this->user)
                ? Conversation::query()->visibleTo($this->user)->unread()->when($this->branchId, fn (Builder $query) => $query->where('conversations.branch_id', $this->branchId))->count()
                : null,
            'late' => $this->can('queue.update')
                ? $this->bookings()->whereIn('status', Booking::IN_SHOP)->whereNotNull('ready_on')->where('ready_on', '<', today()->toDateString())->count()
                : null,
        ];
    }

    /**
     * @return Builder<Review>
     */
    private function reviews(): Builder
    {
        return Review::query()
            ->when(! $this->user->isOwner(), fn (Builder $query) => $query->whereIn('branch_id', $this->user->branchIds()))
            ->when($this->branchId !== null, fn (Builder $query) => $query->where('branch_id', $this->branchId));
    }

    // Sales

    /**
     * @return array{units: int, sales: int, average: int|null, discounts: int, collected: int, ended: int, lost: int, lostRate: float|null, newCustomers: int, repeatCustomers: int, redemptions: int, points: int, redeemed: int, outstanding: int, earnedEver: int}
     */
    public function sales(): array
    {
        $done = $this->finished()->toBase()
            ->selectRaw('count(*) as units, count(total_price) as priced, coalesce(sum(total_price), 0) as sales, coalesce(sum(discount_amount), 0) as discounts')
            ->first();

        $units = (int) $done->units;
        $ended = $this->ended()->toBase()
            ->selectRaw("count(*) as ended, sum(case when status = 'exited' then 0 else 1 end) as lost")
            ->first();

        $guests = $this->finished()->whereNotNull('guest_id')->select('guest_id');
        $everyone = $units > 0 ? (int) $this->finished()->whereNotNull('guest_id')->distinct()->count('guest_id') : 0;
        $repeat = $everyone > 0
            ? (int) $this->bookings()->where('status', 'exited')->where('exited_at', '<', $this->period->start())->whereIn('guest_id', $guests)->distinct()->count('guest_id')
            : 0;

        $referral = ReferralRedemption::query()
            ->whereIn('booking_id', $this->finished()->select('bookings.id'))
            ->whereNull('voided_at')
            ->toBase()
            ->selectRaw('count(*) as redemptions, coalesce(sum(points_awarded), 0) as points')
            ->first();

        $earnedEver = (int) ReferralRedemption::query()->whereIn('code_guest_id', $this->guests()->select('guests.id'))->sum('points_awarded');
        $redeemedEver = (int) RewardRedemption::query()->whereIn('guest_id', $this->guests()->select('guests.id'))->sum('points');
        $redeemed = (int) RewardRedemption::query()
            ->whereIn('guest_id', $this->guests()->select('guests.id'))
            ->whereBetween('created_at', [$this->period->start(), $this->period->end()])
            ->sum('points');

        return [
            'units' => $units,
            'sales' => (int) $done->sales,
            'average' => $done->priced > 0 ? (int) round($done->sales / $done->priced) : null,
            'discounts' => (int) $done->discounts,
            'collected' => $this->collected(),
            'ended' => (int) $ended->ended,
            'lost' => (int) $ended->lost,
            'lostRate' => $ended->ended > 0 ? round($ended->lost / $ended->ended * 100, 1) : null,
            'newCustomers' => $everyone - $repeat,
            'repeatCustomers' => $repeat,
            'redemptions' => (int) $referral->redemptions,
            'points' => (int) $referral->points,
            'redeemed' => $redeemed,
            'outstanding' => $earnedEver - $redeemedEver,
            'earnedEver' => $earnedEver,
        ];
    }

    /**
     * Bookings that were due in the period and have finished or fell
     * through (a home request counts on the day the customer asked for).
     *
     * @return Builder<Booking>
     */
    private function ended(bool $everyBranch = false): Builder
    {
        return $this->bookings($everyBranch)
            ->whereIn('status', ['exited', ...self::LOST])
            ->whereRaw('coalesce(drop_off_on, preferred_date) between ? and ?', [$this->period->from->toDateString(), $this->period->to->toDateString()]);
    }

    private function collected(): int
    {
        return (int) $this->bookings()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$this->period->start(), $this->period->end()])
            ->toBase()
            ->selectRaw('coalesce(sum(coalesce(amount_paid, amount_due, 0)), 0) as total')
            ->value('total');
    }

    /**
     * One row per day of the period.
     *
     * @return list<array{date: string, units: int, sales: int, collected: int, discounts: int}>
     */
    public function daily(): array
    {
        $done = $this->finished()->toBase()
            ->selectRaw('date(exited_at) as day, count(*) as units, coalesce(sum(total_price), 0) as sales, coalesce(sum(discount_amount), 0) as discounts')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $paid = $this->bookings()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$this->period->start(), $this->period->end()])
            ->toBase()
            ->selectRaw('date(paid_at) as day, coalesce(sum(coalesce(amount_paid, amount_due, 0)), 0) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $rows = [];

        for ($day = $this->period->from; $day <= $this->period->to; $day = $day->addDay()) {
            $key = $day->toDateString();
            $rows[] = [
                'date' => $key,
                'units' => (int) ($done[$key]->units ?? 0),
                'sales' => (int) ($done[$key]->sales ?? 0),
                'collected' => (int) ($paid[$key] ?? 0),
                'discounts' => (int) ($done[$key]->discounts ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Sales for the chart: a point per day, or per week for a long period.
     *
     * @return array{labels: list<string>, sales: list<int>, units: int, weekly: bool}
     */
    public function series(): array
    {
        $weekly = $this->period->days() > self::WEEKLY_AFTER_DAYS;
        $points = [];
        $units = 0;

        foreach ($this->daily() as $row) {
            $units += $row['units'];
            $date = CarbonImmutable::parse($row['date']);
            $key = $weekly ? $date->startOfWeek()->toDateString() : $row['date'];
            $points[$key] = ($points[$key] ?? 0) + $row['sales'];
        }

        return [
            'labels' => array_map(fn (string $key) => ($weekly ? 'Week of ' : '').CarbonImmutable::parse($key)->format('M j'), array_keys($points)),
            'sales' => array_values($points),
            'units' => $units,
            'weekly' => $weekly,
        ];
    }

    // Services

    /**
     * @return array{services: list<array{name: string, units: int, sales: int}>, categories: list<array{name: string, units: int, sales: int}>}
     */
    public function services(): array
    {
        $rows = BookingItem::query()
            ->whereIn('booking_id', $this->finished()->select('bookings.id'))
            ->toBase()
            ->selectRaw('category_name, service_name, count(*) as units, coalesce(sum(price), 0) as sales')
            ->groupBy('category_name', 'service_name')
            ->get();

        $merge = fn (string $column, string $fallback) => $rows
            ->groupBy(fn ($row) => $row->{$column} ?: $fallback)
            ->map(fn (Collection $group, string $name) => ['name' => $name, 'units' => (int) $group->sum('units'), 'sales' => (int) $group->sum('sales')])
            ->values();

        return [
            'services' => $merge('service_name', 'Unnamed service')->all(),
            'categories' => $merge('category_name', 'No category')->sortByDesc('sales')->values()->all(),
        ];
    }

    // Branches

    /**
     * Every branch this user may see, side by side (the branch filter does not narrow it).
     *
     * @return list<array{name: string, units: int, sales: int, average: int|null, noShowRate: float|null, toReady: float|null, toExit: float|null}>
     */
    public function branchTable(): array
    {
        $done = $this->finished(everyBranch: true)->toBase()
            ->selectRaw('branch_id, count(*) as units, count(total_price) as priced, coalesce(sum(total_price), 0) as sales')
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        $ended = $this->ended(everyBranch: true)->toBase()
            ->selectRaw("branch_id, count(*) as ended, sum(case when status = 'no_show' then 1 else 0 end) as no_shows")
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        $turnaround = $this->turnaround(everyBranch: true);

        return $this->visibleBranches()->map(fn (Branch $branch) => [
            'name' => $branch->name,
            'units' => (int) ($done[$branch->id]->units ?? 0),
            'sales' => (int) ($done[$branch->id]->sales ?? 0),
            'average' => ($done[$branch->id]->priced ?? 0) > 0 ? (int) round($done[$branch->id]->sales / $done[$branch->id]->priced) : null,
            'noShowRate' => ($ended[$branch->id]->ended ?? 0) > 0 ? round($ended[$branch->id]->no_shows / $ended[$branch->id]->ended * 100, 1) : null,
            'toReady' => $turnaround['byBranch'][$branch->id]['toReady'] ?? null,
            'toExit' => $turnaround['byBranch'][$branch->id]['toExit'] ?? null,
        ])->all();
    }

    // Operations

    /**
     * Average days from arriving to ready and to exit, for jobs that exited
     * in the period. Each average skips jobs missing either timestamp, and
     * entries typed in by hand (their times are not recorded as they happened).
     *
     * @return array{toReady: float|null, toExit: float|null, byBranch: array<int, array{toReady: float|null, toExit: float|null}>}
     */
    public function turnaround(bool $everyBranch = false): array
    {
        $rows = $this->finished($everyBranch)
            ->where('source', '!=', 'manual')
            ->toBase()
            ->get(['branch_id', 'arrived_at', 'ready_at', 'exited_at']);

        $days = function (Collection $rows, string $end): ?float {
            $values = $rows
                ->filter(fn ($row) => $row->arrived_at && $row->{$end})
                ->map(fn ($row) => CarbonImmutable::parse($row->arrived_at)->diffInSeconds(CarbonImmutable::parse($row->{$end}), false) / 86400)
                ->filter(fn (float $value) => $value >= 0);

            return $values->isEmpty() ? null : round($values->avg(), 1);
        };

        return [
            'toReady' => $days($rows, 'ready_at'),
            'toExit' => $days($rows, 'exited_at'),
            'byBranch' => $rows->groupBy('branch_id')->map(fn (Collection $group) => ['toReady' => $days($group, 'ready_at'), 'toExit' => $days($group, 'exited_at')])->all(),
        ];
    }

    /**
     * Units at each step right now.
     *
     * @return array<string, int>
     */
    public function stages(): array
    {
        $counts = $this->bookings()
            ->whereIn('status', ['confirmed', ...Booking::IN_SHOP])
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'Booked' => (int) ($counts['confirmed'] ?? 0),
            'Arrived' => (int) ($counts['arrived'] ?? 0),
            'In progress' => (int) ($counts['in_progress'] ?? 0),
            'Quality check' => (int) ($counts['quality_check'] ?? 0),
            'Ready' => (int) ($counts['ready'] ?? 0),
        ];
    }

    /**
     * Booked units against the daily cap for the next 14 days, per branch.
     * A day the branch is closed is left out; a day marked fully booked
     * counts as full.
     *
     * @return array{branches: list<array{name: string, percent: int|null, openDays: int, fullDays: int, booked: int}>, heaviest: list<array{date: string, branch: string, booked: int, cap: int, marked: bool}>}
     */
    public function utilization(): array
    {
        $from = today()->toImmutable();
        $to = $from->addDays(self::UTILIZATION_DAYS - 1);
        $branches = $this->branches()->filter(fn (Branch $branch) => $branch->dailyCap() > 0);

        if ($branches->isEmpty()) {
            return ['branches' => [], 'heaviest' => []];
        }

        $booked = Booking::query()
            ->visibleTo($this->user)
            ->countingTowardCap()
            ->whereIn('branch_id', $branches->pluck('id'))
            ->whereBetween('drop_off_on', [$from->toDateString(), $to->toDateString()])
            ->toBase()
            ->selectRaw('branch_id, drop_off_on as day, count(*) as total')
            ->groupBy('branch_id', 'drop_off_on')
            ->get()
            ->groupBy('branch_id')
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn ($row) => [substr((string) $row->day, 0, 10) => (int) $row->total]));

        $blocks = BranchBlock::query()->overlapping($from, $to->addDay())->get(['id', 'branch_id', 'kind', 'starts_at', 'ends_at']);
        $summary = [];
        $days = [];

        foreach ($branches as $branch) {
            $mine = $blocks->filter(fn (BranchBlock $block) => $block->branch_id === null || $block->branch_id === $branch->id);
            $calendar = new OpenCalendar($branch, $mine->where('kind', 'closed')->values());
            $full = $mine->where('kind', 'full')->values();
            $cap = $branch->dailyCap();
            $used = 0;
            $open = 0;
            $marked = 0;
            $total = 0;

            for ($day = $from; $day <= $to; $day = $day->addDay()) {
                if (! $calendar->isOpen($day)) {
                    continue;
                }

                $count = (int) ($booked[$branch->id][$day->toDateString()] ?? 0);
                $isMarked = $full->contains(fn (BranchBlock $block) => $block->coversDate($day));
                $open++;
                $marked += $isMarked ? 1 : 0;
                $used += $isMarked ? max($count, $cap) : $count;
                $total += $count;
                $days[] = ['date' => $day->toDateString(), 'branch' => $branch->name, 'booked' => $count, 'cap' => $cap, 'marked' => $isMarked];
            }

            $summary[] = [
                'name' => $branch->name,
                'percent' => $open > 0 ? (int) round($used / ($open * $cap) * 100) : null,
                'openDays' => $open,
                'fullDays' => $marked,
                'booked' => $total,
            ];
        }

        $heaviest = collect($days)
            ->filter(fn (array $day) => $day['booked'] > 0 || $day['marked'])
            ->sortBy([
                fn (array $a, array $b) => ($b['marked'] ? 1 : $b['booked'] / $b['cap']) <=> ($a['marked'] ? 1 : $a['booked'] / $a['cap']),
                fn (array $a, array $b) => $b['booked'] <=> $a['booked'],
                fn (array $a, array $b) => $a['date'] <=> $b['date'],
            ])
            ->take(5)
            ->values()
            ->all();

        return ['branches' => $summary, 'heaviest' => $heaviest];
    }

    // Customers and content

    /**
     * @return Builder<Guest>
     */
    private function guests(): Builder
    {
        return Guest::query()
            ->visibleTo($this->user)
            ->when($this->branchId !== null, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->where('guests.branch_id', $this->branchId)
                ->orWhereHas('bookings', fn (Builder $bookings) => $bookings->where('bookings.branch_id', $this->branchId))));
    }

    /**
     * @return array{new: int|null, total: int|null, top: list<array{name: string, units: int, spend: int}>|null, referrers: list<array{name: string, used: int, points: int}>|null, warranty: int|null, rating: float|null, reviews: int|null}
     */
    public function customers(): array
    {
        $customers = $this->can('customers.view');

        return [
            'new' => $customers ? $this->guests()->whereBetween('guests.created_at', [$this->period->start(), $this->period->end()])->count() : null,
            'total' => $customers ? $this->guests()->count() : null,
            'top' => $customers && $this->can('bookings.sales') ? $this->topCustomers() : null,
            'referrers' => $customers && $this->can('bookings.sales') ? $this->topReferrers() : null,
            'warranty' => $this->can('warranty') ? $this->warrantyEnding() : null,
        ] + ($this->can('reviews') ? $this->approvedReviews() : ['rating' => null, 'reviews' => null]);
    }

    /**
     * Backjobs still open at this user's branches (and the chosen branch), whatever the period.
     */
    public function openBackjobs(): int
    {
        return Backjob::query()
            ->where('status', 'open')
            ->whereHas('booking', fn (Builder $bookings) => $bookings->visibleTo($this->user)->when($this->branchId !== null, fn (Builder $query) => $query->where('branch_id', $this->branchId)))
            ->count();
    }

    /**
     * @return array{rating: float|null, reviews: int}
     */
    public function approvedReviews(): array
    {
        $row = $this->reviews()->approved()->toBase()->selectRaw('count(*) as total, avg(rating) as average')->first();

        return ['rating' => $row->total > 0 ? round((float) $row->average, 1) : null, 'reviews' => (int) $row->total];
    }

    /**
     * @return list<array{name: string, units: int, spend: int}>
     */
    private function topCustomers(): array
    {
        $rows = $this->finished()
            ->whereNotNull('guest_id')
            ->toBase()
            ->selectRaw('guest_id, count(*) as units, coalesce(sum(total_price), 0) as spend')
            ->groupBy('guest_id')
            ->orderByDesc('spend')
            ->orderByDesc('units')
            ->limit(5)
            ->get();

        $names = Guest::query()->whereIn('id', $rows->pluck('guest_id'))->pluck('name', 'id');

        return $rows->map(fn ($row) => ['name' => $names[$row->guest_id] ?? 'Customer', 'units' => (int) $row->units, 'spend' => (int) $row->spend])->all();
    }

    /**
     * The customers whose codes were used on the most finished jobs in the
     * period, with the points those jobs awarded them.
     *
     * @return list<array{name: string, used: int, points: int}>
     */
    private function topReferrers(): array
    {
        $rows = ReferralRedemption::query()
            ->whereIn('booking_id', $this->finished()->select('bookings.id'))
            ->whereIn('code_guest_id', Guest::query()->visibleTo($this->user)->select('guests.id'))
            ->whereNull('voided_at')
            ->toBase()
            ->selectRaw('code_guest_id, count(*) as used, coalesce(sum(points_awarded), 0) as points')
            ->groupBy('code_guest_id')
            ->orderByDesc('used')
            ->orderByDesc('points')
            ->orderBy('code_guest_id')
            ->limit(10)
            ->get();

        $names = Guest::query()->whereIn('id', $rows->pluck('code_guest_id'))->pluck('name', 'id');

        return $rows->map(fn ($row) => ['name' => $names[$row->code_guest_id] ?? 'Customer', 'used' => (int) $row->used, 'points' => (int) $row->points])->all();
    }

    /**
     * Finished jobs with a service whose warranty ends within the next 30 days.
     */
    private function warrantyEnding(): int
    {
        $items = BookingItem::query()->whereIn('booking_id', $this->bookings()->where('status', 'exited')->select('bookings.id'))->where('warranty_months', '>', 0);
        $longest = (int) (clone $items)->max('warranty_months');

        if ($longest === 0) {
            return 0;
        }

        $today = today()->toImmutable();
        $until = $today->addDays(self::WARRANTY_DAYS);

        return $items
            ->with('booking:id,exited_at')
            ->whereHas('booking', fn (Builder $booking) => $booking->where('exited_at', '>=', $today->subMonthsNoOverflow($longest)->subDay()))
            ->get()
            ->filter(function (BookingItem $item) use ($today, $until) {
                $ends = Warranty::endsOn($item->booking, $item);

                return $ends !== null && $ends >= $today && $ends <= $until;
            })
            ->pluck('booking_id')
            ->unique()
            ->count();
    }

    // Staff

    /**
     * Finished jobs for each person's own customers, the referral codes those
     * customers used on them, and the status moves recorded under their
     * name, in the period.
     *
     * @return list<array{name: string, role: string, jobs: int, sales: int, moves: int, referrals: int}>
     */
    public function staff(): array
    {
        $reps = $this->finished()
            ->whereIn('guest_id', Guest::query()->whereNotNull('sales_rep_id')->select('id'))
            ->toBase()
            ->selectRaw('(select sales_rep_id from guests where guests.id = bookings.guest_id) as user_id, count(*) as jobs, coalesce(sum(total_price), 0) as sales')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $moves = BookingEvent::query()
            ->where('kind', 'status')
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [$this->period->start(), $this->period->end()])
            ->whereIn('booking_id', $this->bookings()->select('bookings.id'))
            ->toBase()
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $referrals = ReferralRedemption::query()
            ->whereIn('booking_id', $this->finished()->select('bookings.id'))
            ->whereNull('voided_at')
            ->join('guests', 'guests.id', '=', 'referral_redemptions.used_by_guest_id')
            ->whereNotNull('guests.sales_rep_id')
            ->toBase()
            ->selectRaw('guests.sales_rep_id as user_id, count(*) as total')
            ->groupBy('guests.sales_rep_id')
            ->pluck('total', 'user_id');

        $users = User::query()->whereIn('id', $reps->keys()->merge($moves->keys())->merge($referrals->keys())->unique())->get()->keyBy('id');

        return $users
            ->map(fn (User $user) => [
                'name' => $user->name,
                'role' => $user->roleLabel(),
                'jobs' => (int) ($reps[$user->id]->jobs ?? 0),
                'sales' => (int) ($reps[$user->id]->sales ?? 0),
                'moves' => (int) ($moves[$user->id] ?? 0),
                'referrals' => (int) ($referrals[$user->id] ?? 0),
            ])
            ->sort(fn (array $a, array $b) => [$b['jobs'], $b['moves']] <=> [$a['jobs'], $a['moves']])
            ->values()
            ->all();
    }

    public function seesStaff(): bool
    {
        return $this->user->isOwner() || $this->user->role === Access::BRANCH_MANAGER;
    }
}
