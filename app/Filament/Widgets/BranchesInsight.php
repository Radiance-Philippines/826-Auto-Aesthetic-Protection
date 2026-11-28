<?php

namespace App\Filament\Widgets;

use App\Models\Branch;
use App\Support\Dashboard\Cards;
use App\Support\Dashboard\Insights;

/**
 * Branches side by side, for someone who works with more than one.
 */
class BranchesInsight extends InsightWidget
{
    protected static ?int $sort = 6;

    protected string $heading = 'Branches';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->canAccessArea('bookings.sales') && Branch::query()->visibleTo($user)->count() > 1;
    }

    protected function blocks(Insights $insights): array
    {
        return Cards::branches($insights);
    }
}
