<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsBookingInput;
use App\Models\Booking;
use App\Models\DeviceToken;
use App\Models\Guest;
use App\Models\GuestToken;
use App\Models\SecurityEvent;
use App\Models\SiteSetting;
use App\Support\CustomerRules;
use App\Support\GoogleSignIn;
use App\Support\GuestIdentity;
use App\Support\IdTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The customer app's sign-in (Google or Apple ID token, or the demo
 * customer), its bearer tokens, the customer's own profile, push tokens
 * and account deletion. See routes/api.php.
 */
class AppAuthController extends Controller
{
    use ReadsBookingInput;

    private const GOOGLE_KEYS = 'https://www.googleapis.com/oauth2/v3/certs';

    private const APPLE_KEYS = 'https://appleid.apple.com/auth/keys';

    private const APPLE_ISSUER = 'https://appleid.apple.com';

    private const APPLE_RELAY_DOMAIN = '@privaterelay.appleid.com';

    /**
     * POST /api/app/sign-in/google {id_token, device_name}
     */
    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:4096'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $audiences = GoogleSignIn::appClientIds();

        if ($audiences === []) {
            return response()->json(['message' => 'Google sign-in isn’t set up yet. Ask the shop.'], 422);
        }

        try {
            $claims = IdTokenVerifier::verify($data['id_token'], self::GOOGLE_KEYS, ['https://accounts.google.com', 'accounts.google.com'], $audiences);
        } catch (RuntimeException $e) {
            return $this->refused($e);
        }

        if (blank($claims['email'] ?? null) || ! IdTokenVerifier::flag($claims['email_verified'] ?? false)) {
            return response()->json(['message' => 'Google hasn’t verified that email address. Use another account.'], 401);
        }

        $guest = GuestIdentity::fromGoogle((string) $claims['sub'], (string) $claims['email'], $claims['name'] ?? null, $claims['picture'] ?? null, true);

        return $this->signedIn($guest, $data['device_name'] ?? null);
    }

    /**
     * POST /api/app/sign-in/apple {identity_token, full_name?, device_name}
     */
    public function apple(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identity_token' => ['required', 'string', 'max:4096'],
            'full_name' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $audience = SiteSetting::current()->apple_client_id;

        if (blank($audience)) {
            return response()->json(['message' => 'Sign in with Apple isn’t set up yet. Ask the shop.'], 422);
        }

        try {
            $claims = IdTokenVerifier::verify($data['identity_token'], self::APPLE_KEYS, [self::APPLE_ISSUER], [$audience]);
        } catch (RuntimeException $e) {
            return $this->refused($e);
        }

        $email = filled($claims['email'] ?? null) ? (string) $claims['email'] : null;
        $relay = IdTokenVerifier::flag($claims['is_private_email'] ?? false) || ($email !== null && str_ends_with(strtolower($email), self::APPLE_RELAY_DOMAIN));

        $guest = GuestIdentity::fromApple(
            (string) $claims['sub'],
            $email,
            filled($data['full_name'] ?? null) ? trim($data['full_name']) : null,
            IdTokenVerifier::flag($claims['email_verified'] ?? false),
            $relay,
        );

        return $this->signedIn($guest, $data['device_name'] ?? null);
    }

    /**
     * POST /api/app/sign-in/demo {device_name}: a sample customer, on the
     * demo and on a local machine only.
     */
    public function demo(Request $request): JsonResponse
    {
        abort_unless(Guest::demoSignInAllowed(), 404);

        $data = $request->validate(['device_name' => ['nullable', 'string', 'max:120']]);

        $guest = Guest::demoCustomer();

        return $this->signedIn($guest, $data['device_name'] ?? null);
    }

    /**
     * POST /api/app/sign-out: this device's token stops working.
     */
    public function signOut(Request $request): JsonResponse
    {
        $request->attributes->get('guest_token')?->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/app/me
     */
    public function me(): JsonResponse
    {
        return response()->json($this->guest()->toApp())->header('Cache-Control', 'no-store');
    }

    /**
     * PATCH /api/app/me {name?, phone?, current_password?+password?}. The demo's shared customer is not
     * changed: the reply shows what it would be.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone' => ['sometimes', ...CustomerRules::phone()],
            'current_password' => ['required_with:password', 'string', 'max:72'],
            'password' => ['sometimes', ...CustomerRules::password($this->guest()->email)],
        ], [
            'name.required' => 'Add your name.',
            'name.max' => 'That name is too long. Keep it under 120 characters.',
            'phone.regex' => 'Use numbers only, like 0917 123 4567.',
        ]);

        $guest = $this->guest();

        if (isset($data['password']) && ! ($guest->hasPassword() && Hash::check($data['current_password'], $guest->password))) {
            throw ValidationException::withMessages(['current_password' => 'That isn’t your current password.']);
        }

        $guest->fill(Arr::only($data, ['name', 'phone']));

        if (! config('app.demo')) {
            if (isset($data['password'])) {
                // Every other sign-in ends; this one (a token or the site's session) stays
                $guest->forceFill(['password' => $data['password'], 'remember_token' => null]);
                $guest->tokens()->when($request->attributes->get('guest_token'), fn ($tokens, $keep) => $tokens->whereKeyNot($keep->getKey()))->delete();
            }

            $guest->save();
        }

        return response()->json($guest->toApp());
    }

    /**
     * POST /api/app/push-token {token, platform}: kept for when push is on.
     */
    public function pushToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:android,ios'],
        ]);

        DeviceToken::updateOrCreate(['token' => $data['token']], ['guest_id' => $this->guest()->id, 'platform' => $data['platform']]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/app/account/delete: erases who the customer is. Their
     * bookings and units stay for the shop's records, under "Deleted
     * customer". Not while a booking is still open. The demo's shared
     * customer is left alone.
     */
    public function delete(Request $request): JsonResponse
    {
        $guest = $this->guest();

        if (config('app.demo')) {
            return response()->json(['deleted' => true, 'demo' => true]);
        }

        $blocked = DB::transaction(function () use ($guest) {
            $guest = Guest::query()->lockForUpdate()->findOrFail($guest->getKey());

            if ($guest->bookings()->whereIn('status', Booking::OPEN)->get()->contains(fn (Booking $booking) => $booking->isOpen())) {
                return true;
            }

            $guest->tokens()->delete();
            $guest->deviceTokens()->delete();

            $guest->forceFill([
                'name' => 'Deleted customer',
                'email' => null,
                'phone' => null,
                'google_id' => null,
                'apple_id' => null,
                'avatar_url' => null,
                'referral_code' => null,
                'notes' => null,
                'remember_token' => null,
                'password' => null,
                'login_email' => null,
                'email_verified_at' => null,
            ])->save();

            SecurityEvent::recordForGuest(SecurityEvent::GUEST_ACCOUNT_DELETED, $guest, "Guest #{$guest->id}");

            return false;
        });

        if ($blocked) {
            return response()->json(['message' => 'You still have a booking that isn’t finished. Cancel it or wait until it’s done, then delete your account.'], 409);
        }

        return response()->json(['deleted' => true]);
    }

    private function signedIn(Guest $guest, ?string $device): JsonResponse
    {
        $guest->forceFill(['last_signed_in_at' => now()])->saveQuietly();

        return response()->json(['token' => GuestToken::issue($guest, $device), 'guest' => $guest->toApp()]);
    }

    /**
     * 503 when the provider's keys couldn't be fetched, else 401.
     */
    private function refused(RuntimeException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage()], $e->getCode() === 503 ? 503 : 401);
    }
}
