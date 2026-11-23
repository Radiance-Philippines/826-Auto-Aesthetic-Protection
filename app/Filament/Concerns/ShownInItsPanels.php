<?php

namespace App\Filament\Concerns;

use App\Support\Panels;
use Filament\Facades\Filament;

/**
 * For a page or resource that lives in some of the admin's panels
 * (PanelPages::shown): its sidebar entry shows only there, and only to the
 * accounts whose area allows it. Opening it in another panel is redirected
 * (AuthenticatePanel), so this is navigation, not security.
 */
trait ShownInItsPanels
{
    public static function shouldRegisterNavigation(): bool
    {
        return Panels::shows(static::class, Filament::getCurrentOrDefaultPanel()->getId());
    }
}
