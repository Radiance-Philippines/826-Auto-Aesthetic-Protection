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
 * instead of seeing a 403. Anyone without a panel still gets the 403.
 */
class AuthenticatePanel extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $user = Filament::auth()->user();

        if (
            $user instanceof User
            && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())
            && $user->canAccessAnyPanel()
            && $request->isMethod('GET')
            && ! $request->expectsJson()
            && ! $request->hasHeader('X-Livewire')
        ) {
            throw new HttpResponseException(redirect(Panels::equivalentUrl($user, $request)));
        }

        parent::authenticate($request, $guards);
    }
}
