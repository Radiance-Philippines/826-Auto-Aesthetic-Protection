<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Support\Panels;
use Filament\Panel;

/**
 * The business owner (a super admin): the master dashboard, the shop and
 * site setup, referral, booking, payment and site settings, and the users.
 * The day-to-day work is in /sales and /operations, the connections in /system.
 */
class OwnerPanelProvider extends BasePanelProvider
{
    protected function panelId(): string
    {
        return Panels::OWNER;
    }

    protected function brandName(): string
    {
        return '826 Owner';
    }

    protected function configure(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->pages([Dashboard::class, ...PanelPages::workPages(), ...PanelPages::settingsPages()])
            ->resources([...PanelPages::work(), ...PanelPages::shopAndSite(), ...PanelPages::systemResources()])
            ->widgets(PanelPages::ownerWidgets());
    }
}
