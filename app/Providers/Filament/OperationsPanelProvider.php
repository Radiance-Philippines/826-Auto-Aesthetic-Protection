<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Queue;
use App\Support\Panels;
use Filament\Panel;
use Illuminate\Support\Facades\Route;

/**
 * Detailers and branch managers: the queue is the home page, with the
 * bookings, customers, warranty, closures and (for managers) the inbox,
 * reviews and sales actions their areas allow, and a dashboard of the
 * day-to-day numbers.
 */
class OperationsPanelProvider extends BasePanelProvider
{
    protected function panelId(): string
    {
        return Panels::OPERATIONS;
    }

    protected function brandName(): string
    {
        return '826 Operations';
    }

    protected function configure(Panel $panel): Panel
    {
        return $panel
            ->pages([Dashboard::class, ...PanelPages::workPages(), ...PanelPages::settingsPages()])
            ->resources([...PanelPages::work(), ...PanelPages::shopAndSite(), ...PanelPages::systemResources()])
            ->widgets(PanelPages::operationsWidgets())
            // The queue is the home page; the dashboard is its own page here
            ->authenticatedRoutes(fn (Panel $panel) => Route::get('/', function () use ($panel) {
                if (Queue::canAccess()) {
                    return redirect(Queue::getUrl(panel: $panel->getId()));
                }

                $first = $panel->getRedirectUrl();

                abort_if($first === url($panel->getPath()), 403);

                return redirect($first);
            })->name('home'));
    }
}
