<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Support\Panels;
use Filament\Panel;

/**
 * Super admins: everything, so the owner never has to switch panels. The
 * master dashboard is here.
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
