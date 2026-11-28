<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesInsights;
use App\Support\Dashboard\Cards;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Money and jobs for the chosen period and branch. Each box says under
 * its number what it counts.
 */
class SalesOverview extends StatsOverviewWidget
{
    use UsesInsights;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 3;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Sales';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canAccessArea('bookings.sales');
    }

    protected function getColumns(): int|array|null
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getDescription(): ?string
    {
        return Cards::salesDescription($this->insights());
    }

    protected function getStats(): array
    {
        $icons = [
            'sales' => Heroicon::OutlinedBanknotes,
            'collected' => Heroicon::OutlinedWallet,
            'average' => Heroicon::OutlinedReceiptPercent,
            'units' => Heroicon::OutlinedCheckBadge,
            'lost' => Heroicon::OutlinedXCircle,
            'customers' => Heroicon::OutlinedUserGroup,
            'discounts' => Heroicon::OutlinedTag,
            'referrals' => Heroicon::OutlinedGift,
            'redeemed' => Heroicon::OutlinedArrowTrendingDown,
            'outstanding' => Heroicon::OutlinedScale,
        ];

        return array_map(fn (array $stat) => Stat::make($stat['label'], $stat['value'])
            ->description($stat['help'])
            ->descriptionIcon($icons[$stat['key']]), Cards::sales($this->insights()));
    }
}
