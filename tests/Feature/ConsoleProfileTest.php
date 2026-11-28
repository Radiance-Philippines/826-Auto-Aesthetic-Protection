<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\Branch;
use App\Models\SecurityEvent;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Support\Impersonation;
use App\Support\TwoFactor;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Spatie\LaravelPasskeys\Models\Passkey;
use Tests\Support\SoftwareAuthenticator;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's profile page: the panels' rules for changing how a
 * person signs in, and the same stored data on both sides.
 */
class ConsoleProfileTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const PASSWORD = 'a-long-test-password';

    private const API = '/console/api/operations';

    private Branch $cainta;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['auth.timebox_duration' => 1000, 'passkeys.relying_party.id' => 'localhost']);
        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
        $this->user = $this->staffUser('operations', $this->cainta, ['email' => 'dennis@example.com', 'password' => self::PASSWORD]);
    }

    /** Sends the session cookie back, as a browser does: an emailed code is kept with the session */
    private function sameBrowser(): static
    {
        return $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    }

    private function profile(string $method = 'GET', string $path = '', array $data = []): TestResponse
    {
        return $this->json($method, self::API.'/profile'.$path, $data);
    }

    /** The code an authenticator would show; $ahead steps later when the current one is already used */
    private function totp(string $secret, int $ahead = 0): string
    {
        $google2fa = app(Google2FA::class);

        return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $ahead);
    }

    private function setUpApp(): array
    {
        $secret = $this->profile('POST', '/two-factor/app')->assertOk()->json('secret');
        $codes = $this->profile('POST', '/two-factor/app/confirm', ['code' => $this->totp($secret), 'current_password' => self::PASSWORD])->assertOk()->json('recoveryCodes');

        return [$secret, $codes];
    }

    private function signOut(): void
    {
        $this->post('/console/logout');
        Auth::forgetGuards();
        $this->travel(2)->minutes();
    }

    private function signIn(string $password = self::PASSWORD): TestResponse
    {
        return $this->postJson('/console/login', ['email' => 'dennis@example.com', 'password' => $password]);
    }

    private function code(string $code): TestResponse
    {
        return $this->postJson('/console/login/two-factor', ['method' => 'app', 'code' => $code]);
    }

    public function test_what_is_set_up_here_works_in_the_current_admin(): void
    {
        $this->actingAs($this->user);
        [$secret, $codes] = $this->setUpApp();
        $user = $this->user->fresh();
        $filament = AppAuthentication::make();

        $this->assertSame($secret, $user->app_authentication_secret);
        $this->assertTrue($filament->isEnabled($user));
        $this->assertTrue($filament->verifyCode($this->totp($secret, 1), $secret));
        $this->assertTrue($filament->verifyRecoveryCode($codes[0], $user));
    }

    public function test_a_passkey_added_in_the_current_admin_is_listed_here(): void
    {
        $this->actingAs($this->user);
        $device = new SoftwareAuthenticator;
        $options = null;
        $page = Livewire::test(EditProfile::class);
        $page->call('passkeyOptions')->assertReturned(function (string $json) use (&$options) {
            $options = json_decode($json, true);

            return true;
        });
        $page->call('storePasskey', json_encode($device->register($options)), 'Old laptop');

        $this->profile()->assertJsonPath('passkeys.items.0.name', 'Old laptop')->assertJsonCount(1, 'passkeys.ids');
    }

    public function test_the_page_and_its_data_open_for_every_role(): void
    {
        foreach (['super_admin' => 'owner', 'sales_admin' => 'sales', 'branch_manager' => 'operations'] as $role => $panel) {
            $user = $this->staffUser($role, $this->cainta);

            $this->actingAs($user)->get("/console/{$panel}/profile")->assertOk();
            $this->getJson("/console/api/{$panel}/profile")->assertOk()->assertJsonPath('email', $user->email)->assertJsonPath('setupRequired', false);
            $this->getJson("/console/api/{$panel}/me")->assertJsonPath('links.profile', url("/console/{$panel}/profile"));
        }
    }

    public function test_the_data_never_carries_secrets(): void
    {
        $this->actingAs($this->user);
        [$secret] = $this->setUpApp();

        $body = $this->profile()->assertOk()->getContent();

        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString($this->user->fresh()->password, $body);
        $this->assertStringNotContainsString($this->user->fresh()->app_authentication_recovery_codes[0], $body);
    }

    public function test_a_signed_out_person_gets_nothing(): void
    {
        $this->profile()->assertUnauthorized();
        $this->profile('PATCH', '', ['name' => 'X', 'email' => 'x@example.com'])->assertUnauthorized();
        $this->get('/console/operations/profile')->assertRedirect(route('console.login'));
    }

    public function test_the_name_changes_without_the_password_but_the_email_and_password_do_not(): void
    {
        $this->actingAs($this->user);

        $this->profile('PATCH', '', ['name' => 'Dennis Reyes', 'email' => 'dennis@example.com'])->assertOk()->assertJsonPath('name', 'Dennis Reyes');

        $this->profile('PATCH', '', ['name' => 'Dennis', 'email' => 'new@example.com'])->assertJsonValidationErrors('current_password');
        $this->profile('PATCH', '', ['name' => 'Dennis', 'email' => 'new@example.com', 'current_password' => 'wrong'])->assertJsonValidationErrors('current_password');
        $this->profile('PATCH', '', ['name' => 'Dennis', 'email' => 'dennis@example.com', 'password' => 'another-long-one', 'password_confirmation' => 'another-long-one'])->assertJsonValidationErrors('current_password');
        $this->assertSame('dennis@example.com', $this->user->fresh()->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $this->user->fresh()->password));
    }

    public function test_the_email_must_be_free_and_the_password_long_enough_and_typed_twice(): void
    {
        $this->actingAs($this->user);
        $other = User::factory()->create();
        $base = ['name' => 'Dennis', 'email' => 'dennis@example.com', 'current_password' => self::PASSWORD];

        $this->profile('PATCH', '', ['email' => $other->email] + $base)->assertJsonValidationErrors('email');
        $this->profile('PATCH', '', $base + ['password' => 'short', 'password_confirmation' => 'short'])->assertJsonValidationErrors('password');
        $this->profile('PATCH', '', $base + ['password' => 'long-enough-here', 'password_confirmation' => 'something-else'])->assertJsonValidationErrors('password');
    }

    public function test_a_new_password_keeps_this_session_and_ends_the_old_password(): void
    {
        $this->signIn()->assertOk();
        $this->get('/console/operations')->assertOk();

        $this->profile('PATCH', '', ['name' => 'Dennis', 'email' => 'dennis@example.com', 'password' => 'another-long-one', 'password_confirmation' => 'another-long-one', 'current_password' => self::PASSWORD])->assertOk();

        Auth::forgetGuards();
        $this->profile()->assertOk();

        $this->signOut();
        $this->signIn()->assertUnprocessable();
        $this->signIn('another-long-one')->assertOk();
    }

    public function test_password_checks_have_a_small_budget(): void
    {
        $this->actingAs($this->user);

        foreach (range(1, 5) as $try) {
            $this->profile('PATCH', '', ['name' => 'Dennis', 'email' => 'new@example.com', 'current_password' => 'wrong'])->assertUnprocessable();
        }

        // One budget for everything that checks a password or a code, whichever action
        $this->profile('POST', '/two-factor/recovery-codes', ['current_password' => 'wrong'])->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->profile('PATCH', '', ['name' => 'Dennis', 'email' => 'new@example.com', 'current_password' => self::PASSWORD])->assertTooManyRequests();
    }

    public function test_the_authenticator_is_only_saved_after_its_first_code_and_the_password(): void
    {
        $this->actingAs($this->user);

        $start = $this->profile('POST', '/two-factor/app')->assertOk();
        $secret = $start->json('secret');
        $this->assertStringStartsWith('data:image/', $start->json('qr'));
        $this->assertNull($this->user->fresh()->app_authentication_secret);

        $good = $this->totp($secret);
        $this->profile('POST', '/two-factor/app/confirm', ['code' => $good, 'current_password' => 'wrong'])->assertJsonValidationErrors('current_password');
        $this->profile('POST', '/two-factor/app/confirm', ['code' => $good === '000000' ? '111111' : '000000', 'current_password' => self::PASSWORD])->assertJsonValidationErrors('code');
        $this->assertNull($this->user->fresh()->app_authentication_secret);

        $confirmed = $this->profile('POST', '/two-factor/app/confirm', ['code' => $good, 'current_password' => self::PASSWORD])->assertOk()->assertJsonPath('twoFactor.app', true);

        $this->assertCount(8, $confirmed->json('recoveryCodes'));
        $this->assertSame($secret, $this->user->fresh()->app_authentication_secret);
        $this->assertTrue(Hash::check($confirmed->json('recoveryCodes.0'), $this->user->fresh()->app_authentication_recovery_codes[0]));
        $this->profile('POST', '/two-factor/app')->assertStatus(409);

        // The code that set it up is spent: it does not also sign in
        $this->signOut();
        $this->travelBack();
        $this->signIn();
        $this->code($good)->assertUnprocessable();
        $this->code($this->totp($secret, 1))->assertOk();
    }

    public function test_a_set_up_left_too_long_or_never_started_is_refused(): void
    {
        $this->actingAs($this->user);
        $this->profile('POST', '/two-factor/app/confirm', ['code' => '123456', 'current_password' => self::PASSWORD])->assertJsonValidationErrors('code');

        $secret = $this->profile('POST', '/two-factor/app')->json('secret');
        $this->travel(16)->minutes();

        $this->profile('POST', '/two-factor/app/confirm', ['code' => $this->totp($secret), 'current_password' => self::PASSWORD])->assertJsonValidationErrors('code');
        $this->assertNull($this->user->fresh()->app_authentication_secret);
    }

    public function test_turning_the_authenticator_off_needs_the_password_and_a_code(): void
    {
        $this->actingAs($this->user);
        [$secret, $codes] = $this->setUpApp();
        $this->travel(2)->minutes();

        $this->profile('DELETE', '/two-factor/app', ['code' => $codes[0]])->assertJsonValidationErrors('current_password');
        $this->profile('DELETE', '/two-factor/app', ['current_password' => self::PASSWORD])->assertJsonValidationErrors('code');
        $this->profile('DELETE', '/two-factor/app', ['current_password' => self::PASSWORD, 'code' => 'not-a-code'])->assertJsonValidationErrors('code');
        $this->assertSame($secret, $this->user->fresh()->app_authentication_secret);

        $this->profile('DELETE', '/two-factor/app', ['current_password' => self::PASSWORD, 'code' => $codes[0]])->assertOk()->assertJsonPath('twoFactor.app', false);

        $this->assertNull($this->user->fresh()->app_authentication_secret);
        $this->assertNull($this->user->fresh()->app_authentication_recovery_codes);
    }

    public function test_new_recovery_codes_need_the_password_and_replace_the_old(): void
    {
        $this->actingAs($this->user);
        [, $old] = $this->setUpApp();

        $this->profile('POST', '/two-factor/recovery-codes')->assertJsonValidationErrors('current_password');
        $new = $this->profile('POST', '/two-factor/recovery-codes', ['current_password' => self::PASSWORD])->assertOk()->json('recoveryCodes');

        $this->assertCount(8, $new);
        $this->assertFalse(TwoFactor::passes($this->user->fresh(), $old[0]));
        $this->assertTrue(TwoFactor::passes($this->user->fresh(), $new[0]));
    }

    public function test_emailed_codes_turn_on_and_off_with_a_code_from_the_mailbox(): void
    {
        Notification::fake();
        $this->actingAs($this->user);

        $this->profile('POST', '/two-factor/email/code')->assertExactJson(['sent' => true]);
        $this->sameBrowser()->profile('POST', '/two-factor/email', ['code' => 'nope'])->assertJsonValidationErrors('code');
        $code = Notification::sent($this->user, VerifyEmailAuthentication::class)->first()->code;
        $this->sameBrowser()->profile('POST', '/two-factor/email', ['code' => $code])->assertOk()->assertJsonPath('twoFactor.email', true);

        $this->sameBrowser()->profile('DELETE', '/two-factor/email', ['code' => $code])->assertJsonValidationErrors('code');
        $this->sameBrowser()->profile('POST', '/two-factor/email/code')->assertExactJson(['sent' => true]);
        $second = Notification::sent($this->user, VerifyEmailAuthentication::class)->last()->code;
        $this->sameBrowser()->profile('DELETE', '/two-factor/email', ['code' => $second])->assertOk()->assertJsonPath('twoFactor.email', false);
    }

    public function test_passkeys_are_added_renamed_and_removed_and_it_is_recorded(): void
    {
        $this->actingAs($this->user);
        $device = new SoftwareAuthenticator;

        $options = $this->profile('POST', '/passkeys/options')->assertOk()->json();
        $id = $this->profile('POST', '/passkeys', ['credential' => json_encode($device->register($options)), 'name' => ' Work <b>laptop</b> '])
            ->assertOk()
            ->assertJsonPath('passkeys.items.0.name', 'Work laptop')
            ->assertJsonPath('events.0.label', 'Passkey added')
            ->json('passkeys.items.0.id');

        $this->profile('PATCH', "/passkeys/{$id}", ['name' => ''])->assertJsonValidationErrors('name');
        $this->profile('PATCH', "/passkeys/{$id}", ['name' => 'Phone'])->assertJsonPath('passkeys.items.0.name', 'Phone');
        $this->profile('DELETE', "/passkeys/{$id}")->assertOk()->assertJsonCount(0, 'passkeys.items')->assertJsonPath('events.0.label', 'Passkey removed');

        $this->assertSame(0, Passkey::count());
        $this->assertSame(1, SecurityEvent::where('kind', SecurityEvent::PASSKEY_ADDED)->count());
        $this->assertSame(1, SecurityEvent::where('kind', SecurityEvent::PASSKEY_REMOVED)->count());
    }

    public function test_a_passkey_answer_without_a_challenge_or_a_wrong_one_is_refused(): void
    {
        $this->actingAs($this->user);
        $device = new SoftwareAuthenticator;
        $options = $this->profile('POST', '/passkeys/options')->json();
        $answer = json_encode($device->register($options));

        $this->profile('POST', '/passkeys/options');
        $this->profile('POST', '/passkeys', ['credential' => $answer])->assertJsonValidationErrors('credential');
        $this->profile('POST', '/passkeys', ['credential' => $answer])->assertJsonValidationErrors('credential');

        $this->assertSame(0, Passkey::count());
    }

    public function test_nobody_touches_another_persons_passkey(): void
    {
        $other = $this->staffUser('operations', $this->cainta);
        $this->actingAs($other);
        $options = $this->profile('POST', '/passkeys/options')->json();
        $id = $this->profile('POST', '/passkeys', ['credential' => json_encode((new SoftwareAuthenticator)->register($options))])->json('passkeys.items.0.id');

        // Another browser: the session still holds the other person's password hash
        session()->flush();
        $this->actingAs($this->user);
        $this->profile('PATCH', "/passkeys/{$id}", ['name' => 'Mine now'])->assertNotFound();
        $this->profile('DELETE', "/passkeys/{$id}")->assertNotFound();

        $this->assertSame(1, Passkey::count());
        $this->assertNotSame('Mine now', Passkey::first()->name);
    }

    public function test_no_new_passkeys_while_passkey_sign_in_is_off(): void
    {
        SecuritySetting::instance()->update(['allow_passkeys' => false]);
        $this->actingAs($this->user);

        $this->profile()->assertJsonPath('passkeys.allowed', false);
        $this->profile('POST', '/passkeys/options')->assertForbidden();
        $this->profile('POST', '/passkeys', ['credential' => '{}'])->assertForbidden();
    }

    #[DataProvider('actions')]
    public function test_nothing_while_viewing_as_someone_else(string $method, string $path): void
    {
        $admin = $this->staffUser('super_admin', $this->cainta);

        $this->actingAs($this->user)
            ->withSession([Impersonation::IMPERSONATOR => $admin->id, Impersonation::TARGET => $this->user->id, Impersonation::PANEL => 'owner'])
            ->profile($method, $path, ['name' => 'X', 'email' => 'x@example.com', 'code' => '123456', 'current_password' => self::PASSWORD, 'credential' => '{}'])
            ->assertForbidden();

        $this->assertSame('dennis@example.com', $this->user->fresh()->email);
        $this->get('/console/operations/profile')->assertForbidden();
        $this->getJson(self::API.'/me')->assertJsonPath('links.profile', null);
    }

    /**
     * Every profile endpoint.
     *
     * @return array<string, array{string, string}>
     */
    public static function actions(): array
    {
        return [
            'read' => ['GET', ''],
            'update' => ['PATCH', ''],
            'start the app' => ['POST', '/two-factor/app'],
            'confirm the app' => ['POST', '/two-factor/app/confirm'],
            'app off' => ['DELETE', '/two-factor/app'],
            'recovery codes' => ['POST', '/two-factor/recovery-codes'],
            'email a code' => ['POST', '/two-factor/email/code'],
            'email on' => ['POST', '/two-factor/email'],
            'email off' => ['DELETE', '/two-factor/email'],
            'passkey options' => ['POST', '/passkeys/options'],
            'passkey add' => ['POST', '/passkeys'],
            'passkey rename' => ['PATCH', '/passkeys/1'],
            'passkey remove' => ['DELETE', '/passkeys/1'],
        ];
    }

    #[DataProvider('actions')]
    public function test_every_change_needs_the_csrf_token(string $method, string $path): void
    {
        $this->actingAs($this->user);
        // The check is off while the environment is "testing"
        $this->app['env'] = 'local';

        $this->profile($method, $path)->assertStatus($method === 'GET' ? 200 : 419);
    }

    public function test_required_two_factor_leads_to_this_page_and_only_setting_it_up_is_open(): void
    {
        SecuritySetting::instance()->update(['require_two_factor' => true]);
        $setUp = url('/console/operations/profile');
        $this->signIn()->assertOk();

        $this->get('/console/operations')->assertRedirect($setUp);
        $this->get('/console/operations/queue')->assertRedirect($setUp);
        $this->getJson(self::API.'/queue')->assertForbidden()->assertJsonPath('redirect', $setUp);
        $this->get($setUp)->assertOk();
        $this->profile()->assertOk()->assertJsonPath('setupRequired', true);
        // The shell around it loads, with nothing of the business in it
        $this->getJson(self::API.'/me')->assertOk()
            ->assertJsonPath('setupRequired', true)
            ->assertJsonPath('navigation', [])
            ->assertJsonPath('launcher', [])
            ->assertJsonPath('branches.options', [])
            ->assertJsonPath('live', null);

        foreach ([['PATCH', ''], ['DELETE', '/two-factor/app'], ['POST', '/two-factor/recovery-codes'], ['DELETE', '/two-factor/email'], ['POST', '/passkeys/options'], ['POST', '/passkeys'], ['DELETE', '/passkeys/1']] as [$method, $path]) {
            $this->profile($method, $path)->assertForbidden()->assertJsonPath('redirect', $setUp);
        }

        $this->setUpApp();

        $this->getJson(self::API.'/me')->assertJsonPath('setupRequired', false);
        $this->get('/console/operations/queue')->assertOk();
        $this->profile()->assertJsonPath('setupRequired', false);
    }

    public function test_required_two_factor_can_be_met_with_emailed_codes(): void
    {
        Notification::fake();
        SecuritySetting::instance()->update(['require_two_factor' => true]);
        $this->actingAs($this->user);

        $this->profile('POST', '/two-factor/email/code')->assertOk();
        $this->sameBrowser()->profile('POST', '/two-factor/email', ['code' => Notification::sent($this->user, VerifyEmailAuthentication::class)->first()->code])
            ->assertOk()
            ->assertJsonPath('setupRequired', false);
    }
}
