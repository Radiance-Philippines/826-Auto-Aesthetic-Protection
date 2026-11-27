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
 * as they do there) and Settings → Security's two-factor rule applies.
 */
class OpenConsolePanel
{
    public function __construct(private readonly RequireTwoFactorWhenOn $twoFactor) {}

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

        $passed = false;
        $response = $this->twoFactor->handle($request, function (Request $request) use ($next, &$passed) {
            $passed = true;

            return $next($request);
        });

        if (! $passed && $json) {
            return response()->json(['message' => 'Set up two-factor sign-in first.', 'redirect' => $response->headers->get('Location')], 403);
        }

        return $response;
    }
}
