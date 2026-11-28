<?php

namespace App\Filament\Widgets;

use App\Support\Access;
use App\Support\Dashboard\Cards;
use App\Support\Dashboard\Insights;

/**
 * Who is doing the work, for super admins and branch managers.
 */
class StaffInsight extends InsightWidget
{
    protected static ?int $sort = 9;

    protected string $heading = 'Staff';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isOwner() || $user->role === Access::BRANCH_MANAGER);
    }

    protected function blocks(Insights $insights): array
    {
        return Cards::staff($insights);
    }
}
