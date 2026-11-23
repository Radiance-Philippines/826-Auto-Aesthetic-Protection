<?php

namespace App\Providers\Filament;

use App\Models\User;
use App\Support\Panels;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Route;

/**
 * The old /admin: no pages of its own, only the one sign-in page (and
 * password reset) that sends each person to their own panel, and the
 * redirects for old links (routes/web.php).
 */
class AdminPanelProvider extends BasePanelProvider
{
    protected function panelId(): string
    {
        return Panels::GATEWAY;
    }

    protected function brandName(): string
    {
        return '826 Auto Aesthetic & Protection';
    }

    protected function hasProfile(): bool
    {
        return false;
    }

    protected function configure(Panel $panel): Panel
    {
        return $panel->routes(fn () => Route::get('/', function () {
            $user = Filament::auth()->user();

            return $user instanceof User ? redirect(Panels::homeUrl($user)) : redirect(Filament::getLoginUrl());
        })->name('home'));
    }
}
