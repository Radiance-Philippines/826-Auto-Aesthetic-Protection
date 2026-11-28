<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireTwoFactorWhenOn;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Support\AdminConsole;
use App\Support\Panels;
use App\Support\TwoFactor;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

/**
 * The React admin's own sign-in, forgot-password and reset pages, with the
 * rules of the panels' sign-in: the same people (any panel), the same
 * second factors from the same stored data (App\Support\TwoFactor; emailed
 * codes are still Filament's provider's) and the same password broker and
 * email. Passkeys stay in PasskeyLoginController.
 *
 * Between the password and the second factor nobody is signed in: the
 * session only remembers who is halfway.
 */
class ConsoleAuthController extends Controller
{
    private const PENDING = 'console.two_factor';

    private const PENDING_MINUTES = 10;

    public function showSignIn(Request $request): Response|RedirectResponse
    {
        if ($this->signedInUser()) {
            return redirect()->to(Panels::intendedUrl(route('console.home')));
        }

        $pending = $this->pendingUser($request);

        return $this->page('sign-in', 'Sign in', [
            'passkeys' => SecuritySetting::passkeysAllowed(),
            'status' => $request->session()->get('console.status'),
            'twoFactor' => $pending ? ['methods' => $this->methods($pending), 'sent' => null] : null,
        ]);
    }

