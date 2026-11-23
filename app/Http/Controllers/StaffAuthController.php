<?php

namespace App\Http\Controllers;

use App\Models\AppRelease;
use App\Models\SecurityEvent;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Models\UserToken;
use App\Support\Staff\StaffViews;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The staff app's sign-in (the admin's email and password, then the
 * authenticator code when the account has one or the owner requires it),
 * its bearer tokens and the signed-in person. Codes are checked with
 * Filament's own AppAuthentication provider, recovery codes included.
 * See routes/api.php.
 */
class StaffAuthController extends StaffController
{
    /** A 2FA challenge lives this long and is used once. */
    private const CHALLENGE_MINUTES = 5;

    /** Wrong codes allowed on one challenge. */
    private const CODE_TRIES = 5;

    /** Wrong passwords allowed per email address per minute. */
    private const PASSWORD_TRIES = 5;

    /**
     * POST /api/staff/sign-in {email, password, device_name}
     */
    public function signIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $limiter = 'staff-email|'.sha1(Str::lower($data['email']));

        if (RateLimiter::tooManyAttempts($limiter, self::PASSWORD_TRIES)) {
            return response()->json(['message' => 'Too many tries for that email. Wait a minute and try again.'], 429);
        }

        $user = User::query()->where('email', $data['email'])->first();

        // Always one password check, so a missing account takes as long as a wrong password
        $matches = Hash::check($data['password'], $user?->password ?? Hash::make(Str::random(16)));

        if (! $user || ! $matches || $user->isPendingInvite() || ! $user->canAccessAnyPanel()) {
            RateLimiter::hit($limiter, 60);

            if ($user) {
                SecurityEvent::record(SecurityEvent::STAFF_APP_SIGN_IN_FAILED, $user, 'Wrong password');
            }

            throw ValidationException::withMessages(['email' => 'Those details don’t match. Check your email and password.']);
        }

        RateLimiter::clear($limiter);

        if (filled($user->app_authentication_secret)) {
            $challenge = Str::random(40);
            Cache::put($this->challengeKey($challenge), ['user' => $user->getKey(), 'device' => $data['device_name'] ?? null, 'tries' => 0], now()->addMinutes(self::CHALLENGE_MINUTES));

            return response()->json(['twoFactor' => ['challenge' => $challenge, 'expiresInSeconds' => self::CHALLENGE_MINUTES * 60]]);
        }

        if ($user->has_email_authentication) {
            return response()->json(['message' => 'Your account signs in with an emailed code, which the team app can’t use yet. On the website, open your profile and set up an authenticator app, then sign in here again.'], 403);
        }

        if (SecuritySetting::current()->require_two_factor) {
            return response()->json(['message' => 'Two-factor sign-in is required for the team. On the website, open your profile and set up an authenticator app first, then sign in here again.'], 403);
        }

        return $this->signedIn($user, $data['device_name'] ?? null);
    }

    /**
     * POST /api/staff/sign-in/two-factor {challenge, code}: a 6-digit code
     * from the authenticator app, or a recovery code (which is used up).
     */
    public function twoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:100'],
        ]);

        $key = $this->challengeKey($data['challenge']);
        $challenge = Cache::get($key);
        $user = $challenge ? User::query()->find($challenge['user']) : null;

        if (! $user || blank($user->app_authentication_secret)) {
            throw ValidationException::withMessages(['code' => 'That sign-in took too long. Enter your email and password again.']);
        }

        if (! $this->codeIsValid($user, trim($data['code']))) {
            $challenge['tries']++;
            SecurityEvent::record(SecurityEvent::STAFF_APP_SIGN_IN_FAILED, $user, 'Wrong two-factor code');

            if ($challenge['tries'] >= self::CODE_TRIES) {
                Cache::forget($key);

                return response()->json(['message' => 'Too many wrong codes. Enter your email and password again.', 'restart' => true], 429);
            }

            Cache::put($key, $challenge, now()->addMinutes(self::CHALLENGE_MINUTES));

            throw ValidationException::withMessages(['code' => 'That code isn’t right. Check the app and try again.']);
        }

        Cache::forget($key);

        return $this->signedIn($user, $challenge['device']);
    }

    /**
     * POST /api/staff/sign-out: this device's token stops working.
     */
    public function signOut(Request $request): JsonResponse
    {
        $request->attributes->get('user_token')?->delete();
        SecurityEvent::record(SecurityEvent::STAFF_APP_SIGN_OUT, $this->user());

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/staff/me
     */
    public function me(): JsonResponse
    {
        return response()->json(StaffViews::me($this->user()))->header('Cache-Control', 'no-store');
    }

    /**
     * GET /api/staff/latest: the newest published 826 Team build, or
     * versionCode 0 when there is none yet.
     */
    public function latest(): JsonResponse
    {
        return app(AppController::class)->latest(AppRelease::STAFF);
    }

    private function codeIsValid(User $user, string $code): bool
    {
        $provider = AppAuthentication::make();
        $digits = preg_replace('/\s+/', '', $code);

        if (preg_match('/^\d{6}$/', $digits)) {
            return $provider->verifyCode($digits, $user->getAppAuthenticationSecret(), shouldPreventCodeReuse: true);
        }

        return filled($user->app_authentication_recovery_codes) && $provider->verifyRecoveryCode($code, $user);
    }

    private function challengeKey(string $challenge): string
    {
        return 'staff-2fa:'.hash('sha256', $challenge);
    }

    private function signedIn(User $user, ?string $device): JsonResponse
    {
        [$plain, $token] = UserToken::issue($user, $device);
        SecurityEvent::record(SecurityEvent::STAFF_APP_SIGN_IN, $user, $token->name);

        return response()->json(['token' => $plain, 'expiresAt' => $token->expires_at->toIso8601String(), 'user' => StaffViews::me($user)]);
    }
}
