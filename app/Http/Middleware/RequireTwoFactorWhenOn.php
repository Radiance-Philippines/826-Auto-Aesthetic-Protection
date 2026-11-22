<?php

namespace App\Http\Middleware;

use App\Models\SecuritySetting;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Filament decides at route-registration time whether two-factor is
 * required, which a cached route list would freeze. So the panel always
 * asks, and this checks the setting (Settings → Security) on each request.
 * A passkey counts as strong sign-in: someone who has one is not sent to
 * set up an authenticator (while passkey sign-in is allowed).
 */
class RequireTwoFactorWhenOn extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        $setting = SecuritySetting::current();

        if (! $setting->require_two_factor) {
            return $next($request);
        }

        if (SecuritySetting::passkeysAllowed() && Filament::auth()->user()?->hasPasskeys()) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
