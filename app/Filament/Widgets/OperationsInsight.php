<?php

namespace App\Filament\Widgets;

use App\Support\Dashboard\Cards;
use App\Support\Dashboard\Insights;

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
        return Cards::operationsDescription($insights);
    }

    protected function blocks(Insights $insights): array
    {
        return Cards::operations($insights);
    }
}
