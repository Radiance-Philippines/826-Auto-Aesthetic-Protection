<?php

namespace App\Filament\Widgets;

use App\Models\Branch;
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
        return [[
            'title' => 'Branch comparison',
            'help' => 'Every branch you manage, for the chosen period. Units and sales count jobs that exited. No-show rate is no-shows among bookings due in the period that finished or fell through. Days are from arriving to ready and to exit (hand-entered jobs left out).',
            'columns' => ['Branch', 'Units done', 'Sales', 'Average ticket', 'No-show rate', 'Days to ready', 'Days to exit'],
            'rows' => collect($insights->branchTable())->map(fn (array $row) => [
                $row['name'],
                (string) $row['units'],
                $this->peso($row['sales']),
                $this->peso($row['average']),
                $this->percent($row['noShowRate']),
                $this->days($row['toReady']),
                $this->days($row['toExit']),
            ])->all(),
            'empty' => 'No branches to compare.',
            'wide' => true,
        ]];
    }
}
