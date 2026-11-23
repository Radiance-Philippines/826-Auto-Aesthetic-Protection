<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Panels;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Filament's sign-in check, plus the panel split: someone who works in
 * another panel is sent to the same page there (or that panel's home)
 * instead of seeing a 403, and so is a page opened in a panel it does not
 * belong to (PanelPages::shown), even for a super admin. Anyone without a
 * panel still gets the 403.
 */
class AuthenticatePanel extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $user = Filament::auth()->user();
        $panel = Filament::getCurrentOrDefaultPanel();

        if (
            $user instanceof User
            && $user->canAccessAnyPanel()
            && $request->isMethod('GET')
            && ! $request->expectsJson()
            && ! $request->hasHeader('X-Livewire')
        ) {
            $placed = Panels::placedClass($request->route());

            if (! $user->canAccessPanel($panel)) {
                throw new HttpResponseException(redirect(Panels::equivalentUrl($user, $request)));
            }

            if ($placed !== null && ! Panels::shows($placed, $panel->getId())) {
                // A page of another panel: the same page there, or nowhere for this person
                $url = Panels::elsewhereUrl($user, $request);

                abort_if($url === null, 403);

                throw new HttpResponseException(redirect($url));
            }
        }

        parent::authenticate($request, $guards);
    }
}
