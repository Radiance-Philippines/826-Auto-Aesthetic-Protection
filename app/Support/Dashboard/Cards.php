<?php

namespace App\Support\Dashboard;

use App\Filament\Pages\Inbox;
use App\Filament\Pages\Queue;
use App\Filament\Pages\Warranty;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What each dashboard card says: its labels, numbers, helper lines and where
 * it leads. The Filament widgets and the React dashboard both draw these;
 * who sees which card stays with each widget's canView().
 *
 * A stat is ['key', 'label', 'value', 'help', 'tone' => primary|info|warning|gray|null, 'url'].
 * A block is a table ['title', 'help', 'columns', 'rows', 'empty'] or
 * ['type' => 'stats', 'title', 'help', 'items']; 'wide' ones take a full row.
 */
final class Cards
{
    public const CHART_HELP = 'Total price of jobs that exited, by the day they exited.';

    public const CHART_EMPTY = 'No finished jobs in this period.';

    public static function peso(int|float|null $amount): string
    {
        return $amount === null ? '—' : '₱'.number_format($amount);
    }

    public static function percent(?float $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    }

    public static function days(?float $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').($value === 1.0 ? ' day' : ' days');
    }

    /**
     * Today at the user's branches.
     *
     * @return list<array<string, mixed>>
     */
    public static function today(User $user): array
    {
        $scoped = fn () => Booking::query()->visibleTo($user);

        $dropOffs = $scoped()->countingTowardCap()->where('drop_off_on', today()->toDateString())->count();
        $inShop = $scoped()->whereIn('status', Booking::IN_SHOP)->count();
        $ready = $scoped()->where('status', 'ready')->count();
        $toDo = $scoped()->needsAction()->count();
        $sizes = $scoped()->needsSizeReview()->count();

        return [
            self::stat('dropOffs', 'Drop-offs today', $dropOffs, 'Booked for today', $dropOffs ? 'primary' : 'gray', Queue::getUrl()),
            self::stat('inShop', 'In the shop', $inShop, $ready ? "{$ready} ready" : 'Arrived or being worked on', $inShop ? 'info' : 'gray', BookingResource::getUrl('index', ['tab' => 'in_shop'])),
            self::stat('needsYou', 'Needs you', $toDo, $toDo ? ($sizes ? "{$sizes} to size, plus screenshots and home visits" : 'Screenshots to check, home visits to schedule') : 'All caught up', $toDo ? 'warning' : 'gray', BookingResource::getUrl('index', ['tab' => 'action'])),
        ];
    }

    /**
     * What is waiting right now, whatever period is chosen.
     *
     * @return list<array<string, mixed>>
     */
    public static function attention(Insights $insights): array
    {
        $count = $insights->attention();
        $stat = fn (string $key, string $label, ?int $value, string $help, string $url) => $value === null ? null : self::stat($key, $label, $value, $help, $value > 0 ? 'warning' : 'gray', $url);

        return array_values(array_filter([
            $stat('size', 'Units to size', $count['size'], 'Open bookings whose unit has no size or price yet.', BookingResource::getUrl('index', ['tab' => 'size'])),
            $stat('screenshots', 'Screenshots to check', $count['screenshots'], 'Payment screenshots waiting to be confirmed.', BookingResource::getUrl('index', ['tab' => 'action'])),
            $stat('home', 'Home-service requests', $count['home'], 'Requests nobody has scheduled yet.', BookingResource::getUrl('index', ['tab' => 'home'])),
            $stat('reviews', 'Reviews to approve', $count['reviews'], 'Customer reviews waiting for a decision.', ReviewResource::getUrl('index')),
            $stat('chats', 'Unread chats', $count['chats'], 'Messenger conversations with new messages.', Inbox::getUrl()),
            $stat('late', 'Late units', $count['late'], 'In the shop, past their ready date.', BookingResource::getUrl('index', ['tab' => 'in_shop'])),
        ]));
    }

    public static function salesDescription(Insights $insights): string
    {
        return $insights->sales()['units'] === 0 ? self::CHART_EMPTY : $insights->period->label();
    }

