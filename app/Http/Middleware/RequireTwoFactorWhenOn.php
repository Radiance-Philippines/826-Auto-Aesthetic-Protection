<?php

namespace App\Http\Middleware;

use App\Models\SecuritySetting;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Illuminate\Http\Request;

/**
 * Filament decides at route-registration time whether two-factor is
 * required, which a cached route list would freeze. So the panel always
 * asks, and this checks the setting (Settings → Security) on each request.
 * A passkey counts as strong sign-in: a session started with one is not
 * sent to set up an authenticator (while passkey sign-in is allowed). Merely
 * owning a passkey does not count: a password sign-in still needs 2FA.
 */
class RequireTwoFactorWhenOn extends EnsureMultiFactorAuthenticationIsEnabled
{
    /** Session flag set when this sign-in was done with a passkey */
    public const VIA_PASSKEY = 'auth.via_passkey';

    public function handle(Request $request, Closure $next): mixed
    {
        $setting = SecuritySetting::current();

        if (! $setting->require_two_factor) {
            return $next($request);
        }

        if (SecuritySetting::passkeysAllowed() && $request->session()->get(self::VIA_PASSKEY) === true) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
