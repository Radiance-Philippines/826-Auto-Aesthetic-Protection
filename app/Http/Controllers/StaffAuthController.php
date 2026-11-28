<?php

namespace App\Http\Controllers;

use App\Models\AppRelease;
use App\Models\SecurityEvent;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Models\UserToken;
use App\Support\Staff\StaffViews;
use App\Support\Staff\TeamApps;
use App\Support\TwoFactor;
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
 * its bearer tokens and the signed-in person. Codes are checked by
 * App\Support\TwoFactor, recovery codes included.
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

        $app = TeamApps::fromRequest($request);
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
            Cache::put($this->challengeKey($challenge), ['user' => $user->getKey(), 'device' => $data['device_name'] ?? null, 'app' => $app, 'tries' => 0], now()->addMinutes(self::CHALLENGE_MINUTES));

            return response()->json(['twoFactor' => ['challenge' => $challenge, 'expiresInSeconds' => self::CHALLENGE_MINUTES * 60]]);
        }

        if ($denied = $this->deniedApp($user, $app)) {
            return $denied;
        }

        if ($user->has_email_authentication) {
            return response()->json(['message' => 'Your account signs in with an emailed code, which the team app can’t use yet. On the website, open your profile and set up an authenticator app, then sign in here again.'], 403);
        }

        if (SecuritySetting::current()->require_two_factor) {
            return response()->json(['message' => 'Two-factor sign-in is required for the team. On the website, open your profile and set up an authenticator app first, then sign in here again.'], 403);
        }

        return $this->signedIn($user, $data['device_name'] ?? null, $app);
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

        if (TwoFactor::dayLimitReached($user)) {
            return response()->json(['message' => TwoFactor::DAY_LIMIT_MESSAGE, 'restart' => true], 429);
        }

        if (! TwoFactor::passes($user, trim($data['code']))) {
            TwoFactor::countWrongCode($user);
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

        // The password and the code are right: only now say the app is not for this account
        return $this->deniedApp($user, $challenge['app'] ?? null) ?? $this->signedIn($user, $challenge['device'], $challenge['app'] ?? null);
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
        return response()->json(StaffViews::me($this->user(), $this->teamApp(request())))->header('Cache-Control', 'no-store');
    }

    /**
     * GET /api/staff/latest: the newest published build of the app asking
     * (X-Team-App; none = the legacy 826 Team app), or versionCode 0 when
     * there is none yet.
     */
    public function latest(Request $request): JsonResponse
    {
        return app(AppController::class)->latest(TeamApps::fromRequest($request) ?? AppRelease::STAFF);
    }

    private function challengeKey(string $challenge): string
    {
        return 'staff-2fa:'.hash('sha256', $challenge);
    }

    /**
     * 403 when this account may not open the team app's panel, naming the app that fits it.
     */
    private function deniedApp(User $user, ?string $app): ?JsonResponse
    {
        if ($app === null || TeamApps::allows($user, $app)) {
            return null;
        }

        $suggest = TeamApps::suggestFor($user);

        return response()->json([
            'message' => 'This app is for '.TeamApps::label($app).'. Use '.TeamApps::label($suggest).'.',
            'code' => 'app_not_for_role',
            'suggestApp' => $suggest,
        ], 403);
    }

    private function signedIn(User $user, ?string $device, ?string $app): JsonResponse
    {
        [$plain, $token] = UserToken::issue($user, $device, $app);
        SecurityEvent::record(SecurityEvent::STAFF_APP_SIGN_IN, $user, $token->name);

        return response()->json(['token' => $plain, 'expiresAt' => $token->expires_at->toIso8601String(), 'user' => StaffViews::me($user, $app)]);
    }
}
