<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * Signs a session out once its password has changed, as the panels do, and
 * sends it to the console's sign-in page.
 */
class AuthenticateConsoleSession extends AuthenticateSession
{
    protected function redirectTo(Request $request): ?string
    {
        return route('console.login');
    }
}
