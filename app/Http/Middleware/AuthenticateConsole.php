<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

/**
 * Sign-in for the React admin is its own page (ConsoleAuthController), with
 * the same session as the panels. (A JSON call gets a plain 401.)
 */
class AuthenticateConsole extends Authenticate
{
    protected function redirectTo(Request $request): ?string
    {
        return route('console.login');
    }
}