    /**
     * Money and jobs for the chosen period and branch.
     *
     * @return list<array<string, mixed>>
     */
    public static function sales(Insights $insights): array
    {
        $sales = $insights->sales();
        $none = $sales['units'] === 0;
        $noPoints = $sales['earnedEver'] === 0 && $sales['redeemed'] === 0;

        return array_values(array_filter([
            $none ? null : self::stat('sales', 'Sales', self::peso($sales['sales']), 'Total price of jobs that exited in this period, after discounts.'),
            $none && $sales['collected'] === 0 ? null : self::stat('collected', 'Collected', self::peso($sales['collected']), 'Fees marked paid, and amounts entered on manual transactions, by paid date. Refunds are not recorded.'),
            $none ? null : self::stat('average', 'Average ticket', self::peso($sales['average']), 'Sales divided by finished jobs that have a price.'),
            $none ? null : self::stat('units', 'Units done', $sales['units'], 'Bookings that reached Exited in this period.'),
            $sales['ended'] === 0 ? null : self::stat('lost', 'Lost bookings', self::percent($sales['lostRate']), "{$sales['lost']} of {$sales['ended']} bookings due in this period were cancelled, declined, expired or no-show."),
            $none ? null : self::stat('customers', 'New and repeat customers', "{$sales['newCustomers']} new, {$sales['repeatCustomers']} repeat", 'Customers with a finished job in this period. Repeat means they had an earlier finished job.'),
            $none ? null : self::stat('discounts', 'Discounts given', self::peso($sales['discounts']), 'Discounts on the jobs counted in Sales.'),
            $none ? null : self::stat('referrals', 'Referral codes used', $sales['redemptions'], $sales['points'].' points awarded to the code owners, from the jobs counted in Sales.'),
            $noPoints ? null : self::stat('redeemed', 'Points redeemed', $sales['redeemed'], 'Points customers spent in this period, on discounts or adjustments.'),
            $noPoints ? null : self::stat('outstanding', 'Points outstanding', $sales['outstanding'], 'All points earned by code owners, minus all redeemed, whatever the period.'),
        ]));
    }

    public static function chartHeading(Insights $insights): string
    {
        return $insights->period->days() > Insights::WEEKLY_AFTER_DAYS ? 'Sales per week' : 'Sales per day';
    }

    /**
     * Which services the finished jobs were for.
     *
     * @return list<array<string, mixed>>
     */
    public static function services(Insights $insights): array
    {
        $data = $insights->services();
        $services = collect($data['services']);
        $rows = fn ($list) => $list->take(8)->map(fn (array $row) => [$row['name'], (string) $row['units'], self::peso($row['sales'])])->values()->all();

        return [
            [
                'title' => 'Top services by units',
                'help' => 'Times each service was on a finished job in this period.',
                'columns' => ['Service', 'Units', 'Sales'],
                'rows' => $rows($services->sortBy([['units', 'desc'], ['sales', 'desc']])),
                'empty' => self::CHART_EMPTY,
            ],
            [
                'title' => 'Top services by sales',
                'help' => 'Price of each service on finished jobs, before any discount on the booking.',
                'columns' => ['Service', 'Units', 'Sales'],
                'rows' => $rows($services->sortBy([['sales', 'desc'], ['units', 'desc']])),
                'empty' => self::CHART_EMPTY,
            ],
            [
                'title' => 'Sales by category',
                'help' => 'The same prices, grouped by the category each service was in when booked.',
                'columns' => ['Category', 'Units', 'Sales'],
                'rows' => collect($data['categories'])->map(fn (array $row) => [$row['name'], (string) $row['units'], self::peso($row['sales'])])->all(),
                'empty' => self::CHART_EMPTY,
                'wide' => true,
            ],
        ];
    }

    /**
     * Branches side by side.
     *
     * @return list<array<string, mixed>>
     */
    public static function branches(Insights $insights): array
    {
        return [[
            'title' => 'Branch comparison',
            'help' => 'Every branch you manage, for the chosen period. Units and sales count jobs that exited. No-show rate is no-shows among bookings due in the period that finished or fell through. Days are from arriving to ready and to exit (hand-entered jobs left out).',
            'columns' => ['Branch', 'Units done', 'Sales', 'Average ticket', 'No-show rate', 'Days to ready', 'Days to exit'],
            'rows' => collect($insights->branchTable())->map(fn (array $row) => [
                $row['name'],
                (string) $row['units'],
                self::peso($row['sales']),
                self::peso($row['average']),
                self::percent($row['noShowRate']),
                self::days($row['toReady']),
                self::days($row['toExit']),
            ])->all(),
            'empty' => 'No branches to compare.',
            'wide' => true,
        ]];
    }

    public static function operationsDescription(Insights $insights): string
    {
        return 'Units in the shop and the next 14 days are as of now. Turnaround is for '.$insights->period->label().'.';
    }

