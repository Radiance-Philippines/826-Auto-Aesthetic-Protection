<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesInsights;
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
        $insights = $this->insights();

        return $insights->sales()['units'] === 0 ? 'No finished jobs in this period.' : $insights->period->label();
    }

    protected function getStats(): array
    {
        $sales = $this->insights()->sales();
        $none = $sales['units'] === 0;

        $stats = [
            $none ? null : Stat::make('Sales', $this->peso($sales['sales']))
                ->description('Total price of jobs that exited in this period, after discounts.')
                ->descriptionIcon(Heroicon::OutlinedBanknotes),
            $none && $sales['collected'] === 0 ? null : Stat::make('Collected', $this->peso($sales['collected']))
                ->description('Fees marked paid, and amounts entered on manual transactions, by paid date. Refunds are not recorded.')
                ->descriptionIcon(Heroicon::OutlinedWallet),
            $none ? null : Stat::make('Average ticket', $this->peso($sales['average']))
                ->description('Sales divided by finished jobs that have a price.')
                ->descriptionIcon(Heroicon::OutlinedReceiptPercent),
            $none ? null : Stat::make('Units done', $sales['units'])
                ->description('Bookings that reached Exited in this period.')
                ->descriptionIcon(Heroicon::OutlinedCheckBadge),
            $sales['ended'] === 0 ? null : Stat::make('Lost bookings', $this->percent($sales['lostRate']))
                ->description("{$sales['lost']} of {$sales['ended']} bookings due in this period were cancelled, declined, expired or no-show.")
                ->descriptionIcon(Heroicon::OutlinedXCircle),
            $none ? null : Stat::make('New and repeat customers', "{$sales['newCustomers']} new, {$sales['repeatCustomers']} repeat")
                ->description('Customers with a finished job in this period. Repeat means they had an earlier finished job.')
                ->descriptionIcon(Heroicon::OutlinedUserGroup),
            $none ? null : Stat::make('Discounts given', $this->peso($sales['discounts']))
                ->description('Discounts on the jobs counted in Sales.')
                ->descriptionIcon(Heroicon::OutlinedTag),
            $none ? null : Stat::make('Referral codes used', $sales['redemptions'])
                ->description($sales['points'].' points awarded to the code owners, from the jobs counted in Sales.')
                ->descriptionIcon(Heroicon::OutlinedGift),
        ];

        return array_values(array_filter($stats));
    }
}
