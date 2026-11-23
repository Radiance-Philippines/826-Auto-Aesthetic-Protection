<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Warranty;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Support\Dashboard\Insights;

/**
 * Customers, warranties and reviews.
 */
class CustomersInsight extends InsightWidget
{
    protected static ?int $sort = 8;

    protected string $heading = 'Customers and reviews';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canAccessAnyArea('customers.view', 'warranty', 'reviews');
    }

    protected function blocks(Insights $insights): array
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
                'rows' => array_map(fn (array $row) => [$row['name'], (string) $row['units'], $this->peso($row['spend'])], $data['top']),
                'empty' => 'No finished jobs in this period.',
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
}
