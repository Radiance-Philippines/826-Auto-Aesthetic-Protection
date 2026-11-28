<?php

namespace App\Filament\Widgets;

use App\Support\Dashboard\Cards;
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
        return Cards::services($insights);
    }
}
