<?php

namespace App\Filament\Widgets;

use App\Support\Dashboard\Insights;

/**
 * Which services the finished jobs were for.
 */
class ServicesInsight extends InsightWidget
{
    protected static ?int $sort = 5;

    protected string $heading = 'Services';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canAccessArea('bookings.sales');
    }

    protected function blocks(Insights $insights): array
    {
        $data = $insights->services();
        $services = collect($data['services']);
        $rows = fn ($list) => $list->take(8)->map(fn (array $row) => [$row['name'], (string) $row['units'], $this->peso($row['sales'])])->values()->all();
        $empty = 'No finished jobs in this period.';

        return [
            [
                'title' => 'Top services by units',
                'help' => 'Times each service was on a finished job in this period.',
                'columns' => ['Service', 'Units', 'Sales'],
                'rows' => $rows($services->sortBy([['units', 'desc'], ['sales', 'desc']])),
                'empty' => $empty,
            ],
            [
                'title' => 'Top services by sales',
                'help' => 'Price of each service on finished jobs, before any discount on the booking.',
                'columns' => ['Service', 'Units', 'Sales'],
                'rows' => $rows($services->sortBy([['sales', 'desc'], ['units', 'desc']])),
                'empty' => $empty,
            ],
            [
                'title' => 'Sales by category',
                'help' => 'The same prices, grouped by the category each service was in when booked.',
                'columns' => ['Category', 'Units', 'Sales'],
                'rows' => collect($data['categories'])->map(fn (array $row) => [$row['name'], (string) $row['units'], $this->peso($row['sales'])])->all(),
                'empty' => $empty,
                'wide' => true,
            ],
        ];
    }
}
