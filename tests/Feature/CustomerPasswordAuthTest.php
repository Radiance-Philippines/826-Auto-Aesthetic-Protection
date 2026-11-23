<?php

namespace Tests\Feature;

use App\Mail\CustomerAccountMail;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\GuestToken;
use App\Models\MailSetting;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\CustomerAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\Support\BuildsShop;
use Tests\TestCase;

/**
 * Customers' own accounts: sign up, verify, sign in, forgot and reset,
 * for the site (session) and the app (bearer token).
 */
class CustomerPasswordAuthTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private const PASSWORD = 'correct horse 42';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->buildShop();
        Mail::fake();
        $this->mailbox();
    }

    private function mailbox(bool $on = true): void
    {
        MailSetting::instance()->update($on ? ['address' => 'hello@826auto.test', 'password' => 'secret'] : ['address' => null, 'password' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function signUpData(array $over = []): array
    {
        return ['name' => 'Maria Santos', 'email' => 'Maria@Example.com', 'password' => self::PASSWORD, 'phone' => '0917 123 4567', ...$over];
    }

    private function account(array $over = []): Guest
    {
        $guest = new Guest(['name' => 'Maria Santos', 'email' => 'maria@example.com', 'source' => 'password']);
        $guest->forceFill(['login_email' => 'maria@example.com', 'password' => self::PASSWORD, 'email_verified_at' => now(), ...$over])->save();

        return $guest;
    }

    private function sentTo(string $kind): ?CustomerAccountMail
    {
        return Mail::sent(CustomerAccountMail::class, fn (CustomerAccountMail $mail) => $mail->kind === $kind)->last();
    }

    // Sign up

    public function test_sign_up_makes_an_unverified_account_and_emails_a_link(): void
    {
        $this->postJson('/auth/register', $this->signUpData())
            ->assertStatus(202)
            ->assertExactJson(['status' => 'verify_email', 'message' => 'Check your email to verify your account.', 'email' => 'maria@example.com']);

        $guest = Guest::query()->where('login_email', 'maria@example.com')->firstOrFail();
        $this->assertSame('password', $guest->source);
        $this->assertNull($guest->email_verified_at);
        $this->assertSame('maria@example.com', $guest->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $guest->password));
        $this->assertNotSame(self::PASSWORD, $guest->getRawOriginal('password'));
        $this->assertGuest('guest');

        $mail = $this->sentTo('verify');
        $this->assertNotNull($mail);
        $this->assertTrue($mail->hasTo('maria@example.com'));
        $this->assertStringContainsString('/verify-email/'.$guest->id.'/', $mail->url);
        $this->assertStringContainsString('signature=', $mail->url);
    }

    public function test_sign_up_rules(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->postJson('/auth/register', $this->signUpData(['password' => 'short1']))->assertJsonValidationErrors('password');
        $this->postJson('/auth/register', $this->signUpData(['password' => 'onlylettersonly']))->assertJsonValidationErrors('password');
        $this->postJson('/auth/register', $this->signUpData(['password' => '12345678901234']))->assertJsonValidationErrors('password');
        $this->postJson('/auth/register', $this->signUpData(['password' => str_repeat('a1', 40)]))->assertJsonValidationErrors('password');
        $this->postJson('/auth/register', $this->signUpData(['email' => 'maria12345@example.com', 'password' => 'maria12345@example.com']))->assertJsonValidationErrors('password');
        $this->postJson('/auth/register', $this->signUpData(['name' => 'M']))->assertJsonValidationErrors('name');
        $this->postJson('/auth/register', $this->signUpData(['name' => str_repeat('x', 81)]))->assertJsonValidationErrors('name');
        $this->postJson('/auth/register', $this->signUpData(['name' => 'Maria <b>']))->assertJsonValidationErrors('name');
        $this->postJson('/auth/register', $this->signUpData(['email' => 'not-an-email']))->assertJsonValidationErrors('email');
        $this->postJson('/auth/register', $this->signUpData(['phone' => '123']))->assertJsonValidationErrors('phone');

        $this->assertSame(0, Guest::query()->count());
        Mail::assertNothingSent();

        // The phone is optional
        $this->postJson('/auth/register', $this->signUpData(['phone' => null]))->assertStatus(202);
    }

    public function test_sign_up_does_not_say_whether_an_email_has_an_account(): void
    {
        $this->account();
        Guest::factory()->create(['email' => 'luis@example.com']);

        $fresh = $this->postJson('/auth/register', $this->signUpData(['email' => 'new@example.com']));
        $verified = $this->postJson('/auth/register', $this->signUpData(['email' => 'maria@example.com']));
        $google = $this->postJson('/auth/register', $this->signUpData(['email' => 'LUIS@example.com']));

        foreach ([$fresh, $verified, $google] as $response) {
            $response->assertStatus(202)->assertJsonPath('status', 'verify_email')->assertJsonPath('message', 'Check your email to verify your account.');
        }

        // Nothing was created for the two addresses that already had an account
        $this->assertSame(1, Guest::query()->where('email', 'maria@example.com')->count());
        $this->assertSame(1, Guest::query()->where('email', 'luis@example.com')->count());
        $this->assertTrue(Hash::check(self::PASSWORD, Guest::query()->where('login_email', 'maria@example.com')->value('password')));
        $this->assertNull(Guest::query()->where('email', 'luis@example.com')->value('password'));

        // Their owners got "you already have an account" instead of a link
        Mail::assertSent(CustomerAccountMail::class, fn (CustomerAccountMail $mail) => $mail->kind === 'existing' && $mail->hasTo('maria@example.com'));
        Mail::assertSent(CustomerAccountMail::class, fn (CustomerAccountMail $mail) => $mail->kind === 'existing' && $mail->hasTo('luis@example.com'));
        Mail::assertSent(CustomerAccountMail::class, fn (CustomerAccountMail $mail) => $mail->kind === 'verify' && $mail->hasTo('new@example.com'));
        Mail::assertSent(CustomerAccountMail::class, 3);
    }

    public function test_a_mailbox_that_fails_does_not_change_the_answer(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->postJson('/auth/register', $this->signUpData())->assertStatus(202);
    }

    public function test_a_walk_in_is_never_adopted_at_sign_up(): void
    {
        $walkIn = Guest::factory()->walkIn()->create(['name' => 'Maria (walk-in)', 'email' => 'maria@example.com']);
        Booking::factory()->create(['guest_id' => $walkIn->id, 'branch_id' => $this->branch->id]);

        $this->postJson('/auth/register', $this->signUpData())->assertStatus(202);

        $this->assertNull($walkIn->fresh()->password);
        $this->assertNull($walkIn->fresh()->login_email);
        $this->assertSame(1, Booking::query()->where('guest_id', $walkIn->id)->count());
        $new = Guest::query()->where('login_email', 'maria@example.com')->firstOrFail();
        $this->assertNotSame($walkIn->id, $new->id);
        $this->assertSame(0, $new->bookings()->count());

        // Not signed in, not even by knowing the password
        $this->postJson('/auth/login', ['email' => 'maria@example.com', 'password' => self::PASSWORD])->assertForbidden()->assertJsonPath('code', 'email_unverified');
    }

    public function test_the_verified_link_joins_the_walk_in_with_that_email(): void
    {
        $walkIn = Guest::factory()->walkIn()->create(['name' => 'Maria (walk-in)', 'email' => 'maria@example.com', 'phone' => '0917 000 1111']);
        $booking = Booking::factory()->create(['guest_id' => $walkIn->id, 'branch_id' => $this->branch->id]);

        $this->postJson('/auth/register', $this->signUpData())->assertStatus(202);
        $newId = Guest::query()->where('login_email', 'maria@example.com')->value('id');

        $this->get($this->sentTo('verify')->url)->assertRedirect(url('/verify-email').'?status=verified');

        $this->assertNull(Guest::query()->find($newId));
        $walkIn->refresh();
        $this->assertSame('maria@example.com', $walkIn->login_email);
        $this->assertNotNull($walkIn->email_verified_at);
        $this->assertSame('Maria (walk-in)', $walkIn->name);
        $this->assertSame('0917 000 1111', $walkIn->phone);
        $this->assertSame($walkIn->id, $booking->fresh()->guest_id);

        $this->postJson('/auth/login', ['email' => 'maria@example.com', 'password' => self::PASSWORD])->assertOk();
        $this->assertAuthenticatedAs($walkIn, 'guest');
    }

    public function test_a_walk_in_with_a_google_or_apple_account_is_not_adopted(): void
    {
        $google = Guest::factory()->create(['email' => 'maria@example.com']);
        $this->account(['login_email' => 'other@example.com', 'email' => 'maria@example.com', 'email_verified_at' => null]);
        $guest = Guest::query()->where('login_email', 'other@example.com')->firstOrFail();

        // The verify link of a password account whose email matches a Google customer leaves both be
        $this->get(CustomerAccounts::verifyUrl($guest))->assertRedirect(url('/verify-email').'?status=verified');

        $this->assertNotNull(Guest::query()->find($google->id));
        $this->assertNotNull(Guest::query()->find($guest->id));
        $this->assertNull($google->fresh()->password);
    }

    public function test_signing_up_again_replaces_an_unproven_password_and_old_links(): void
    {
        $this->postJson('/auth/register', $this->signUpData(['password' => 'attacker pass 99', 'name' => 'Attacker']))->assertStatus(202);
        $old = $this->sentTo('verify')->url;

        $this->travel(2)->minutes();
        $this->postJson('/auth/register', $this->signUpData(['name' => 'Maria Santos']))->assertStatus(202);
        $new = Mail::sent(CustomerAccountMail::class, fn ($mail) => $mail->kind === 'verify')->last()->url;

        $this->assertSame(1, Guest::query()->where('login_email', 'maria@example.com')->count());
        $guest = Guest::query()->where('login_email', 'maria@example.com')->firstOrFail();
        $this->assertSame('Maria Santos', $guest->name);
        $this->assertFalse(Hash::check('attacker pass 99', $guest->password));

        $this->get($old)->assertRedirect(url('/verify-email').'?status=invalid');
        $this->assertNull($guest->fresh()->email_verified_at);
        $this->get($new)->assertRedirect(url('/verify-email').'?status=verified');
        $this->assertNotNull($guest->fresh()->email_verified_at);
    }

    public function test_production_refuses_sign_up_without_a_mailbox_but_the_demo_does_not(): void
    {
        $this->mailbox(false);

        $this->postJson('/auth/register', $this->signUpData())
            ->assertStatus(503)
            ->assertJsonPath('message', 'Account sign-up by email isn’t available yet. Use Google.')
            ->assertJsonPath('code', 'email_sign_up_unavailable');
        $this->postJson('/api/app/auth/register', $this->signUpData())->assertStatus(503);
        $this->assertSame(0, Guest::query()->count());
        Mail::assertNothingSent();
    }

    public function test_the_demo_verifies_on_the_spot_and_sends_nothing(): void
    {
        $this->mailbox(false);
        config(['app.demo' => true]);

        $this->postJson('/auth/register', $this->signUpData())
            ->assertCreated()
            ->assertJsonPath('status', 'verified')
            ->assertJsonPath('demo', true)
            ->assertJsonPath('guest.name', 'Maria Santos')
            ->assertJsonPath('message', 'Demo: email isn’t sent, your account is verified automatically.');

        $guest = Guest::query()->where('login_email', 'maria@example.com')->firstOrFail();
        $this->assertNotNull($guest->email_verified_at);
        $this->assertAuthenticatedAs($guest, 'guest');
        Mail::assertNothingSent();

        // A second try with the same address: said plainly in the demo, which has nobody's real mail to protect
        $this->postJson('/auth/register', $this->signUpData())->assertUnprocessable()->assertJsonValidationErrors('email');

        // The app gets a token instead of a session
        $this->postJson('/api/app/auth/register', $this->signUpData(['email' => 'app@example.com', 'device_name' => 'Pixel']))
            ->assertCreated()
            ->assertJsonStructure(['token', 'guest' => ['id', 'name', 'email', 'signInMethod', 'emailVerified']])
            ->assertJsonPath('guest.signInMethod', 'password');
    }

    public function test_the_demo_does_not_join_a_walk_in_either(): void
    {
        $this->mailbox(false);
        config(['app.demo' => true]);
        $walkIn = Guest::factory()->walkIn()->create(['email' => 'maria@example.com']);

        $this->postJson('/auth/register', $this->signUpData())->assertCreated();

        $this->assertNull($walkIn->fresh()->login_email);
        $this->assertNotNull(Guest::query()->find($walkIn->id));
    }

    // Verification

    public function test_the_link_is_signed_lasts_a_day_and_signs_no_one_in(): void
    {
        $this->postJson('/auth/register', $this->signUpData());
        $url = $this->sentTo('verify')->url;
        $guest = Guest::query()->where('login_email', 'maria@example.com')->firstOrFail();

        $this->get(str_replace('/'.$guest->verificationHash(), '/'.str_repeat('a', 64), $url))->assertRedirect(url('/verify-email').'?status=expired');
        $this->get(url("/verify-email/{$guest->id}/".$guest->verificationHash()))->assertRedirect(url('/verify-email').'?status=expired');

        $this->travel(25)->hours();
        $this->get($url)->assertRedirect(url('/verify-email').'?status=expired');
        $this->assertNull($guest->fresh()->email_verified_at);

        $this->travelBack();
        $this->get($url)->assertRedirect(url('/verify-email').'?status=verified');
        $this->assertGuest('guest');
        $this->assertNotNull($guest->fresh()->email_verified_at);
        // Opened twice: still fine
        $this->get($url)->assertRedirect(url('/verify-email').'?status=verified');
    }

    public function test_resend_answers_the_same_for_everyone_and_sends_only_to_unverified_accounts(): void
    {
        $this->postJson('/auth/register', $this->signUpData(['email' => 'wait@example.com']));
        $this->account();
        $this->travel(2)->minutes();
        Mail::fake();

        foreach (['wait@example.com', 'maria@example.com', 'nobody@example.com'] as $email) {
            $this->postJson('/auth/resend-verification', ['email' => $email])->assertStatus(202)->assertExactJson(['message' => 'If that account is waiting to be verified, we sent a new link.']);
        }

        Mail::assertSent(CustomerAccountMail::class, 1);
        Mail::assertSent(CustomerAccountMail::class, fn (CustomerAccountMail $mail) => $mail->kind === 'verify' && $mail->hasTo('wait@example.com'));

        // Again right away: no second email inside the minute
        $this->postJson('/auth/resend-verification', ['email' => 'wait@example.com'])->assertStatus(202);
        Mail::assertSent(CustomerAccountMail::class, 1);
    }

    // Sign in

    public function test_a_verified_customer_signs_in_on_the_site_with_a_new_session(): void
    {
        $guest = $this->account();
        $this->withSession(['probe' => 'old']);
        $before = session()->getId();

        $this->postJson('/auth/login', ['email' => 'MARIA@example.com', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertExactJson(['guest' => ['name' => 'Maria Santos', 'email' => 'maria@example.com', 'avatar' => null, 'signInMethod' => 'password']]);

        $this->assertAuthenticatedAs($guest, 'guest');
        $this->assertNotSame($before, session()->getId());
        $this->assertNotNull($guest->fresh()->last_signed_in_at);
        $this->getJson('/api/my-bookings')->assertOk();
    }

    public function test_the_app_gets_a_token_and_no_session(): void
    {
        $guest = $this->account();

        $response = $this->postJson('/api/app/auth/login', ['email' => 'maria@example.com', 'password' => self::PASSWORD, 'device_name' => 'Pixel 8'])
            ->assertOk()
            ->assertJsonPath('guest.id', $guest->id)
            ->assertJsonPath('guest.signInMethod', 'password')
            ->assertJsonPath('guest.emailVerified', true)
            ->assertJsonMissingPath('guest.password')
            ->assertCookieMissing(config('session.cookie'));

        $token = $response->json('token');
        $this->assertSame('Pixel 8', GuestToken::query()->where('guest_id', $guest->id)->value('name'));
        $this->withToken($token)->getJson('/api/app/me')->assertOk()->assertJsonPath('email', 'maria@example.com');
        $this->assertStringNotContainsString($token, (string) GuestToken::query()->value('token_hash'));
    }

    public function test_every_failure_looks_the_same_and_google_accounts_cannot_use_a_password(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->account();
        $unverified = $this->account(['login_email' => 'late@example.com', 'email' => 'late@example.com', 'email_verified_at' => null]);
        Guest::factory()->create(['email' => 'google@example.com']);
        // A customer the team added, who even has an email on file
        Guest::factory()->walkIn()->create(['email' => 'walk@example.com']);
        // An admin user with a password is not a customer
        User::factory()->create(['email' => 'admin@example.com', 'password' => Hash::make(self::PASSWORD)]);

        $answers = collect([
            ['maria@example.com', 'wrong password 1'],
            ['nobody@example.com', self::PASSWORD],
            ['google@example.com', self::PASSWORD],
            ['walk@example.com', self::PASSWORD],
            ['admin@example.com', self::PASSWORD],
            ['late@example.com', 'wrong password 1'],
        ])->map(fn (array $try) => $this->postJson('/auth/login', ['email' => $try[0], 'password' => $try[1]]));

        foreach ($answers as $answer) {
            $answer->assertUnprocessable()->assertJsonPath('errors.email.0', 'Those details don’t match. Check your email and password.');
        }
        $this->assertSame(1, $answers->map(fn ($answer) => $answer->getContent())->unique()->count());
        $this->assertGuest('guest');

        // Only a right password may learn that the email still needs verifying
        $this->travel(2)->minutes();
        $this->postJson('/auth/login', ['email' => 'late@example.com', 'password' => self::PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('message', 'Verify your email first. Open the link we sent you.')
            ->assertJsonPath('code', 'email_unverified')
            ->assertJsonPath('canResend', true);
        $this->assertGuest('guest');
        $this->assertSame(0, GuestToken::query()->where('guest_id', $unverified->id)->count());
    }

    public function test_wrong_passwords_lock_the_sign_in_for_a_while_and_are_noted(): void
    {
        $guest = $this->account();

        foreach (range(1, 5) as $try) {
            $this->postJson('/api/app/auth/login', ['email' => 'maria@example.com', 'password' => "wrong pass {$try}1"])->assertUnprocessable();
        }

        // The right password is refused while locked, once the one-minute throttle has passed
        $this->travel(2)->minutes();
        $this->postJson('/api/app/auth/login', ['email' => 'maria@example.com', 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertJsonPath('code', 'locked')
            ->assertJsonPath('retryAfter', fn ($seconds) => $seconds > 0 && $seconds <= 900);

        $this->assertSame(4, SecurityEvent::query()->where('guest_id', $guest->id)->where('kind', SecurityEvent::CUSTOMER_SIGN_IN_FAILED)->count());
        $this->assertSame(1, SecurityEvent::query()->where('guest_id', $guest->id)->where('kind', SecurityEvent::CUSTOMER_LOCKED_OUT)->count());
        $this->assertStringNotContainsString('maria', (string) SecurityEvent::query()->where('kind', SecurityEvent::CUSTOMER_LOCKED_OUT)->value('note'));

        $this->travel(16)->minutes();
        $this->postJson('/api/app/auth/login', ['email' => 'maria@example.com', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_a_right_password_clears_earlier_mistakes(): void
    {
        $this->account();

        foreach (range(1, 3) as $try) {
            $this->postJson('/auth/login', ['email' => 'maria@example.com', 'password' => "wrong pass {$try}1"])->assertUnprocessable();
        }
        $this->postJson('/auth/login', ['email' => 'maria@example.com', 'password' => self::PASSWORD])->assertOk();
        $this->travel(2)->minutes();
        foreach (range(1, 4) as $try) {
            $this->postJson('/auth/login', ['email' => 'maria@example.com', 'password' => "wrong pass {$try}1"])->assertUnprocessable();
        }
        $this->postJson('/auth/login', ['email' => 'maria@example.com', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_sign_up_sign_in_and_forgot_are_throttled_per_visitor_and_per_email(): void
    {
        foreach (range(1, 5) as $try) {
            $this->postJson('/auth/login', ['email' => "nobody{$try}@example.com", 'password' => 'whatever 123'])->assertUnprocessable();
        }
        $this->postJson('/auth/login', ['email' => 'nobody6@example.com', 'password' => 'whatever 123'])->assertStatus(429);

        $this->travel(2)->minutes();
        // One email from many addresses
        foreach (range(1, 5) as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$ip}"])->postJson('/auth/forgot-password', ['email' => 'maria@example.com'])->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->postJson('/auth/forgot-password', ['email' => 'maria@example.com'])->assertStatus(429);

        foreach (range(1, 5) as $try) {
            $this->postJson('/auth/register', $this->signUpData(['email' => "r{$try}@example.com"]))->assertStatus(202);
        }
        $this->postJson('/auth/register', $this->signUpData(['email' => 'r6@example.com']))->assertStatus(429);
    }

    // Forgot and reset

    public function test_forgot_password_answers_the_same_and_emails_only_the_owner_of_an_account(): void
    {
        $guest = $this->account();
        Guest::factory()->create(['email' => 'google@example.com']);
        Guest::factory()->walkIn()->create(['email' => 'walk@example.com']);

        $bodies = collect(['maria@example.com', 'google@example.com', 'walk@example.com', 'nobody@example.com'])
            ->map(fn (string $email) => $this->postJson('/auth/forgot-password', ['email' => $email])->assertOk()->getContent());

        $this->assertSame(1, $bodies->unique()->count());
        $this->assertSame('If that email has an account, we sent a link to choose a new password.', json_decode($bodies[0], true)['message']);

        $reset = $this->sentTo('reset');
        $this->assertTrue($reset->hasTo('maria@example.com'));
        $this->assertStringContainsString('/reset-password/', $reset->url);
        $this->assertStringContainsString('email=maria%40example.com', $reset->url);
        // Google customers hear that they already have an account; walk-ins and strangers nothing
        Mail::assertSent(CustomerAccountMail::class, fn (CustomerAccountMail $mail) => $mail->kind === 'existing' && $mail->hasTo('google@example.com'));
        Mail::assertSent(CustomerAccountMail::class, 2);
        $this->assertSame($guest->id, $reset->guest->id);
    }

    private function resetToken(Guest $guest): string
    {
        return Password::broker('guests')->createToken($guest);
    }

    public function test_reset_sets_the_password_proves_the_email_and_ends_every_app_sign_in(): void
    {
        $guest = $this->account(['email_verified_at' => null]);
        GuestToken::issue($guest, 'Phone');
        GuestToken::issue($guest, 'Tablet');
        $remember = $guest->remember_token;
        $token = $this->resetToken($guest);

        $this->postJson('/api/app/auth/reset-password', ['email' => 'Maria@example.com', 'token' => $token, 'password' => 'brand new pass 77'])
            ->assertOk()->assertJsonPath('message', 'Your password is changed. Sign in with the new one.');

        $guest->refresh();
        $this->assertTrue(Hash::check('brand new pass 77', $guest->password));
        $this->assertNotNull($guest->email_verified_at);
        $this->assertSame(0, $guest->tokens()->count());
        $this->assertNotSame($remember, $guest->remember_token);
        $this->assertSame(1, SecurityEvent::query()->where('kind', SecurityEvent::CUSTOMER_PASSWORD_RESET)->count());

        $this->postJson('/auth/login', ['email' => 'maria@example.com', 'password' => self::PASSWORD])->assertUnprocessable();
        $this->postJson('/auth/login', ['email' => 'maria@example.com', 'password' => 'brand new pass 77'])->assertOk();

        // One use only
        $this->postJson('/auth/reset-password', ['email' => 'maria@example.com', 'token' => $token, 'password' => 'another pass 123'])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_reset_refuses_a_wrong_token_an_unknown_email_a_weak_password_and_an_old_link(): void
    {
        $guest = $this->account();
        $token = $this->resetToken($guest);

        $this->postJson('/auth/reset-password', ['email' => 'maria@example.com', 'token' => 'nope', 'password' => 'brand new pass 77'])->assertJsonValidationErrors('token');
        $this->postJson('/auth/reset-password', ['email' => 'other@example.com', 'token' => $token, 'password' => 'brand new pass 77'])->assertJsonValidationErrors('token');
        $this->postJson('/auth/reset-password', ['email' => 'maria@example.com', 'token' => $token, 'password' => 'weak'])->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check(self::PASSWORD, $guest->fresh()->password));

        $this->travel(61)->minutes();
        $this->postJson('/auth/reset-password', ['email' => 'maria@example.com', 'token' => $token, 'password' => 'brand new pass 77'])->assertJsonValidationErrors('token');
        $this->assertTrue(Hash::check(self::PASSWORD, $guest->fresh()->password));
    }

    public function test_a_reset_of_an_unproven_account_also_joins_a_walk_in(): void
    {
        $walkIn = Guest::factory()->walkIn()->create(['email' => 'maria@example.com']);
        $guest = $this->account(['email_verified_at' => null]);

        $this->postJson('/auth/reset-password', ['email' => 'maria@example.com', 'token' => $this->resetToken($guest), 'password' => 'brand new pass 77'])->assertOk();

        $this->assertNull(Guest::query()->find($guest->id));
        $this->assertTrue(Hash::check('brand new pass 77', $walkIn->fresh()->password));
        $this->assertNotNull($walkIn->fresh()->email_verified_at);
    }

    // After sign-in

    public function test_a_password_account_changes_its_password_with_the_current_one(): void
    {
        $guest = $this->account();
        $kept = GuestToken::issue($guest, 'This phone');
        GuestToken::issue($guest, 'Old tablet');

        $this->withToken($kept)->patchJson('/api/app/me', ['password' => 'a brand new pass 5'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->withToken($kept)->patchJson('/api/app/me', ['current_password' => 'wrong wrong 123', 'password' => 'a brand new pass 5'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->withToken($kept)->patchJson('/api/app/me', ['current_password' => self::PASSWORD, 'password' => 'short1'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame(2, $guest->tokens()->count());

        $this->withToken($kept)->patchJson('/api/app/me', ['current_password' => self::PASSWORD, 'password' => 'a brand new pass 5'])->assertOk();

        $this->assertTrue(Hash::check('a brand new pass 5', $guest->fresh()->password));
        $this->assertSame(1, $guest->tokens()->count());
        $this->withToken($kept)->getJson('/api/app/me')->assertOk();
    }

    public function test_a_google_customer_cannot_set_a_password_through_the_profile(): void
    {
        $guest = Guest::factory()->create();

        $this->withToken(GuestToken::issue($guest, 'Phone'))->patchJson('/api/app/me', ['current_password' => 'anything goes 1', 'password' => 'a brand new pass 5'])->assertUnprocessable();
        $this->assertNull($guest->fresh()->password);
    }

    public function test_deleting_the_account_erases_the_password_and_frees_the_email(): void
    {
        $guest = $this->account();
        $token = GuestToken::issue($guest, 'Phone');

        $this->withToken($token)->postJson('/api/app/account/delete')->assertOk();

        $guest->refresh();
        $this->assertNull($guest->password);
        $this->assertNull($guest->login_email);
        $this->assertNull($guest->email);
        $this->postJson('/auth/login', ['email' => 'maria@example.com', 'password' => self::PASSWORD])->assertUnprocessable();
        $this->postJson('/auth/register', $this->signUpData())->assertStatus(202);
        $this->assertSame(1, Guest::query()->where('login_email', 'maria@example.com')->count());
    }

    public function test_the_password_never_leaves_the_server(): void
    {
        $guest = $this->account();

        $this->assertArrayNotHasKey('password', $guest->toArray());
        $this->assertArrayNotHasKey('login_email', $guest->toArray());
        $this->assertArrayNotHasKey('password', $guest->toApp());
        $this->assertArrayNotHasKey('password', $guest->toSite());
        $this->assertStringNotContainsString($guest->getRawOriginal('password'), json_encode($guest->toApp()).json_encode($guest->toSite()));
    }

    public function test_the_account_pages_open_and_the_site_knows_the_new_sign_in(): void
    {
        foreach (['/login', '/register', '/forgot-password', '/reset-password/abc123?email=maria%40example.com', '/verify-email', '/verify-email?status=verified'] as $path) {
            $this->get($path)->assertOk();
        }

        Auth::guard('guest')->login($this->account());
        $this->assertSame('password', $this->get('/')->viewData('app')['auth']['guest']['signInMethod']);
    }
}
