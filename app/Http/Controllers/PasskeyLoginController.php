<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireTwoFactorWhenOn;
use App\Models\SecurityEvent;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Support\Panels;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Support\Config;

/**
 * Sign in to the admin with a passkey instead of a password: no email needed
 * (the passkey says who it is). The browser proves it with a signature over
 * a one-time challenge and the user verification our options require, so
 * this counts as strong sign-in and skips the 2FA code. The cryptography is
 * spatie/laravel-passkeys' (web-auth/webauthn-lib); this controller only
 * decides what happens after it passes: the person lands in their own panel.
 */
class PasskeyLoginController extends Controller
{
    public function options(): JsonResponse
    {
        abort_unless(SecuritySetting::passkeysAllowed(), 404);

        $action = Config::getAction('generate_passkey_authentication_options', GeneratePasskeyAuthenticationOptionsAction::class);

        return response()->json(json_decode($action->execute(), true));
    }

    public function login(Request $request): JsonResponse
    {
        abort_unless(SecuritySetting::passkeysAllowed(), 404);

        $request->validate([
            'credential' => ['required', 'string', 'json', 'max:20000'],
            'remember' => ['sometimes', 'boolean'],
            // Asked from the React admin's sign-in page: home is the console
            'console' => ['sometimes', 'boolean'],
        ]);

        $home = fn (User $user): string => $request->boolean('console') ? route('console.home') : Panels::homeUrl($user);

        // Already signed in (the autofill and the button can both answer): nothing more to do
        if (Filament::auth()->user() instanceof User) {
            return response()->json(['redirect' => $home(Filament::auth()->user())]);
        }

        $failed = response()->json(['message' => 'That passkey could not sign you in. Try again, or use your password.'], 422);

        $options = Session::pull('passkey-authentication-options');

        if (blank($options)) {
            return $failed;
        }

        $passkey = Config::getAction('find_passkey', FindPasskeyToAuthenticateAction::class)
            ->execute($request->input('credential'), $options);

        $user = $passkey?->authenticatable;

        if (! $user instanceof User || ! $user->canAccessAnyPanel()) {
            return $failed;
        }

        Filament::auth()->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        // Only this session counts as strong sign-in; owning a passkey is not enough (a password login by the same person is not)
        $request->session()->put(RequireTwoFactorWhenOn::VIA_PASSKEY, true);

        SecurityEvent::record(SecurityEvent::PASSKEY_SIGN_IN, $user, $passkey->name);

        return response()->json(['redirect' => Panels::intendedUrl($home($user))]);
    }
}
