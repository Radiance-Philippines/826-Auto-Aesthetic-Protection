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
 */
class RequireTwoFactorWhenOn extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! SecuritySetting::current()->require_two_factor) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
