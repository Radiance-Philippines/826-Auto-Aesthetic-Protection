<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Users\UserResource;
use App\Support\Impersonation;
use App\Support\Panels;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;

/**
 * "Stop viewing as": back to the real super admin, on the Users list.
 */
class ImpersonationController extends Controller
{
    public function stop(): RedirectResponse
    {
        if (! Impersonation::active()) {
            return redirect('/');
        }

        return Impersonation::stop()
            ? redirect(UserResource::getUrl('index', panel: Panels::OWNER))
            : redirect(Filament::getPanel(Panels::OWNER)->getLoginUrl());
    }
}
