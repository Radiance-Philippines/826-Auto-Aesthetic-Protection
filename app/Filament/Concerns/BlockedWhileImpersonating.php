<?php

namespace App\Filament\Concerns;

use App\Support\Impersonation;

/**
 * For a page that must not be used while a super admin views as someone else
 * (settings, app releases, users, the profile). The middleware turns a normal
 * visit into a notice; this stops any Livewire call from a tab opened earlier.
 */
trait BlockedWhileImpersonating
{
    public function bootBlockedWhileImpersonating(): void
    {
        abort_if(Impersonation::active(), 403, 'Not available while viewing as someone else.');
    }
}
