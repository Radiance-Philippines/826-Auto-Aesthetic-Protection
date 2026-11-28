<?php

namespace App\Http\Middleware;

use App\Models\SecuritySetting;
use App\Models\User;
use App\Support\Impersonation;
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
        return self::due($request) ? parent::handle($request, $next) : $next($request);
    }

    /**
     * Whether this signed-in person still has two-factor sign-in to set up
     * before the admin opens (the panels here, /console in OpenConsolePanel).
     */
    public static function due(Request $request): bool
    {
        // A super admin viewing as someone is already signed in strongly; the target's 2FA is not theirs to set up
        if (! SecuritySetting::current()->require_two_factor || Impersonation::active()) {
            return false;
        }

        if (SecuritySetting::passkeysAllowed() && $request->session()->get(self::VIA_PASSKEY) === true) {
            return false;
        }

        $user = $request->user();

        return $user instanceof User && ! $user->hasTwoFactor();
    }
}
