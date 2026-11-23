<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Support\Panels;
use Filament\Panel;

/**
 * Sales admins: bookings and their sales actions, customers, warranty,
 * reviews, the inbox and manual transactions, and a dashboard of the sales
 * numbers.
 */
class SalesPanelProvider extends BasePanelProvider
{
    protected function panelId(): string
    {
        return Panels::SALES;
    }

    protected function brandName(): string
    {
        return '826 Sales';
    }

    protected function configure(Panel $panel): Panel
    {
        return $panel
            ->pages([Dashboard::class, ...PanelPages::workPages(), ...PanelPages::settingsPages()])
            ->resources([...PanelPages::work(), ...PanelPages::shopAndSite(), ...PanelPages::systemResources()])
            ->widgets(PanelPages::salesWidgets());
    }
}
