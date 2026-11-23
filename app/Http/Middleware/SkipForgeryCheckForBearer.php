<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * A request that carries its own bearer token needs no CSRF token: a
 * forged cross-site request can't add an Authorization header (the browser
 * would ask first). Cookie-signed-in requests are checked as before.
 */
class SkipForgeryCheckForBearer extends PreventRequestForgery
{
    public function handle($request, Closure $next)
    {
        return StartSessionUnlessBearer::isTokenCall($request) ? $next($request) : parent::handle($request, $next);
    }
}
