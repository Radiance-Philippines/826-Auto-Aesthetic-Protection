<?php

namespace App\Filament\Widgets;

use App\Support\Dashboard\Cards;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Today at the signed-in team member's branches: drop-offs, cars in the
 * shop, and what needs them (screenshots, home-service requests and units
 * waiting for their size review).
 */
class BookingStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canAccessArea('bookings.view');
    }

    // Gentle on SQLite: refresh once a minute, not every 5 seconds
    protected ?string $pollingInterval = '60s';

    protected function getColumns(): int|array|null
    {
        return ['md' => 3];
    }

    protected function getStats(): array
    {
        $icons = ['dropOffs' => Heroicon::OutlinedCalendarDays, 'inShop' => Heroicon::OutlinedWrenchScrewdriver, 'needsYou' => Heroicon::OutlinedInboxArrowDown];

        return array_map(fn (array $stat) => Stat::make($stat['label'], $stat['value'])
            ->description($stat['help'])
            ->descriptionIcon($icons[$stat['key']])
            ->color($stat['tone'])
            ->url($stat['url']), Cards::today(auth()->user()));
    }
}
