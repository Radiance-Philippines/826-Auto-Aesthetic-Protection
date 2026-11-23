<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

/**
 * The customer app calls /api/* with a bearer token and no cookies:
 * those requests get no session (so no throwaway session row per call).
 */
class StartSessionUnlessBearer extends StartSession
{
    public function handle($request, Closure $next)
    {
        if (! self::isTokenCall($request)) {
            return parent::handle($request, $next);
        }

        // A store that is never started or saved: the middleware after this one may still ask for it
        $request->setLaravelSession(app('session')->driver('array'));

        return $next($request);
    }

    public static function isTokenCall(Request $request): bool
    {
        return $request->bearerToken() !== null && $request->is('api/*');
    }
}
