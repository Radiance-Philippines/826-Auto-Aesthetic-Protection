<?php

namespace App\Http\Controllers;

use App\Models\SecurityEvent;
use App\Models\SecuritySetting;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Support\Config;

/**
 * Sign in to /admin with a passkey instead of a password: no email needed
 * (the passkey says who it is). The browser proves it with a signature over
 * a one-time challenge and the user verification our options require, so
 * this counts as strong sign-in and skips the 2FA code. The cryptography is
 * spatie/laravel-passkeys' (web-auth/webauthn-lib); this controller only
 * decides what happens after it passes.
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

        // Already signed in (the autofill and the button can both answer): nothing more to do
        if (Filament::auth()->check()) {
            return response()->json(['redirect' => Filament::getUrl()]);
        }

        $request->validate([
            'credential' => ['required', 'string', 'json', 'max:20000'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $failed = response()->json(['message' => 'That passkey could not sign you in. Try again, or use your password.'], 422);

        $options = Session::pull('passkey-authentication-options');

        if (blank($options)) {
            return $failed;
        }

        $passkey = Config::getAction('find_passkey', FindPasskeyToAuthenticateAction::class)
            ->execute($request->input('credential'), $options);

        $user = $passkey?->authenticatable;

        if (! $user || ($user instanceof FilamentUser && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel()))) {
            return $failed;
        }

        Filament::auth()->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        SecurityEvent::record(SecurityEvent::PASSKEY_SIGN_IN, $user, $passkey->name);

        return response()->json(['redirect' => redirect()->intended(Filament::getUrl())->getTargetUrl()]);
    }
}
