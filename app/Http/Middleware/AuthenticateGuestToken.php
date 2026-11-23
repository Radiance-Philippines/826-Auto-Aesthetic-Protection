<?php

namespace App\Http\Middleware;

use App\Models\GuestToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer app's bearer token (`Authorization: Bearer …`) signs the
 * customer in on the "guest" guard for this request, so every customer
 * endpoint works the same with a token or with the site's session cookie.
 *
 * Alias `guest.token` reads a token when one is sent (a bad one is a 401)
 * and leaves cookie sign-in alone; `guest.token:required` also refuses a
 * request with no sign-in at all.
 */
class AuthenticateGuestToken
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $plain = $request->bearerToken();

        if ($plain !== null && $plain !== '') {
            $token = GuestToken::findLive($plain);

            if (! $token?->guest) {
                return $this->unauthenticated();
            }

            Auth::guard('guest')->setUser($token->guest);
            $request->attributes->set('guest_token', $token);

            // At most once a minute, so a busy screen is not a write per request
            if ($token->last_used_at === null || $token->last_used_at->lessThan(now()->subMinute())) {
                $token->forceFill(['last_used_at' => now()])->saveQuietly();
            }
        } elseif ($mode === 'required' && ! Auth::guard('guest')->check()) {
            return $this->unauthenticated();
        }

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json(['message' => 'Sign in to continue.', 'signIn' => true], 401);
    }
}
