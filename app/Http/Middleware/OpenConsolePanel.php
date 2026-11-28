<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Panels;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens a /console panel the way Filament opens its own: this person must
 * have it, it becomes the current panel (so links, policies and badges read
 * as they do there) and Settings → Security's two-factor rule applies. Until
 * two-factor is set up, only the shell, the profile page and setting it up open.
 */
class OpenConsolePanel
{
    /** The JSON that stays open while two-factor sign-in is still to set up */
    private const SETUP_ROUTES = ['console.api.me', 'console.api.profile.show', 'console.api.profile.setup.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $panel = (string) $request->route('panel');
        $json = $request->is('console/api/*');

        abort_unless($user instanceof User && $user->canAccessAnyPanel(), 403);

        if (! Panels::canOpen($user, $panel)) {
            abort_if($json, 403, 'Your role doesn’t include this panel.');

            return redirect()->route('console.page', ['panel' => Panels::homeId($user), 'path' => $request->route('path')] + $request->query());
        }

        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();

        // Controllers take their own parameters; they read the panel from Filament
        $request->route()->forgetParameter('panel');

        $open = $request->routeIs(...self::SETUP_ROUTES) || ($request->routeIs('console.page') && $request->route('path') === 'profile');

        if (! $open && RequireTwoFactorWhenOn::due($request)) {
            $setUp = route('console.page', ['panel' => $panel, 'path' => 'profile']);

            return $json ? response()->json(['message' => 'Set up two-factor sign-in first.', 'redirect' => $setUp], 403) : redirect($setUp);
        }

        return $next($request);
    }
}
