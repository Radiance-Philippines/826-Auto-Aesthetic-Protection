<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireTwoFactorWhenOn;
use App\Models\SecurityEvent;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Support\Impersonation;
use App\Support\Passkeys;
use App\Support\TwoFactor;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelPasskeys\Models\Passkey;

/**
 * The signed-in person's own profile in the React admin, with the rules of
 * the panels' profile page: the current password to change the email or the
 * password and for the authenticator app, nothing while viewing as someone
 * else, and the same stored two-factor and passkey data. While two-factor
 * is required and not set up, only setting it up is open (OpenConsolePanel).
 */
class ConsoleProfileController extends Controller
{
    /** The authenticator secret being set up, until its first code proves the phone has it */
    private const SETUP = 'console.totp_setup';

    private const SETUP_MINUTES = 15;

    public function show(Request $request): JsonResponse
    {
        return $this->profile($request, $this->me($request));
    }

    /**
     * PATCH profile {name, email, password?, password_confirmation?, current_password?}
     */
    public function update(Request $request): JsonResponse
    {
        $user = $this->me($request);
        $changesSignIn = filled($request->input('password')) || $request->input('email') !== $user->email;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'password' => ['nullable', 'string', 'confirmed', 'max:4096', Password::default()],
            'current_password' => $changesSignIn ? ['required', 'string', 'current_password:web'] : ['nullable'],
        ]);

        $user->fill(['name' => $data['name'], 'email' => $data['email']]);

        if (filled($data['password'] ?? null)) {
            // Hashed by the model's cast; AuthenticateConsoleSession keeps this session signed in
            $user->password = $data['password'];
        }

        $user->save();

        return $this->profile($request, $user);
    }

    /**
     * POST profile/two-factor/app: a new secret and its QR code. Nothing is saved on the account yet.
     */
    public function startApp(Request $request): JsonResponse
    {
        $user = $this->me($request);
        abort_if(filled($user->app_authentication_secret), 409, 'The authenticator app is already on.');

        $secret = TwoFactor::newSecret();
        $request->session()->put(self::SETUP, [
            'user' => $user->getKey(),
            'secret' => Crypt::encryptString($secret),
            'expires' => now()->addMinutes(self::SETUP_MINUTES)->getTimestamp(),
        ]);

        return $this->noStore([
            'secret' => $secret,
            'qr' => TwoFactor::qrCode(strip_tags((string) Filament::getCurrentOrDefaultPanel()->getBrandName()), $user->email, $secret),
        ]);
    }

    /**
     * POST profile/two-factor/app/confirm {code, current_password}: turns it on and shows the recovery codes, once.
     */
    public function confirmApp(Request $request): JsonResponse
    {
        $user = $this->me($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'current_password' => ['required', 'string', 'current_password:web'],
        ]);

        $setup = $request->session()->get(self::SETUP);

        if (! is_array($setup) || $setup['user'] !== $user->getKey() || $setup['expires'] < now()->getTimestamp() || filled($user->app_authentication_secret)) {
            throw ValidationException::withMessages(['code' => 'That took too long. Close this and set it up again.']);
        }

        $secret = Crypt::decryptString($setup['secret']);

        if (! TwoFactor::codeMatches($secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code isn’t right. Check the app and try again.']);
        }

        $codes = TwoFactor::newRecoveryCodes();

        DB::transaction(function () use ($user, $secret, $codes): void {
            $user->saveAppAuthenticationSecret($secret);
            TwoFactor::saveRecoveryCodes($user, $codes);
        });

        $request->session()->forget(self::SETUP);

        return $this->profile($request, $user, ['recoveryCodes' => $codes]);
    }

    /**
     * DELETE profile/two-factor/app {current_password, code}: a code from the app, or a recovery code.
     */
    public function disableApp(Request $request): JsonResponse
    {
        $user = $this->me($request);
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'code' => ['required', 'string', 'max:100'],
        ]);

        if (! TwoFactor::passes($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code isn’t right. Check it and try again.']);
        }

        DB::transaction(function () use ($user): void {
            $user->saveAppAuthenticationSecret(null);
            TwoFactor::saveRecoveryCodes($user, null);
        });

        return $this->profile($request, $user);
    }

    /**
     * POST profile/two-factor/recovery-codes {current_password}: new ones; the old stop working.
     */
    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $this->me($request);
        $request->validate(['current_password' => ['required', 'string', 'current_password:web']]);
        abort_if(blank($user->app_authentication_secret), 409, 'The authenticator app is off.');

        $codes = TwoFactor::newRecoveryCodes();
        TwoFactor::saveRecoveryCodes($user, $codes);

        return $this->profile($request, $user, ['recoveryCodes' => $codes]);
    }

    /**
     * POST profile/two-factor/email/code: the code that proves the mailbox, to turn emailed codes on or off.
     */
    public function sendEmailCode(Request $request): JsonResponse
    {
        // False when two codes already went out this minute
        return $this->noStore(['sent' => EmailAuthentication::make()->sendCode($this->me($request))]);
    }

    public function enableEmail(Request $request): JsonResponse
    {
        return $this->toggleEmail($request, true);
    }

    public function disableEmail(Request $request): JsonResponse
    {
        return $this->toggleEmail($request, false);
    }

    public function passkeyOptions(Request $request): JsonResponse
    {
        $user = $this->me($request);
        abort_unless(SecuritySetting::passkeysAllowed(), 403, 'Passkey sign-in is turned off.');

        return $this->noStore(json_decode(Passkeys::registrationOptions($user), true));
    }

    /**
     * POST profile/passkeys {credential, name?}
     */
    public function addPasskey(Request $request): JsonResponse
    {
        $user = $this->me($request);
        abort_unless(SecuritySetting::passkeysAllowed(), 403, 'Passkey sign-in is turned off.');

        $data = $request->validate([
            'credential' => ['required', 'string', 'json', 'max:20000'],
            'name' => ['nullable', 'string', 'max:60'],
        ]);

        $result = Passkeys::store($user, $data['credential'], (string) ($data['name'] ?? ''));

        if (! $result['ok']) {
            throw ValidationException::withMessages(['credential' => $result['message']]);
        }

        return $this->profile($request, $user);
    }

    public function renamePasskey(Request $request, int $passkey): JsonResponse
    {
        $user = $this->me($request);
        $own = $this->ownPasskey($user, $passkey);
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $own->update(['name' => trim($data['name'])]);

        return $this->profile($request, $user);
    }

    public function removePasskey(Request $request, int $passkey): JsonResponse
    {
        $user = $this->me($request);

        Passkeys::remove($user, $this->ownPasskey($user, $passkey));

        return $this->profile($request, $user);
    }

    private function toggleEmail(Request $request, bool $on): JsonResponse
    {
        $user = $this->me($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        if (! EmailAuthentication::make()->verifyCode(preg_replace('/\s+/', '', $data['code']), $user)) {
            throw ValidationException::withMessages(['code' => 'That code isn’t right, or it is too old. Send a new one.']);
        }

        $user->toggleEmailAuthentication($on);

        return $this->profile($request, $user);
    }

    /**
     * Never someone else's: a super admin viewing as a person must not touch how that person signs in.
     */
    private function me(Request $request): User
    {
        abort_if(Impersonation::active(), 403, 'Not available while viewing as someone else.');

        return $request->user();
    }

    /**
     * Only ever one of this person's own passkeys, whatever id is sent.
     */
    private function ownPasskey(User $user, int $id): Passkey
    {
        return $user->passkeys()->whereKey($id)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function profile(Request $request, User $user, array $extra = []): JsonResponse
    {
        return $this->noStore([
            'name' => $user->name,
            'email' => $user->email,
            'setupRequired' => RequireTwoFactorWhenOn::due($request),
            'twoFactor' => [
                'app' => filled($user->app_authentication_secret),
                'recoveryCodes' => count($user->app_authentication_recovery_codes ?? []),
                'email' => (bool) $user->has_email_authentication,
            ],
            'passkeys' => [
                'allowed' => SecuritySetting::passkeysAllowed(),
                ...Passkeys::state($user),
                'items' => $user->passkeys()->oldest()->get()->map(fn (Passkey $passkey) => [
                    'id' => $passkey->id,
                    'name' => $passkey->name,
                    'added' => $passkey->created_at->timezone(config('app.timezone'))->format('M j, Y'),
                    'lastUsed' => $passkey->last_used_at?->diffForHumans(),
                ])->all(),
            ],
            'events' => SecurityEvent::query()->where('user_id', $user->getKey())->with('actor')->latest('id')->limit(15)->get()->map(fn (SecurityEvent $event) => [
                'label' => $event->label(),
                'note' => $event->note,
                'actor' => $event->actor?->name,
                'at' => $event->created_at->timezone(config('app.timezone'))->format('M j, g:i A'),
                'ip' => $event->ip,
            ])->all(),
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function noStore(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store');
    }
}
