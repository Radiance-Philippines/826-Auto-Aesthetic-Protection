<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Bookings\BookingResource;
use App\Support\Dashboard\Insights;
use Carbon\CarbonImmutable;

/**
 * The shop floor: where the units are, how long a job takes, and how full
 * the coming days are.
 */
class OperationsInsight extends InsightWidget
{
    protected static ?int $sort = 7;

    protected string $heading = 'Operations';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canAccessArea('queue.update');
    }

    protected function description(Insights $insights): ?string
    {
        return 'Units in the shop and the next 14 days are as of now. Turnaround is for '.$insights->period->label().'.';
    }

    protected function blocks(Insights $insights): array
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
                    'url' => BookingResource::getUrl('index', ['activeTab' => $label === 'Booked' ? 'upcoming' : 'in_shop']),
                ])->values()->all(),
                'wide' => true,
            ],
            [
                'type' => 'stats',
                'title' => 'Turnaround',
                'help' => 'Average days from arriving at the shop, for jobs that exited in this period. Jobs missing a time, and ones typed in by hand, are left out.',
                'items' => [
                    ['label' => 'To ready', 'value' => $this->days($turnaround['toReady']), 'help' => 'Arrived to ready'],
                    ['label' => 'To exit', 'value' => $this->days($turnaround['toExit']), 'help' => 'Arrived to exited'],
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
}
