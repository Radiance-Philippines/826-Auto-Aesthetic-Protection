<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DeviceToken;
use App\Models\Guest;
use App\Models\GuestToken;
use App\Models\SecurityEvent;
use App\Models\SiteSetting;
use App\Models\Vehicle;
use App\Support\IdTokenVerifier;
use App\Support\PushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsShop;
use Tests\Support\SignsIdTokens;
use Tests\TestCase;

/**
 * The customer app's sign-in: bearer tokens, Google and Apple ID tokens
 * (checked against a fake key set), the demo customer, the profile, push
 * tokens and account deletion.
 */
class AppAuthTest extends TestCase
{
    use BuildsShop, RefreshDatabase, SignsIdTokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->buildShop();
        SiteSetting::instance()->update(['google_android_client_id' => 'android.apps.googleusercontent.com', 'google_ios_client_id' => 'ios.apps.googleusercontent.com', 'apple_client_id' => 'ph.radianceph.aap826']);
        $this->fakeProviderKeys();
    }

    private function signedIn(?Guest $guest = null): array
    {
        $guest ??= Guest::factory()->create();

        return [$guest, ['Authorization' => 'Bearer '.GuestToken::issue($guest, 'Test phone')]];
    }

    // Bearer tokens

    public function test_a_token_signs_in_the_existing_customer_endpoints_without_a_cookie_or_session(): void
    {
        [$guest, $headers] = $this->signedIn();
        Vehicle::factory()->create(['guest_id' => $guest->id, 'model' => 'Toyota Vios']);
        Booking::factory()->create(['guest_id' => $guest->id, 'branch_id' => $this->branch->id]);

        $this->getJson('/api/my-vehicles', $headers)->assertOk()->assertJsonPath('vehicles.0.model', 'Toyota Vios')->assertCookieMissing(config('session.cookie'));
        $this->getJson('/api/my-bookings', $headers)->assertOk()->assertJsonCount(1, 'bookings');
        $this->getJson('/api/my-referral', $headers)->assertOk();
        $this->getJson('/api/availability?branch='.$this->branch->id.'&services[]='.$this->detail->id, $headers)->assertOk();
    }

    public function test_the_old_endpoints_still_refuse_a_visitor_with_no_sign_in(): void
    {
        $this->getJson('/api/my-vehicles')->assertUnauthorized();
        $this->getJson('/api/my-bookings')->assertUnauthorized();
    }

    public function test_the_site_cookie_sign_in_still_works(): void
    {
        $guest = Guest::factory()->create();
        Vehicle::factory()->create(['guest_id' => $guest->id]);

        $this->actingAs($guest, 'guest')->getJson('/api/my-vehicles')->assertOk()->assertJsonCount(1, 'vehicles');
    }

    public function test_an_unknown_revoked_or_expired_token_is_a_401(): void
    {
        [$guest, $headers] = $this->signedIn();

        $this->getJson('/api/my-vehicles', ['Authorization' => 'Bearer nonsense'])->assertUnauthorized()->assertJson(['signIn' => true]);

        $this->getJson('/api/app/me', $headers)->assertOk();
        GuestToken::query()->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/app/me', $headers)->assertUnauthorized();
        $this->getJson('/api/my-vehicles', $headers)->assertUnauthorized();

        [, $fresh] = $this->signedIn($guest);
        $this->postJson('/api/app/sign-out', [], $fresh)->assertOk();
        $this->getJson('/api/app/me', $fresh)->assertUnauthorized();
    }

    public function test_the_app_endpoints_need_a_token(): void
    {
        foreach (['/api/app/me', '/api/app/home'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }

        $this->postJson('/api/app/sign-out')->assertUnauthorized();
        $this->postJson('/api/app/account/delete')->assertUnauthorized();
        $this->postJson('/api/app/push-token', ['token' => 'x', 'platform' => 'android'])->assertUnauthorized();
    }

    public function test_only_the_hash_of_a_token_is_kept_and_last_used_is_touched_at_most_once_a_minute(): void
    {
        $guest = Guest::factory()->create();
        $plain = GuestToken::issue($guest, 'Pixel 8');

        $this->assertSame(40, strlen($plain));
        $row = GuestToken::sole();
        $this->assertSame(hash('sha256', $plain), $row->token_hash);
        $this->assertSame('Pixel 8', $row->name);
        $this->assertNull($row->last_used_at);

        $this->getJson('/api/app/me', ['Authorization' => "Bearer {$plain}"])->assertOk();
        $first = $row->refresh()->last_used_at;
        $this->assertNotNull($first);

        $this->travel(30)->seconds();
        $this->getJson('/api/app/me', ['Authorization' => "Bearer {$plain}"])->assertOk();
        $this->assertTrue($first->equalTo($row->refresh()->last_used_at));

        $this->travel(2)->minutes();
        $this->getJson('/api/app/me', ['Authorization' => "Bearer {$plain}"])->assertOk();
        $this->assertTrue($row->refresh()->last_used_at->greaterThan($first));
    }

    public function test_signing_out_ends_only_that_device(): void
    {
        $guest = Guest::factory()->create();
        [, $phone] = $this->signedIn($guest);
        [, $tablet] = $this->signedIn($guest);

        $this->postJson('/api/app/sign-out', [], $phone)->assertOk()->assertJson(['ok' => true]);

        $this->getJson('/api/app/me', $phone)->assertUnauthorized();
        $this->getJson('/api/app/me', $tablet)->assertOk();
    }

    public function test_sign_in_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/app/sign-in/google', ['id_token' => 'x'])->assertUnauthorized();
        }

        $this->postJson('/api/app/sign-in/google', ['id_token' => 'x'])->assertStatus(429);
    }

    // Google

    public function test_a_google_id_token_signs_in_and_makes_the_customer(): void
    {
        $response = $this->postJson('/api/app/sign-in/google', ['id_token' => $this->idToken($this->googleClaims()), 'device_name' => 'Pixel 8'])
            ->assertOk()
            ->assertJsonStructure(['token', 'guest' => ['id', 'name', 'email', 'phone', 'avatar', 'referralCode', 'source']])
            ->assertJsonPath('guest.name', 'Maria Santos')
            ->assertJsonPath('guest.email', 'maria@example.com')
            ->assertJsonPath('guest.avatar', 'https://lh3.example/photo.jpg')
            ->assertJsonPath('guest.source', 'google');

        $this->assertSame('1098765432100', Guest::sole()->google_id);
        $this->getJson('/api/app/me', ['Authorization' => 'Bearer '.$response->json('token')])->assertOk()->assertJsonPath('email', 'maria@example.com');
        $this->assertSame('Pixel 8', GuestToken::sole()->name);

        // Signing in again is the same customer
        $this->postJson('/api/app/sign-in/google', ['id_token' => $this->idToken($this->googleClaims())])->assertOk();
        $this->assertSame(1, Guest::count());
    }

    public function test_the_web_client_id_and_the_ios_client_id_are_accepted_too(): void
    {
        foreach (['client.apps.googleusercontent.com', 'ios.apps.googleusercontent.com'] as $aud) {
            $this->postJson('/api/app/sign-in/google', ['id_token' => $this->idToken($this->googleClaims(['aud' => $aud]))])->assertOk();
        }
    }

    public function test_a_google_token_that_is_wrong_is_refused(): void
    {
        $wrong = [
            'someone else’s client' => $this->idToken($this->googleClaims(['aud' => 'other.apps.googleusercontent.com'])),
            'another issuer' => $this->idToken($this->googleClaims(['iss' => 'https://evil.example'])),
            'expired' => $this->idToken($this->googleClaims(['exp' => now()->subHour()->getTimestamp()])),
            'not signed by google' => $this->idToken($this->googleClaims(), key: openssl_pkey_new(['private_key_bits' => 2048])),
            'unknown key id' => $this->idToken($this->googleClaims(), kid: 'other-key'),
            'email not verified' => $this->idToken($this->googleClaims(['email_verified' => false])),
            'no email' => $this->idToken($this->googleClaims(['email' => null])),
            'not a token' => 'nope',
        ];

        foreach ($wrong as $why => $token) {
            $this->postJson('/api/app/sign-in/google', ['id_token' => $token])->assertUnauthorized(); // $why
            $this->assertSame(0, Guest::count(), $why);
        }

        $this->assertSame(0, GuestToken::count());
    }

    public function test_a_token_with_a_swapped_body_fails_the_signature(): void
    {
        [$head, , $signature] = explode('.', $this->idToken($this->googleClaims()));
        $body = rtrim(strtr(base64_encode(json_encode($this->googleClaims(['sub' => 'someone-else']))), '+/', '-_'), '=');

        $this->postJson('/api/app/sign-in/google', ['id_token' => "{$head}.{$body}.{$signature}"])->assertUnauthorized();
    }

    public function test_google_sign_in_says_so_when_it_is_not_set_up(): void
    {
        SiteSetting::instance()->update(['google_client_id' => null, 'google_client_secret' => null, 'google_android_client_id' => null, 'google_ios_client_id' => null]);

        $this->postJson('/api/app/sign-in/google', ['id_token' => $this->idToken($this->googleClaims())])->assertStatus(422)->assertJsonPath('message', 'Google sign-in isn’t set up yet. Ask the shop.');
    }

    public function test_a_google_outage_is_a_503_not_a_wrong_token(): void
    {
        Http::swap(new Factory);
        Http::fake(['www.googleapis.com/*' => Http::response('', 500)]);

        $this->postJson('/api/app/sign-in/google', ['id_token' => $this->idToken($this->googleClaims())])->assertStatus(503);
    }

    public function test_google_keys_are_cached_between_sign_ins(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/app/sign-in/google', ['id_token' => $this->idToken($this->googleClaims())])->assertOk();
        }

        Http::assertSentCount(1);
    }

    public function test_a_walk_in_with_the_same_verified_email_keeps_their_history(): void
    {
        $walkIn = Guest::factory()->create(['google_id' => null, 'email' => 'Maria@Example.com', 'source' => 'walk_in']);

        $this->postJson('/api/app/sign-in/google', ['id_token' => $this->idToken($this->googleClaims())])->assertOk();

        $this->assertSame(1, Guest::count());
        $this->assertSame('1098765432100', $walkIn->refresh()->google_id);
    }

    public function test_the_modulus_of_a_key_is_turned_into_a_working_public_key(): void
    {
        $rsa = openssl_pkey_get_details($this->providerKey())['rsa'];
        $pem = IdTokenVerifier::pem($rsa['n'], $rsa['e']);

        openssl_sign('hello', $signature, $this->providerKey(), OPENSSL_ALGO_SHA256);
        $this->assertSame(1, openssl_verify('hello', $signature, $pem, OPENSSL_ALGO_SHA256));
    }

    // Apple

    public function test_an_apple_identity_token_signs_in_and_makes_the_customer(): void
    {
        $this->postJson('/api/app/sign-in/apple', ['identity_token' => $this->idToken($this->appleClaims()), 'full_name' => 'Maria Santos', 'device_name' => 'iPhone'])
            ->assertOk()
            ->assertJsonPath('guest.name', 'Maria Santos')
            ->assertJsonPath('guest.email', 'maria@example.com')
            ->assertJsonPath('guest.source', 'apple')
            ->assertJsonStructure(['token']);

        $this->assertSame('001234.abcdef.5678', Guest::sole()->apple_id);

        // Next time Apple sends no name: the customer is the same and keeps theirs
        $this->postJson('/api/app/sign-in/apple', ['identity_token' => $this->idToken($this->appleClaims())])->assertOk()->assertJsonPath('guest.name', 'Maria Santos');
        $this->assertSame(1, Guest::count());
    }

    public function test_apple_may_hide_the_email_and_a_relay_address_adopts_nobody(): void
    {
        $walkIn = Guest::factory()->create(['google_id' => null, 'email' => 'maria@privaterelay.appleid.com', 'source' => 'walk_in']);

        $this->postJson('/api/app/sign-in/apple', ['identity_token' => $this->idToken($this->appleClaims(['email' => 'maria@privaterelay.appleid.com', 'is_private_email' => 'true']))])->assertOk();

        $this->assertSame(2, Guest::count());
        $this->assertNull($walkIn->refresh()->apple_id);

        // No email at all
        $this->postJson('/api/app/sign-in/apple', ['identity_token' => $this->idToken($this->appleClaims(['sub' => 'no-email', 'email' => null]))])->assertOk()->assertJsonPath('guest.email', null)->assertJsonPath('guest.name', 'Apple customer');
    }

    public function test_a_real_verified_apple_email_adopts_the_walk_in_and_an_unverified_one_does_not(): void
    {
        $walkIn = Guest::factory()->create(['google_id' => null, 'email' => 'maria@example.com', 'source' => 'walk_in']);

        $this->postJson('/api/app/sign-in/apple', ['identity_token' => $this->idToken($this->appleClaims(['sub' => 'unverified', 'email_verified' => 'false']))])->assertOk();
        $this->assertNull($walkIn->refresh()->apple_id);

        $this->postJson('/api/app/sign-in/apple', ['identity_token' => $this->idToken($this->appleClaims())])->assertOk();
        $this->assertSame('001234.abcdef.5678', $walkIn->refresh()->apple_id);
    }

    public function test_an_apple_token_that_is_wrong_is_refused(): void
    {
        $wrong = [
            'another app' => $this->idToken($this->appleClaims(['aud' => 'com.other.app'])),
            'another issuer' => $this->idToken($this->appleClaims(['iss' => 'https://accounts.google.com'])),
            'expired' => $this->idToken($this->appleClaims(['exp' => now()->subHour()->getTimestamp()])),
            'a google token' => $this->idToken($this->googleClaims()),
        ];

        foreach ($wrong as $token) {
            $this->postJson('/api/app/sign-in/apple', ['identity_token' => $token])->assertUnauthorized();
        }

        $this->assertSame(0, Guest::count());
    }

    public function test_apple_sign_in_says_so_when_it_is_not_set_up(): void
    {
        SiteSetting::instance()->update(['apple_client_id' => null]);

        $this->postJson('/api/app/sign-in/apple', ['identity_token' => $this->idToken($this->appleClaims())])->assertStatus(422);
    }

    // The demo customer

    public function test_demo_sign_in_is_off_in_production(): void
    {
        $this->assertFalse(app()->isLocal());
        $this->postJson('/api/app/sign-in/demo', ['device_name' => 'Pixel 8'])->assertNotFound();
        $this->assertSame(0, Guest::count());
    }

    public function test_demo_sign_in_works_on_the_demo_and_locally_with_one_shared_customer(): void
    {
        config(['app.demo' => true]);

        $first = $this->postJson('/api/app/sign-in/demo', ['device_name' => 'Pixel 8'])->assertOk()->assertJsonPath('guest.name', 'Demo Customer')->assertJsonPath('guest.email', 'demo-customer@example.com');
        $this->postJson('/api/app/sign-in/demo')->assertOk()->assertJsonPath('guest.id', $first->json('guest.id'));

        $this->assertSame(1, Guest::count());
        $this->assertSame(Guest::DEMO_GOOGLE_ID, Guest::sole()->google_id);

        config(['app.demo' => false]);
        $this->app['env'] = 'local';
        $this->postJson('/api/app/sign-in/demo')->assertOk();
    }

    public function test_the_demo_customer_is_never_changed_or_deleted_on_the_demo(): void
    {
        config(['app.demo' => true]);
        $headers = ['Authorization' => 'Bearer '.$this->postJson('/api/app/sign-in/demo')->json('token')];

        $this->patchJson('/api/app/me', ['name' => 'Someone Else'], $headers)->assertOk()->assertJsonPath('name', 'Someone Else');
        $this->assertSame('Demo Customer', Guest::sole()->name);

        $this->postJson('/api/app/account/delete', [], $headers)->assertOk()->assertJson(['deleted' => true, 'demo' => true]);
        $this->assertSame('Demo Customer', Guest::sole()->name);
        $this->assertSame(1, GuestToken::count());
    }

    // Profile, push, deletion

    public function test_me_and_changing_the_name_and_phone(): void
    {
        [$guest, $headers] = $this->signedIn(Guest::factory()->create(['name' => 'Maria Santos', 'email' => 'maria@example.com', 'referral_code' => 'MARIA-7K3Q']));

        $this->getJson('/api/app/me', $headers)->assertOk()->assertExactJson([
            'id' => $guest->id,
            'name' => 'Maria Santos',
            'email' => 'maria@example.com',
            'phone' => null,
            'avatar' => null,
            'referralCode' => 'MARIA-7K3Q',
            'source' => 'google',
            'signInMethod' => 'google',
            'emailVerified' => false,
        ]);

        $this->patchJson('/api/app/me', ['phone' => '+63 917 123 4567', 'name' => 'Maria S. Santos'], $headers)->assertOk()->assertJsonPath('phone', '+63 917 123 4567');
        $this->assertSame('9171234567', $guest->refresh()->phone_key);
        $this->assertSame('Maria S. Santos', $guest->name);

        $this->patchJson('/api/app/me', ['phone' => '0917'], $headers)->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->patchJson('/api/app/me', ['phone' => 'call me'], $headers)->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->patchJson('/api/app/me', ['name' => ''], $headers)->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->patchJson('/api/app/me', ['phone' => null], $headers)->assertOk();
        $this->assertNull($guest->refresh()->phone_key);
    }

    public function test_a_push_token_is_kept_once_per_device_and_moves_with_the_account(): void
    {
        [$maria, $mariaHeaders] = $this->signedIn();
        [$juan, $juanHeaders] = $this->signedIn();

        $this->postJson('/api/app/push-token', ['token' => 'ExponentPushToken[abc]', 'platform' => 'android'], $mariaHeaders)->assertOk();
        $this->postJson('/api/app/push-token', ['token' => 'ExponentPushToken[abc]', 'platform' => 'android'], $mariaHeaders)->assertOk();
        $this->assertSame($maria->id, DeviceToken::sole()->guest_id);

        $this->postJson('/api/app/push-token', ['token' => 'ExponentPushToken[abc]', 'platform' => 'android'], $juanHeaders)->assertOk();
        $this->assertSame($juan->id, DeviceToken::sole()->guest_id);

        $this->postJson('/api/app/push-token', ['token' => 'x', 'platform' => 'windows'], $mariaHeaders)->assertUnprocessable();
    }

    public function test_push_is_dormant_until_it_is_switched_on(): void
    {
        [$guest] = $this->signedIn();
        DeviceToken::create(['guest_id' => $guest->id, 'token' => 'ExponentPushToken[abc]', 'platform' => 'ios']);
        Http::swap(new Factory);
        Http::fake();

        $this->assertFalse(PushNotifier::toGuest($guest, 'Ready', 'Your car is ready'));
        Http::assertNothingSent();

        config(['services.push.enabled' => true]);
        Http::swap(new Factory);
        Http::fake(['exp.host/*' => Http::response(['data' => []])]);
        $this->assertTrue(PushNotifier::toGuest($guest, 'Ready', 'Your car is ready'));
        Http::assertSent(fn ($request) => $request[0]['to'] === 'ExponentPushToken[abc]' && $request[0]['title'] === 'Ready');
    }

    public function test_deleting_the_account_erases_the_person_and_keeps_the_shops_records(): void
    {
        $guest = Guest::factory()->create(['name' => 'Maria Santos', 'email' => 'maria@example.com', 'phone' => '0917 123 4567', 'avatar_url' => 'https://x.example/a.jpg', 'referral_code' => 'MARIA-7K3Q', 'notes' => 'Prefers mornings', 'apple_id' => 'apple-sub']);
        [, $headers] = $this->signedIn($guest);
        GuestToken::issue($guest, 'Tablet');
        DeviceToken::create(['guest_id' => $guest->id, 'token' => 'tok', 'platform' => 'android']);
        $vehicle = Vehicle::factory()->create(['guest_id' => $guest->id]);
        $done = Booking::factory()->status('exited')->create(['guest_id' => $guest->id, 'vehicle_id' => $vehicle->id, 'branch_id' => $this->branch->id]);
        Booking::factory()->status('cancelled')->create(['guest_id' => $guest->id, 'branch_id' => $this->branch->id]);

        $this->postJson('/api/app/account/delete', [], $headers)->assertOk()->assertExactJson(['deleted' => true]);

        $guest->refresh();
        $this->assertSame('Deleted customer', $guest->name);
        foreach (['email', 'phone', 'phone_key', 'google_id', 'apple_id', 'avatar_url', 'referral_code', 'notes'] as $field) {
            $this->assertNull($guest->{$field}, $field);
        }

        $this->assertSame(0, GuestToken::count());
        $this->assertSame(0, DeviceToken::count());
        $this->assertSame($guest->id, $done->refresh()->guest_id);
        $this->assertSame($guest->id, $vehicle->refresh()->guest_id);

        $event = SecurityEvent::sole();
        $this->assertSame(SecurityEvent::GUEST_ACCOUNT_DELETED, $event->kind);
        $this->assertSame($guest->id, $event->guest_id);
        $this->assertStringNotContainsString('Maria', (string) $event->note);

        $this->getJson('/api/app/me', $headers)->assertUnauthorized();

        // Signing in with the same Google account later makes a new customer
        $this->postJson('/api/app/sign-in/google', ['id_token' => $this->idToken($this->googleClaims())])->assertOk();
        $this->assertNotSame($guest->id, Guest::query()->where('google_id', '1098765432100')->value('id'));
    }

    public function test_an_open_booking_blocks_deleting_the_account(): void
    {
        foreach (['confirmed', 'arrived', 'in_progress', 'quality_check', 'ready', 'requested', 'payment_review'] as $status) {
            $guest = Guest::factory()->create(['name' => 'Maria Santos']);
            [, $headers] = $this->signedIn($guest);
            $booking = Booking::factory()->status($status)->create(['guest_id' => $guest->id, 'branch_id' => $this->branch->id]);

            $this->postJson('/api/app/account/delete', [], $headers)->assertStatus(409)->assertJsonPath('message', 'You still have a booking that isn’t finished. Cancel it or wait until it’s done, then delete your account.');
            $this->assertSame('Maria Santos', $guest->refresh()->name, $status);
            $this->assertSame(1, $guest->tokens()->count());

            $booking->update(['status' => 'exited']);
            $this->postJson('/api/app/account/delete', [], $headers)->assertOk();
        }
    }

    public function test_a_lapsed_unpaid_hold_does_not_block_deleting(): void
    {
        $guest = Guest::factory()->create();
        [, $headers] = $this->signedIn($guest);
        Booking::factory()->hold(-5)->create(['guest_id' => $guest->id, 'branch_id' => $this->branch->id]);

        $this->postJson('/api/app/account/delete', [], $headers)->assertOk();
    }
}
