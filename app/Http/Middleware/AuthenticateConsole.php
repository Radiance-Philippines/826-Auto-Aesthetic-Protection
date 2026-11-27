<?php

namespace App\Http\Middleware;

use App\Support\Panels;
use Filament\Facades\Filament;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

/**
 * Sign-in for the React admin is the admin's one sign-in page, with the same
 * session. (A JSON call gets a plain 401.)
 */
class AuthenticateConsole extends Authenticate
{
    protected function redirectTo(Request $request): ?string
    {
        return Filament::getPanel(Panels::GATEWAY)->getLoginUrl();
    }
}
