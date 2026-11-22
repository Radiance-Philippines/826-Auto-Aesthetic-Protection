<?php

namespace App\Filament\Widgets;

use App\Support\Access;
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
        return [[
            'title' => 'Work by person',
            'help' => 'Jobs and sales are finished jobs for the customers a person is the sales rep of. Moves are the status changes recorded under their name.',
            'columns' => ['Person', 'Role', 'Customers\' jobs', 'Sales', 'Moves'],
            'rows' => collect($insights->staff())->map(fn (array $row) => [$row['name'], $row['role'], (string) $row['jobs'], $this->peso($row['sales']), (string) $row['moves']])->all(),
            'empty' => 'No staff activity in this period.',
            'wide' => true,
        ]];
    }
}
