<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Http\Middleware\RequireTwoFactorWhenOn;
use App\Models\Branch;
use App\Models\SecurityEvent;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Support\TwoFactor;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Spatie\LaravelPasskeys\Models\Passkey;
use Tests\Support\SoftwareAuthenticator;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's own sign-in, forgot-password and reset pages: the
 * panels' rules for who gets in, and no way round the second factor.
 */
class ConsoleAuthTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const PASSWORD = 'a-long-test-password';

    private const REFUSED = 'These credentials do not match our records.';

    private const DEAD_LINK = 'This link is invalid or has expired. Ask for a new one.';

    private Branch $cainta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['auth.timebox_duration' => 1000, 'passkeys.relying_party.id' => 'localhost']);
        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
    }

    private function member(string $role = 'super_admin', array $state = []): User
    {
        return $this->staffUser($role, $this->cainta, ['email' => 'dennis@example.com', 'password' => self::PASSWORD, ...$state]);
    }

    private function withApp(User $user): User
    {
        $user->saveAppAuthenticationSecret(AppAuthentication::make()->generateSecret());

        return $user;
    }

    /** Sends the session cookie back, as a browser does (the test client does not by itself) */
    private function sameBrowser(): static
    {
        return $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    }

    private function password(string $password = self::PASSWORD, string $email = 'dennis@example.com', array $more = []): TestResponse
    {
        return $this->postJson('/console/login', ['email' => $email, 'password' => $password, ...$more]);
    }

    private function code(string $code, string $method = 'app'): TestResponse
    {
        return $this->postJson('/console/login/two-factor', ['method' => $method, 'code' => $code]);
    }

    private function currentCode(User $user): string
    {
        return AppAuthentication::make()->getCurrentCode($user->fresh());
    }

    /** Nothing that needs a sign-in answers, however it is asked */
    private function assertSignedOut(): void
    {
        Auth::forgetGuards();
        $this->assertGuest('web');
        $this->getJson('/console/api/owner/me')->assertUnauthorized();
        $this->get('/console')->assertRedirect(route('console.login'));
        $this->get('/console/owner/users')->assertRedirect(route('console.login'));
        $this->get('/owner/profile')->assertRedirect();
        $this->assertGuest('web');
    }

    public function test_the_page_is_served_with_what_it_needs_and_nothing_cached(): void
    {
        $response = $this->get('/console/login')->assertOk()
            ->assertSee('"screen":"sign-in"', false)
            ->assertSee('"passkeys":true', false)
            ->assertSee('"twoFactor":null', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        SecuritySetting::instance()->update(['allow_passkeys' => false]);
        $this->get('/console/login')->assertSee('"passkeys":false', false);

        // The panels' own page stays
        $this->get('/admin/login')->assertOk();
    }

    #[DataProvider('roles')]
    public function test_each_role_signs_in_and_opens_the_console(string $role, string $panel): void
    {
        $user = $this->member($role);
        $this->get('/console/login');
        $before = session()->getId();
        $this->sameBrowser()->get('/console/login');
        $this->assertSame($before, session()->getId());

        $this->sameBrowser()->password()->assertOk()->assertExactJson(['redirect' => route('console.home')]);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($before, session()->getId());
        $this->get('/console')->assertRedirect(url("/console/{$panel}"));
        $this->get('/console/login')->assertRedirect(route('console.home'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function roles(): array
    {
        return [
            'super admin' => ['super_admin', 'owner'],
            'sales admin' => ['sales_admin', 'sales'],
            'operations' => ['operations', 'operations'],
            'branch manager' => ['branch_manager', 'operations'],
        ];
    }

    public function test_remember_me_sets_the_long_cookie_only_when_ticked(): void
    {
        $this->member();

        $cookie = Auth::guard('web')->getRecallerName();
        $this->password()->assertCookieMissing($cookie);
        $this->post('/console/logout');
        $this->password(more: ['remember' => true])->assertCookie($cookie);
    }

    public function test_the_page_the_person_asked_for_opens_after_sign_in(): void
    {
        $this->member();

        $this->get('/console/operations/queue?date=2026-11-14')->assertRedirect(route('console.login'));

        $this->password()->assertJsonPath('redirect', url('/console/operations/queue?date=2026-11-14'));
    }

    #[DataProvider('elsewhere')]
    public function test_a_redirect_off_this_app_is_refused(string $intended): void
    {
        $this->member();

        $this->withSession(['url.intended' => $intended])->password()->assertJsonPath('redirect', route('console.home'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function elsewhere(): array
    {
        return [
            'another site' => ['https://evil.example/console'],
            'a look-alike host' => ['http://localhost.evil.example/console'],
            'a user part' => ['http://localhost@evil.example/'],
            'no scheme' => ['//evil.example/'],
            'a script address' => ['javascript:alert(1)'],
        ];
    }

    public function test_a_wrong_password_and_an_unknown_email_read_the_same(): void
    {
        $this->member();
        Event::fake([Failed::class]);

        $wrong = $this->password('not the password')->assertUnprocessable()->assertJsonPath('errors.email.0', self::REFUSED);
        $unknown = $this->password(email: 'nobody@example.com')->assertUnprocessable();

        $this->assertSame($wrong->json(), $unknown->json());
        Event::assertDispatchedTimes(Failed::class, 2);
        $this->assertSignedOut();
    }

    public function test_an_account_without_a_panel_is_refused_like_a_wrong_password(): void
    {
        $this->withApp($this->member('former_staff'));

        $this->password()->assertUnprocessable()->assertJsonPath('errors.email.0', self::REFUSED)->assertJsonMissingPath('twoFactor');

        $this->assertNull(session('console.two_factor'));
        $this->assertGuest('web');
    }

    public function test_password_tries_are_limited_per_visitor(): void
    {
        $this->member();

        foreach (range(1, 5) as $try) {
            $this->password('wrong', "nobody{$try}@example.com")->assertUnprocessable();
        }

        $this->password()->assertTooManyRequests();
        $this->assertGuest('web');
    }

    public function test_password_tries_are_limited_per_email_whoever_sends_them(): void
    {
        $this->member();

        foreach (range(1, 5) as $try) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$try}"])->password('wrong')->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->password(email: 'DENNIS@example.com')->assertTooManyRequests();
    }

    public function test_the_password_alone_does_not_sign_in_an_account_with_an_authenticator(): void
    {
        $user = $this->withApp($this->member());
        $this->get('/console/login');
        $before = session()->getId();

        $this->sameBrowser()->password()->assertOk()->assertExactJson(['twoFactor' => ['methods' => ['app'], 'sent' => null]]);

        $this->assertNotSame($before, session()->getId());
        $this->sameBrowser()->assertSignedOut();
        // A reload keeps the step
        $this->sameBrowser()->get('/console/login')->assertSee('"twoFactor":{"methods":["app"],"sent":null}', false);

        $good = $this->currentCode($user);
        $this->sameBrowser()->code($good === '000000' ? '111111' : '000000')->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->sameBrowser()->assertSignedOut();

        $halfway = session()->getId();
        $this->sameBrowser()->code($this->currentCode($user))->assertOk()->assertJsonStructure(['redirect']);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($halfway, session()->getId());
        $this->assertNull(session('console.two_factor'));
    }

    public function test_remember_me_and_the_asked_for_page_survive_the_second_step(): void
    {
        $user = $this->withApp($this->member());
        $this->get('/console/owner/users');

        $this->password(more: ['remember' => true]);

        $this->code($this->currentCode($user))
            ->assertJsonPath('redirect', url('/console/owner/users'))
            ->assertCookie(Auth::guard('web')->getRecallerName());
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        $user = $this->withApp($this->member());
        AppAuthentication::make()->saveRecoveryCodes($user, ['aaaaaaaaaa-bbbbbbbbbb', 'cccccccccc-dddddddddd']);

        $this->password();
        $this->code(' aaaaaaaaaa-bbbbbbbbbb ')->assertOk();
        $this->assertCount(1, $user->fresh()->app_authentication_recovery_codes);

        $this->post('/console/logout');
        Auth::forgetGuards();

        $this->password();
        $this->code('aaaaaaaaaa-bbbbbbbbbb')->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_the_second_step_needs_the_first(): void
    {
        $user = $this->withApp($this->member());

        $this->code($this->currentCode($user))->assertUnprocessable()->assertJsonValidationErrors('restart');
        $this->postJson('/console/login/two-factor/email')->assertUnprocessable()->assertJsonValidationErrors('restart');

        $this->assertGuest('web');
    }

    #[DataProvider('staleSteps')]
    public function test_the_second_step_starts_again_when_the_first_no_longer_holds(string $change): void
    {
        $user = $this->withApp($this->member());
        $this->password();
        $code = $this->currentCode($user);

        match ($change) {
            'time' => $this->travel(11)->minutes(),
            'password' => $user->forceFill(['password' => 'another-long-password'])->save(),
            'access' => $user->forceFill(['role' => 'former_staff'])->save(),
            'cancel' => $this->deleteJson('/console/login/two-factor')->assertNoContent(),
        };

        $this->code($code)->assertUnprocessable()->assertJsonValidationErrors('restart');
        $this->assertGuest('web');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function staleSteps(): array
    {
        return ['ten minutes passed' => ['time'], 'the password changed' => ['password'], 'access was taken away' => ['access'], 'the person went back' => ['cancel']];
    }

    public function test_code_tries_are_limited_per_visitor_and_per_account(): void
    {
        $user = $this->withApp($this->member());
        $good = $this->currentCode($user);
        $wrong = $good === '000000' ? '111111' : '000000';
        $this->password();

        foreach (range(1, 5) as $try) {
            $this->code($wrong)->assertUnprocessable();
        }

        $this->code($good)->assertTooManyRequests();
        // Another address: the account's own count still holds
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->code($good)->assertTooManyRequests();
        $this->assertGuest('web');
    }

    public function test_only_a_code_from_the_last_half_minute_or_so_counts(): void
    {
        $user = $this->withApp($this->member());
        $google2fa = app(Google2FA::class);
        $codeAt = fn (int $stepsAway) => $google2fa->oathTotp($user->fresh()->app_authentication_secret, $google2fa->getTimestamp() + $stepsAway);

        $this->password()->assertOk();
        $this->code($codeAt(-3))->assertUnprocessable();
        $this->code($codeAt(-1))->assertOk();
    }

    public function test_an_account_takes_only_so_many_wrong_codes_in_a_day(): void
    {
        $user = $this->withApp($this->member());

        foreach (range(1, TwoFactor::WRONG_CODES_A_DAY) as $try) {
            TwoFactor::countWrongCode($user);
        }

        $this->password()->assertOk();
        $this->code($this->currentCode($user))->assertStatus(429)->assertJsonPath('message', TwoFactor::DAY_LIMIT_MESSAGE);
        $this->assertSignedOut();

        $this->travel(25)->hours();
        $this->password()->assertOk();
        $this->code($this->currentCode($user))->assertOk();
    }

    public function test_a_wrong_code_counts_toward_the_days_limit(): void
    {
        $user = $this->withApp($this->member());

        foreach (range(1, TwoFactor::WRONG_CODES_A_DAY - 1) as $try) {
            TwoFactor::countWrongCode($user);
        }

        $this->password()->assertOk();
        $this->code('000000')->assertUnprocessable();
        $this->assertTrue(TwoFactor::dayLimitReached($user));
    }

    public function test_one_visitor_gets_only_so_many_sign_in_tries_for_an_address_in_a_day(): void
    {
        $this->member();

        foreach (range(1, 30) as $try) {
            // Past each minute's budget
            $this->travel(61)->seconds();
            $this->password('wrong-password')->assertUnprocessable();
        }

        $this->travel(61)->seconds();
        $this->password()->assertStatus(429);
    }

    public function test_an_account_with_emailed_codes_gets_one_and_signs_in_with_it(): void
    {
        Notification::fake();
        $user = $this->member();
        $user->toggleEmailAuthentication(true);

        $this->password()->assertExactJson(['twoFactor' => ['methods' => ['email'], 'sent' => true]]);
        // Filament keeps an emailed code with the session that asked for it
        $this->sameBrowser();
        $this->assertSignedOut();

        // A method the account does not have is no way in
        $this->sameBrowser()->code('123456')->assertUnprocessable()->assertJsonValidationErrors('method');

        $sent = Notification::sent($user, VerifyEmailAuthentication::class);
        $this->assertCount(1, $sent);

        $this->sameBrowser()->code($sent->first()->code, 'email')->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_emailed_codes_can_be_sent_again_but_not_without_end(): void
    {
        Notification::fake();
        $user = $this->member();
        $user->toggleEmailAuthentication(true);
        $this->password();

        $this->postJson('/console/login/two-factor/email')->assertExactJson(['sent' => true]);
        // The panels' own limit: two a minute
        $this->postJson('/console/login/two-factor/email')->assertExactJson(['sent' => false]);

        Notification::assertSentToTimes($user, VerifyEmailAuthentication::class, 2);
    }

    public function test_both_factors_are_offered_and_the_email_only_goes_when_asked(): void
    {
        Notification::fake();
        $user = $this->withApp($this->member());
        $user->toggleEmailAuthentication(true);

        $this->password()->assertExactJson(['twoFactor' => ['methods' => ['app', 'email'], 'sent' => null]]);
        Notification::assertNothingSent();

        $this->postJson('/console/login/two-factor/email')->assertExactJson(['sent' => true]);
    }

    public function test_no_emailed_code_for_an_account_that_does_not_use_them(): void
    {
        Notification::fake();
        $this->withApp($this->member());
        $this->password();

        $this->postJson('/console/login/two-factor/email')->assertNotFound();

        Notification::assertNothingSent();
    }

    public function test_a_password_sign_in_never_counts_as_a_passkey_one(): void
    {
        $this->member();

        $this->withSession([RequireTwoFactorWhenOn::VIA_PASSKEY => true])->password()->assertOk();

        $this->assertNull(session(RequireTwoFactorWhenOn::VIA_PASSKEY));
    }

    public function test_a_passkey_signs_in_to_the_console(): void
    {
        $user = $this->member();
        $device = new SoftwareAuthenticator;
        $this->actingAs($user);

        $options = null;
        $page = Livewire::test(EditProfile::class);
        $page->call('passkeyOptions')->assertReturned(function (string $json) use (&$options) {
            $options = json_decode($json, true);

            return true;
        });
        $page->call('storePasskey', json_encode($device->register($options)), 'Laptop');
        $this->assertSame(1, Passkey::count());

        Auth::logout();
        session()->flush();

        $assertion = $device->assert($this->getJson('/admin-passkeys/options')->json(), $user->getPassKeyId());

        $this->postJson('/admin-passkeys/login', ['credential' => json_encode($assertion), 'console' => true])
            ->assertOk()
            ->assertJsonPath('redirect', route('console.home'));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(1, SecurityEvent::where('kind', SecurityEvent::PASSKEY_SIGN_IN)->count());
    }

    #[DataProvider('writes')]
    public function test_every_write_needs_the_csrf_token(string $method, string $uri): void
    {
        $this->actingAs($this->member());
        // The check is off while the environment is "testing"
        $this->app['env'] = 'local';

        $this->json($method, $uri)->assertStatus(419);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function writes(): array
    {
        return [
            'password' => ['POST', '/console/login'],
            'code' => ['POST', '/console/login/two-factor'],
            'send a code' => ['POST', '/console/login/two-factor/email'],
            'back' => ['DELETE', '/console/login/two-factor'],
            'forgot' => ['POST', '/console/forgot-password'],
            'reset' => ['POST', '/console/reset-password'],
            'sign out' => ['POST', '/console/logout'],
        ];
    }

    public function test_sign_out_ends_the_session_and_lands_on_the_sign_in_page(): void
    {
        $this->member();
        $this->password();
        $before = session()->getId();

        $this->sameBrowser()->post('/console/logout')->assertRedirect(route('console.login'));

        $this->assertNotSame($before, session()->getId());
        $this->sameBrowser()->assertSignedOut();
    }

    public function test_a_password_changed_elsewhere_ends_the_session_at_the_console_sign_in(): void
    {
        $user = $this->member();
        $this->password();
        $this->get('/console/owner')->assertOk();

        User::query()->whereKey($user->id)->update(['password' => Hash::make('changed-somewhere-else')]);
        Auth::forgetGuards();

        $this->get('/console/owner')->assertRedirect(route('console.login'));
    }

    public function test_forgot_password_answers_the_same_and_mails_only_the_team(): void
    {
        Notification::fake();
        $user = $this->member();
        $outsider = $this->member('former_staff', ['email' => 'former@example.com']);

        $team = $this->postJson('/console/forgot-password', ['email' => $user->email])->assertOk();
        $none = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->postJson('/console/forgot-password', ['email' => $outsider->email])->assertOk();
        $unknown = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])->postJson('/console/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        // Asked again within the broker's minute: still the same words
        $again = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])->postJson('/console/forgot-password', ['email' => $user->email])->assertOk();

        $this->assertSame($team->json(), $none->json());
        $this->assertSame($team->json(), $unknown->json());
        $this->assertSame($team->json(), $again->json());
        Notification::assertNotSentTo($outsider, ResetPassword::class);
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        Notification::assertSentTo($user, ResetPassword::class, fn (ResetPassword $mail) => str_starts_with($mail->url, route('console.password.reset').'?token=')
            && str_contains($mail->url, 'email=dennis%40example.com'));
    }

    public function test_forgot_password_is_limited(): void
    {
        Notification::fake();

        foreach (range(1, 2) as $try) {
            $this->postJson('/console/forgot-password', ['email' => "nobody{$try}@example.com"])->assertOk();
        }

        $this->postJson('/console/forgot-password', ['email' => 'nobody3@example.com'])->assertTooManyRequests();

        // And per address, whoever asks
        foreach (range(1, 2) as $try) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.1.{$try}"])->postJson('/console/forgot-password', ['email' => 'dennis@example.com'])->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.9'])->postJson('/console/forgot-password', ['email' => 'dennis@example.com'])->assertTooManyRequests();
    }

    private function reset(string $token, string $email = 'dennis@example.com', string $password = 'a-brand-new-password', ?string $again = null): TestResponse
    {
        return $this->postJson('/console/reset-password', ['token' => $token, 'email' => $email, 'password' => $password, 'password_confirmation' => $again ?? $password]);
    }

    public function test_a_reset_link_sets_a_new_password_once_and_does_not_sign_in(): void
    {
        // An invited person who never chose a password
        $user = $this->withApp($this->member(state: ['email_verified_at' => null]));
        $remember = $user->remember_token;
        $token = Password::broker()->createToken($user);

        $this->get('/console/reset-password?token='.$token.'&email=dennis%40example.com')->assertOk()->assertSee('"screen":"reset"', false);

        $this->reset($token)->assertOk()->assertExactJson(['redirect' => route('console.login')]);

        $user->refresh();
        $this->assertTrue(Hash::check('a-brand-new-password', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotSame($remember, $user->remember_token);
        // The second factor is still asked for
        $this->assertGuest('web');
        $this->get('/console/login')->assertSee('Your password has been reset.');

        $this->travel(2)->minutes();
        $this->reset($token, password: 'one-more-password')->assertUnprocessable()->assertJsonPath('errors.token.0', self::DEAD_LINK);
    }

    public function test_every_dead_reset_link_reads_the_same(): void
    {
        $user = $this->member();
        $outsider = $this->member('former_staff', ['email' => 'former@example.com']);

        $answers = [
            $this->reset('wrong-token'),
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->reset('wrong-token', 'nobody@example.com'),
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])->reset(Password::broker()->createToken($outsider), 'former@example.com'),
        ];

        foreach ($answers as $answer) {
            $answer->assertUnprocessable()->assertExactJson(['message' => self::DEAD_LINK, 'errors' => ['token' => [self::DEAD_LINK]]]);
        }

        $this->assertTrue(Hash::check(self::PASSWORD, $outsider->fresh()->password));
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_a_reset_needs_a_long_enough_password_typed_twice(): void
    {
        $token = Password::broker()->createToken($this->member());

        $this->reset($token, password: 'short')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->reset($token, again: 'something else entirely')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->reset($token)->assertTooManyRequests();
    }

    /**
     * What the current admin stored keeps working here unchanged: made with
     * the very calls its set-up action makes, then only read.
     */
    public function test_a_secret_and_recovery_codes_made_by_the_current_admin_sign_in_here(): void
    {
        $user = $this->member();
        $filament = AppAuthentication::make()->recoverable();
        $secret = $filament->generateSecret();
        $codes = $filament->generateRecoveryCodes();
        $filament->saveSecret($user, $secret);
        $filament->saveRecoveryCodes($user, $codes);
        $columns = ['password', 'app_authentication_secret', 'app_authentication_recovery_codes'];
        $stored = DB::table('users')->where('id', $user->id)->first($columns);

        $this->password()->assertJsonPath('twoFactor.methods', ['app']);
        $this->code($this->currentCode($user))->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertEquals($stored, DB::table('users')->where('id', $user->id)->first($columns));

        $this->post('/console/logout');
        Auth::forgetGuards();

        $this->password();
        $this->code($codes[3])->assertOk();
        $this->assertCount(7, $user->fresh()->app_authentication_recovery_codes);
        // And the current admin still reads what is left
        $this->assertTrue($filament->verifyRecoveryCode($codes[4], $user->fresh()));
    }

    public function test_a_code_used_in_the_current_admin_cannot_be_used_again_here(): void
    {
        $user = $this->withApp($this->member());
        $code = $this->currentCode($user);
        $this->assertTrue(AppAuthentication::make()->verifyCode($code, $user->app_authentication_secret, shouldPreventCodeReuse: true));

        $this->password();

        $this->code($code)->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_a_remember_cookie_from_before_still_opens_the_console(): void
    {
        $user = $this->member();
        $guard = Auth::guard('web');
        $guard->login($user, true);
        // The cookie the current admin's sign-in sets: the same guard, the same format
        $name = $guard->getRecallerName();
        $value = implode('|', [$user->id, $user->fresh()->remember_token, $guard->hashPasswordForCookie($user->password)]);
        Auth::forgetGuards();
        session()->flush();

        $this->withCookie($name, $value)->get('/console/owner')->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_odd_input_on_the_pages_is_not_an_error(): void
    {
        $this->get('/console/reset-password?token[]=a&email[]=b')->assertOk()->assertSee('"email":""', false);
        $this->get('/console/reset-password?email=%3C%2Fscript%3E%3Cscript%3Ealert(1)%3C%2Fscript%3E&token=x')->assertOk()->assertDontSee('<script>alert(1)', false);
        $this->postJson('/console/login', ['email' => ['a'], 'password' => ['b']])->assertUnprocessable();
    }

    public function test_links_already_sent_still_open(): void
    {
        $user = $this->member();
        $link = Filament::getPanel('owner')->getResetPasswordUrl(Password::broker()->createToken($user), $user);

        $this->get($link)->assertOk();
    }
}
