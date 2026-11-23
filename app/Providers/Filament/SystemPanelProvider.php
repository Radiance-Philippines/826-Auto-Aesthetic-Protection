<?php

namespace App\Providers\Filament;

use App\Filament\Pages\SystemHealth;
use App\Support\Panels;
use Filament\Panel;

/**
 * IT backoffice and Radiance (super admins only): app releases and the
 * connections to Meta, Google and email, and the security switches, with a
 * small page of what is switched on as its home.
 */
class SystemPanelProvider extends BasePanelProvider
{
    protected function panelId(): string
    {
        return Panels::SYSTEM;
    }

    protected function brandName(): string
    {
        return '826 System';
    }

    protected function configure(Panel $panel): Panel
    {
        return $panel
            ->pages([SystemHealth::class, ...PanelPages::workPages(), ...PanelPages::settingsPages()])
            ->resources([...PanelPages::work(), ...PanelPages::shopAndSite(), ...PanelPages::systemResources()]);
    }
}
