<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\GuestToken;
use App\Models\SecurityEvent;
use App\Support\CustomerAccounts;
use App\Support\CustomerRules;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A customer's own account (email and password), next to Google. One set
 * of endpoints for two callers: the site (routes/web.php: session cookie and
 * CSRF token, answers with the guest) and the app (routes/api.php under
 * /api/app/auth: no session, answers with a bearer token). The caller is
 * known from the route name ("app.auth.*"). The rules are in
 * App\Support\CustomerAccounts; every answer that could say whether an
 * address has an account says the same thing either way.
 */
class CustomerAuthController extends Controller
{
    /** Wrong passwords allowed per email address and visitor before the lock. */
    private const FAILURES = 5;

    /** How long the lock lasts. */
    private const LOCK_SECONDS = 900;

    private const MATCH = 'Those details don’t match. Check your email and password.';

    /**
     * POST register {name, email, password, phone?, device_name?}
     */
    public function register(Request $request): JsonResponse
    {
        if (! CustomerAccounts::available()) {
            return response()->json([
                'message' => 'Account sign-up by email isn’t available yet. Use Google.',
                'code' => 'email_sign_up_unavailable',
            ], 503);
        }

        $email = CustomerAccounts::normalize((string) $request->input('email'));
        $request->merge(['email' => $email]);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[^<>]+$/u'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => CustomerRules::password($email),
            'phone' => ['sometimes', ...CustomerRules::phone()],
            'device_name' => ['nullable', 'string', 'max:120'],
        ], [
            'name.min' => 'Enter your name.',
            'name.required' => 'Enter your name.',
            'name.regex' => 'Use letters and numbers in your name.',
            'email.email' => 'Enter a valid email address.',
            'password.required' => 'Choose a password.',
        ]);

        $result = CustomerAccounts::register($data['name'], $email, $data['password'], filled($data['phone'] ?? null) ? $data['phone'] : null);

        if ($result['status'] === 'exists') {
            throw ValidationException::withMessages(['email' => 'That email already has an account. Sign in instead.']);
        }

        if ($result['status'] === 'verified') {
            return $this->signedIn($request, $result['guest'], $data['device_name'] ?? null, 201, [
                'status' => 'verified',
                'demo' => true,
                'message' => 'Demo: email isn’t sent, your account is verified automatically.',
            ]);
        }

        return response()->json([
            'status' => 'verify_email',
            'message' => 'Check your email to verify your account.',
            'email' => $email,
        ], 202);
    }

    /**
     * POST login {email, password, device_name?}
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $lock = 'customer-login|'.sha1(CustomerAccounts::normalize($data['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($lock, self::FAILURES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($lock) / 60);

            return response()->json([
                'message' => "Too many tries. Wait {$minutes} ".Str::plural('minute', $minutes).' and try again, or reset your password.',
                'code' => 'locked',
                'retryAfter' => RateLimiter::availableIn($lock),
            ], 429);
        }

        ['guest' => $guest, 'matches' => $matches] = CustomerAccounts::attempt($data['email'], $data['password']);

        if (! $matches) {
            RateLimiter::hit($lock, self::LOCK_SECONDS);

            if ($guest) {
                SecurityEvent::recordForGuest(RateLimiter::tooManyAttempts($lock, self::FAILURES) ? SecurityEvent::CUSTOMER_LOCKED_OUT : SecurityEvent::CUSTOMER_SIGN_IN_FAILED, $guest, "Guest #{$guest->id}");
            }

            throw ValidationException::withMessages(['email' => self::MATCH]);
        }

        // Only now, with the password right, may we say the email is the problem
        if (! $guest->email_verified_at) {
            return response()->json([
                'message' => 'Verify your email first. Open the link we sent you.',
                'code' => 'email_unverified',
                'canResend' => true,
                'email' => $guest->login_email,
            ], 403);
        }

        RateLimiter::clear($lock);

        return $this->signedIn($request, $guest, $data['device_name'] ?? null);
    }

    /**
     * POST forgot-password {email}: always the same answer.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:190']]);

        CustomerAccounts::forgot($data['email']);

        return response()->json(['message' => 'If that email has an account, we sent a link to choose a new password.']);
    }

    /**
     * POST reset-password {email, token, password}: a new password from the
     * emailed link. The email is then proven, and every app sign-in ends.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $email = CustomerAccounts::normalize((string) $request->input('email'));
        $request->merge(['email' => $email]);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'token' => ['required', 'string', 'max:200'],
            'password' => CustomerRules::password($email),
        ]);

        $status = Password::broker(CustomerAccounts::BROKER)->reset(
            ['login_email' => $email, 'token' => $data['token'], 'password' => $data['password']],
            function (Guest $guest, string $password) {
                $guest->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $guest->tokens()->delete();

                $guest = CustomerAccounts::markVerified($guest);

                SecurityEvent::recordForGuest(SecurityEvent::CUSTOMER_PASSWORD_RESET, $guest, "Guest #{$guest->id}");
                event(new PasswordReset($guest));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'This link isn’t valid anymore. Ask for a new one.']);
        }

        return response()->json(['message' => 'Your password is changed. Sign in with the new one.']);
    }

    /**
     * POST resend-verification {email}: always the same answer.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:190']]);

        CustomerAccounts::resendVerification($data['email']);

        return response()->json(['message' => 'If that account is waiting to be verified, we sent a new link.'], 202);
    }

    /**
     * GET /verify-email/{id}/{hash}: the signed link from the email. Proves
     * the address and sends the visitor to the page that says so. It does not
     * sign anyone in: a link opened by somebody else's browser must not put
     * them in an account they didn't choose.
     */
    public function verifyEmail(Request $request, int $id, string $hash): RedirectResponse
    {
        $status = match (true) {
            ! $request->hasValidSignature() => 'expired',
            CustomerAccounts::verify($id, $hash) === null => 'invalid',
            default => 'verified',
        };

        return redirect(url('/verify-email').'?status='.$status);
    }

    /**
     * The site gets its session (and the guest to show), the app a token.
     *
     * @param  array<string, mixed>  $extra
     */
    private function signedIn(Request $request, Guest $guest, ?string $device, int $status = 200, array $extra = []): JsonResponse
    {
        $guest->forceFill(['last_signed_in_at' => now()])->saveQuietly();

        if ($request->routeIs('app.*')) {
            return response()->json([...$extra, 'token' => GuestToken::issue($guest, $device), 'guest' => $guest->toApp()], $status);
        }

        Auth::guard('guest')->login($guest, remember: true);
        $request->session()->regenerate();

        return response()->json([...$extra, 'guest' => $guest->toSite()], $status);
    }
}