    /**
     * The shop floor.
     *
     * @return list<array<string, mixed>>
     */
    public static function operations(Insights $insights): array
    {
        $stages = $insights->stages();
        $turnaround = $insights->turnaround();
        $utilization = $insights->utilization();
        $many = $insights->branches()->count() > 1;

        return [
            [
                'type' => 'stats',
                'title' => 'Units by stage',
                'help' => 'Bookings at each step right now. Booked means confirmed and not arrived yet, on any date.',
                'items' => collect($stages)->map(fn (int $count, string $label) => [
                    'label' => $label,
                    'value' => (string) $count,
                    'help' => '',
                    'url' => BookingResource::getUrl('index', ['tab' => $label === 'Booked' ? 'upcoming' : 'in_shop']),
                ])->values()->all(),
                'wide' => true,
            ],
            [
                'type' => 'stats',
                'title' => 'Turnaround',
                'help' => 'Average days from arriving at the shop, for jobs that exited in this period. Jobs missing a time, and ones typed in by hand, are left out.',
                'items' => [
                    ['label' => 'To ready', 'value' => self::days($turnaround['toReady']), 'help' => 'Arrived to ready'],
                    ['label' => 'To exit', 'value' => self::days($turnaround['toExit']), 'help' => 'Arrived to exited'],
                ],
            ],
            [
                'title' => 'Capacity, next 14 days',
                'help' => 'Booked units against the daily cap on open days. Closed days are left out; days marked fully booked count as full.',
                'columns' => ['Branch', 'Used', 'Booked units', 'Open days', 'Marked full'],
                'rows' => collect($utilization['branches'])->map(fn (array $row) => [
                    $row['name'],
                    $row['percent'] === null ? 'No open days' : $row['percent'].'%',
                    (string) $row['booked'],
                    (string) $row['openDays'],
                    (string) $row['fullDays'],
                ])->all(),
                'empty' => 'No branch with a daily cap and open days.',
            ],
            [
                'title' => 'Heaviest upcoming days',
                'help' => 'The fullest open days in the next 14 days.',
                'columns' => $many ? ['Day', 'Branch', 'Booked'] : ['Day', 'Booked'],
                'rows' => collect($utilization['heaviest'])->map(fn (array $day) => array_values(array_filter([
                    CarbonImmutable::parse($day['date'])->format('D, M j'),
                    $many ? $day['branch'] : null,
                    "{$day['booked']} of {$day['cap']}".($day['marked'] ? ', marked full' : ''),
                ], fn ($cell) => $cell !== null)))->all(),
                'empty' => 'No bookings in the next 14 days.',
            ],
        ];
    }

    /**
     * Customers, warranties and reviews.
     *
     * @return list<array<string, mixed>>
     */
    public static function customers(Insights $insights): array
    {
        $data = $insights->customers();
        $items = array_values(array_filter([
            $data['new'] === null ? null : ['label' => 'New customers', 'value' => (string) $data['new'], 'help' => 'Added in this period.', 'url' => CustomerResource::getUrl('index')],
            $data['total'] === null ? null : ['label' => 'All customers', 'value' => (string) $data['total'], 'help' => 'Everyone on your list, whatever the period.'],
            $data['warranty'] === null ? null : ['label' => 'Warranties ending soon', 'value' => (string) $data['warranty'], 'help' => 'Finished jobs with a service whose warranty ends in the next 30 days.', 'url' => Warranty::getUrl()],
            $data['reviews'] === null ? null : ['label' => 'Review rating', 'value' => $data['rating'] === null ? 'No reviews yet' : $data['rating'].' out of 5', 'help' => $data['reviews'].' approved '.($data['reviews'] === 1 ? 'review' : 'reviews').', all time.', 'url' => ReviewResource::getUrl('index')],
        ]));

        $blocks = [];

        if ($items !== []) {
            $blocks[] = ['type' => 'stats', 'title' => 'At a glance', 'help' => null, 'items' => $items, 'wide' => true];
        }

        if ($data['top'] !== null) {
            $blocks[] = [
                'title' => 'Top customers by spend',
                'help' => 'Customers with the most sales on finished jobs in this period.',
                'columns' => ['Customer', 'Units', 'Spent'],
                'rows' => array_map(fn (array $row) => [$row['name'], (string) $row['units'], self::peso($row['spend'])], $data['top']),
                'empty' => self::CHART_EMPTY,
                'wide' => true,
            ];
        }

        if ($data['referrers'] !== null) {
            $blocks[] = [
                'title' => 'Top referrers',
                'help' => 'Customers whose referral code was used on finished jobs in this period, and the points those jobs awarded them.',
                'columns' => ['Code owner', 'Times used', 'Points awarded'],
                'rows' => array_map(fn (array $row) => [$row['name'], (string) $row['used'], (string) $row['points']], $data['referrers']),
                'empty' => 'No referral codes were used on finished jobs in this period.',
                'wide' => true,
            ];
        }

        return $blocks;
    }

    /**
     * Who is doing the work.
     *
     * @return list<array<string, mixed>>
     */
    public static function staff(Insights $insights): array
    {
        return [[
            'title' => 'Work by person',
            'help' => 'Jobs and sales are finished jobs for the customers a person is the sales rep of. Referrals are those jobs where the customer used another customer’s referral code. Moves are the status changes recorded under their name.',
            'columns' => ['Person', 'Role', 'Customers\' jobs', 'Sales', 'Referrals', 'Moves'],
            'rows' => collect($insights->staff())->map(fn (array $row) => [$row['name'], $row['role'], (string) $row['jobs'], self::peso($row['sales']), (string) $row['referrals'], (string) $row['moves']])->all(),
            'empty' => 'No staff activity in this period.',
            'wide' => true,
        ]];
    }

    /**
     * @return array{key: string, label: string, value: int|string, help: string, tone: string|null, url: string|null}
     */
    private static function stat(string $key, string $label, int|string $value, string $help, ?string $tone = null, ?string $url = null): array
    {
        return compact('key', 'label', 'value', 'help', 'tone', 'url');
    }
}
