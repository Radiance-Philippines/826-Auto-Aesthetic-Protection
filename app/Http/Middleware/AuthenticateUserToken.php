<?php

namespace App\Http\Middleware;

use App\Models\SecuritySetting;
use App\Models\UserToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff app's bearer token (`Authorization: Bearer …`) signs the team
 * member in on the "web" guard for this request, so the admin's own
 * permission checks (areas, branch scopes, policies, history "who") work
 * unchanged. A missing, unknown, revoked or expired token is a 401, and so
 * is an account that must now have two-factor sign-in but has none (it
 * signs in again and is told to set it up on the website).
 */
class AuthenticateUserToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain !== null && $plain !== '' ? UserToken::findLive($plain) : null;

        if (! $token?->user || ! $this->mayStayIn($token)) {
            return response()->json(['message' => 'Sign in to continue.', 'signIn' => true], 401);
        }

        Auth::guard('web')->setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('user_token', $token);

        $token->touchUsed();

        return $next($request);
    }

    private function mayStayIn(UserToken $token): bool
    {
        return ! SecuritySetting::current()->require_two_factor || filled($token->user->app_authentication_secret);
    }
}
