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

        // The React admin's banner goes back to its own Users screen
        $console = str_contains((string) url()->previous(), '/console/');

        return Impersonation::stop()
            ? redirect($console ? route('console.page', ['panel' => Panels::OWNER, 'path' => 'users']) : UserResource::getUrl('index', panel: Panels::OWNER))
            : redirect(Filament::getPanel(Panels::OWNER)->getLoginUrl());
    }
}
