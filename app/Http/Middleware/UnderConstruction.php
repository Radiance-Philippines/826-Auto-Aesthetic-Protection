<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While SITE_PIN is set, the website's pages show "Under construction" until
 * the team enters the PIN. Only the pages: the admin, the apps' API, APK
 * downloads and webhooks are not behind it.
 */
class UnderConstruction
{
    public const SESSION = 'site_unlocked';

    public function handle(Request $request, Closure $next): Response
    {
        $pin = (string) config('app.site_pin');

        // The session keeps a hash of the PIN it was opened with, so a new PIN locks the site again
        if ($pin === '' || hash_equals(self::mark($pin), (string) $request->session()->get(self::SESSION))) {
            return $next($request);
        }

        return response()->view('under-construction');
    }

    public static function mark(string $pin): string
    {
        return hash('sha256', $pin);
    }
}
