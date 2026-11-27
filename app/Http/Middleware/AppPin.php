<?php

namespace App\Http\Middleware;

use App\Http\Controllers\SiteUnlockController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * While SITE_PIN is set, the customer app is for the team only, like the
 * website: it sends the PIN in X-Site-Pin. Wrong guesses share the website's
 * limit (a few a minute per address).
 */
class AppPin
{
    public const HEADER = 'X-Site-Pin';

    public function handle(Request $request, Closure $next): Response
    {
        return self::refusal($request) ?? $next($request);
    }

    /**
     * The 423 to answer with, or null when the request may go on.
     */
    public static function refusal(Request $request): ?Response
    {
        $pin = (string) config('app.site_pin');

        if ($pin === '') {
            return null;
        }

        $key = 'site-unlock:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, SiteUnlockController::TRIES)) {
            return self::locked('Too many tries. Wait a minute, then try again.');
        }

        $given = (string) $request->header(self::HEADER);

        if (hash_equals($pin, $given)) {
            return null;
        }

        if ($given === '') {
            return self::locked('826 is still being tested. Enter the team PIN.');
        }

        RateLimiter::hit($key);

        return self::locked('That PIN is not right.');
    }

    private static function locked(string $message): Response
    {
        return response()->json(['message' => $message, 'code' => 'pin_required'], 423);
    }
}
