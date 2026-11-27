<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UnderConstruction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /unlock: the team's PIN opens the site for this browser (5 tries a minute).
 */
class SiteUnlockController extends Controller
{
    public const TRIES = 5;

    public function __invoke(Request $request): RedirectResponse
    {
        $pin = (string) config('app.site_pin');
        abort_if($pin === '', 404);

        $key = 'site-unlock:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::TRIES)) {
            return back()->withErrors(['pin' => 'Too many tries. Wait a minute, then try again.']);
        }

        $given = $request->input('pin');

        if (! is_string($given) || ! hash_equals($pin, $given)) {
            RateLimiter::hit($key);

            return back()->withErrors(['pin' => 'That PIN is not right.']);
        }

        RateLimiter::clear($key);
        $request->session()->put(UnderConstruction::SESSION, UnderConstruction::mark($pin));

        return back();
    }
}