    /**
     * POST /console/login {email, password, remember}
     */
    public function signIn(Request $request): JsonResponse
    {
        if ($this->signedInUser()) {
            return response()->json(['redirect' => Panels::intendedUrl(route('console.home'))]);
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:4096'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $request->session()->forget(self::PENDING);

        $credentials = ['email' => $data['email'], 'password' => $data['password']];
        $remember = $request->boolean('remember');
        $user = $this->checkPassword($credentials, $remember);
        $methods = $this->methods($user);

        if ($methods === []) {
            return $this->completeSignIn($request, $user, $remember);
        }

        // A new session id for the halfway state; nobody is signed in yet
        $request->session()->migrate(true);
        $request->session()->put(self::PENDING, [
            'id' => $user->getKey(),
            'password' => $this->fingerprint($user),
            'remember' => $remember,
            'expires' => now()->addMinutes(self::PENDING_MINUTES)->getTimestamp(),
        ]);

        // As the panels do: an emailed code goes out at once when it is the first factor offered
        $sent = $methods[0] === 'email' ? EmailAuthentication::make()->sendCode($user) : null;

        return response()->json(['twoFactor' => ['methods' => $methods, 'sent' => $sent]]);
    }

    /**
     * POST /console/login/two-factor {method: app|email, code}
     */
    public function twoFactor(Request $request): JsonResponse
    {
        $user = $this->pendingUserOrRestart($request);

        $data = $request->validate([
            'method' => ['required', Rule::in($this->methods($user))],
            'code' => ['required', 'string', 'max:100'],
        ]);

        // The panels' own per-account budget of 5 a minute, shared with them
        $limiter = MultiFactorChallenge::make();

        if ($limiter->isRateLimited($user)) {
            return response()->json(['message' => 'Too many tries. Wait a minute and try again.'], 429);
        }

        $limiter->hitRateLimiter($user);

        $passes = $data['method'] === 'email'
            ? EmailAuthentication::make()->verifyCode(preg_replace('/\s+/', '', $data['code']), $user)
            : TwoFactor::passes($user, $data['code']);

        if (! $passes) {
            throw ValidationException::withMessages(['code' => 'That code isn’t right. Check it and try again.']);
        }

        return $this->completeSignIn($request, $user, (bool) $request->session()->get(self::PENDING.'.remember'));
    }

    /**
     * POST /console/login/two-factor/email: send (or send again) the emailed code.
     */
    public function sendEmailCode(Request $request): JsonResponse
    {
        $user = $this->pendingUserOrRestart($request);

        abort_unless($user->has_email_authentication, 404);

        // False when two codes already went out this minute
        return response()->json(['sent' => EmailAuthentication::make()->sendCode($user)]);
    }

    public function cancelTwoFactor(Request $request): Response
    {
        $request->session()->forget(self::PENDING);

        return response()->noContent();
    }

    public function showForgotPassword(): Response|RedirectResponse
    {
        return $this->signedInUser() ? redirect()->route('console.home') : $this->page('forgot', 'Forgot password');
    }

    /**
     * POST /console/forgot-password {email}: the same answer whoever asks.
     */
    public function sendResetLink(Request $request): JsonResponse
    {
        $email = $request->validate(['email' => ['required', 'string', 'email', 'max:190']])['email'];

        // After the response, so a team address takes no longer to answer than an unknown one
        defer(fn () => Password::broker()->sendResetLink(['email' => $email], function (User $user, string $token): void {
            if (! $user->canAccessAnyPanel()) {
                return;
            }

            $notification = app(ResetPassword::class, ['token' => $token]);
            $notification->url = route('console.password.reset', ['token' => $token, 'email' => $user->email]);

            $user->notify($notification);
        }));

        return response()->json(['message' => 'If that address belongs to a team account, a link to choose a new password is on its way. Check your inbox and spam folder.']);
    }

    public function showResetPassword(Request $request): Response|RedirectResponse
    {
        if ($this->signedInUser()) {
            return redirect()->route('console.home');
        }

        $text = fn (string $key): string => is_string($request->query($key)) ? mb_substr($request->query($key), 0, 190) : '';

        return $this->page('reset', 'Choose a new password', ['email' => $text('email'), 'token' => $text('token')]);
    }

    /**
     * POST /console/reset-password {token, email, password, password_confirmation}
     */
    public function resetPassword(Request $request): JsonResponse
    {
        abort_if($this->signedInUser() !== null, 403, 'Sign out first, then open the link again.');

        $data = $request->validate([
            'token' => ['required', 'string', 'max:190'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string', 'confirmed', 'max:4096', PasswordRule::default()],
        ]);

        $changed = false;

        $status = Password::broker()->reset($data, function (User $user, string $password) use (&$changed): void {
            if (! $user->canAccessAnyPanel()) {
                return;
            }

            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            $changed = true;

            event(new PasswordReset($user));
        });

        // One message for a wrong link, an unknown address and an account without a panel
        if ($status !== Password::PASSWORD_RESET || ! $changed) {
            throw ValidationException::withMessages(['token' => 'This link is invalid or has expired. Ask for a new one.']);
        }

        $request->session()->forget(self::PENDING);
        $request->session()->flash('console.status', __($status));

        return response()->json(['redirect' => route('console.login')]);
    }

    public function signOut(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('console.login');
    }

    /**
     * The account these details open, checked as Filament's sign-in does:
     * one message and one length of time for every refusal.
     *
     * @param  array{email: string, password: string}  $credentials
     */
    private function checkPassword(array $credentials, bool $remember): User
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        return app(Timebox::class)->call(function (Timebox $timebox) use ($guard, $credentials, $remember): User {
            event(new Attempting('web', $credentials, $remember));

            $provider = $guard->getProvider();
            $user = $provider->retrieveByCredentials($credentials);

            if (! $user) {
                Hash::make($credentials['password']); // the same bcrypt cost as a real check
            }

            if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials) || ! $user->canAccessAnyPanel()) {
                event(new Failed('web', $user, $credentials));

                throw ValidationException::withMessages(['email' => __('auth.failed')]);
            }

            $provider->rehashPasswordIfRequired($user, $credentials);
            $timebox->returnEarly();

            return $user;
        }, (int) config('auth.timebox_duration', 200_000));
    }

    private function completeSignIn(Request $request, User $user, bool $remember): JsonResponse
    {
        // A password sign-in is not a passkey one, whatever this session did before
        $request->session()->forget([self::PENDING, RequireTwoFactorWhenOn::VIA_PASSKEY]);

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        return response()->json(['redirect' => Panels::intendedUrl(route('console.home'))]);
    }

    /**
     * @return list<'app'|'email'>
     */
    private function methods(User $user): array
    {
        return array_values(array_filter([
            filled($user->app_authentication_secret) ? 'app' : null,
            $user->has_email_authentication ? 'email' : null,
        ]));
    }

    /**
     * Who passed the password step, if that still holds: in time, same
     * password, still allowed in and still using a second factor.
     */
    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || $pending['expires'] < now()->getTimestamp()) {
            return null;
        }

        $user = User::query()->find($pending['id']);

        return $user && hash_equals($pending['password'], $this->fingerprint($user)) && $user->canAccessAnyPanel() && $this->methods($user) !== []
            ? $user
            : null;
    }

    private function pendingUserOrRestart(Request $request): User
    {
        // Someone already signed in has no second step to take
        $user = $this->signedInUser() ? null : $this->pendingUser($request);

        if (! $user) {
            $request->session()->forget(self::PENDING);

            throw ValidationException::withMessages(['restart' => 'That sign-in took too long. Enter your email and password again.']);
        }

        return $user;
    }

    private function fingerprint(User $user): string
    {
        return hash_hmac('sha256', (string) $user->getAuthPassword(), (string) config('app.key'));
    }

    private function signedInUser(): ?User
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The same HTML page as the console, with the sign-in bundle.
     *
     * @param  array<string, mixed>  $data
     */
    private function page(string $screen, string $title, array $data = []): Response
    {
        return response()->view('console', [
            'title' => "{$title} · 826",
            'entry' => 'resources/js/admin/auth/main.tsx',
            // "admin" is the panels' shared sign-in: where the no-JavaScript link goes
            'console' => ['base' => AdminConsole::basePath(), 'panel' => Panels::GATEWAY, 'logo' => null, 'auth' => ['screen' => $screen, ...$data]],
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
