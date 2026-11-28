<?php

namespace App\Filament\Widgets;

use App\Support\Dashboard\Cards;
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
        return Cards::customers($insights);
    }
}
