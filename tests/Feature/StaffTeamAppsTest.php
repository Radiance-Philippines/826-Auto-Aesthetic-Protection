<?php

namespace Tests\Feature;

use App\Models\AppRelease;
use App\Models\Branch;
use App\Models\MailSetting;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Models\UserToken;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * One team app per panel (config/team_apps.php): who may sign in to which
 * app, tokens bound to their app, /me and its tabs per app (the legacy app,
 * with no X-Team-App header, unchanged), /api/staff/system and latest per app.
 */
class StaffTeamAppsTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const APPS = ['owner', 'sales', 'operations', 'system'];

    private Branch $cainta;

    protected function setUp(): void
    {
        parent::setUp();

        // The matrix signs in more than ten times a minute from one address
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->cainta = Branch::factory()->create(['name' => 'Cainta', 'status' => 'open']);
    }

    private function member(string $role, array $state = []): User
    {
        return $this->staffUser($role, $this->cainta, ['email' => fake()->unique()->userName().'@example.com', 'password' => 'a-long-test-password', ...$state]);
    }

    private function signIn(User $user, ?string $app): TestResponse
    {
        return $this->postJson('/api/staff/sign-in', ['email' => $user->email, 'password' => 'a-long-test-password', 'device_name' => 'Galaxy S24'], $app ? ['X-Team-App' => $app] : []);
    }

    public function test_sign_in_matrix_every_role_in_every_app(): void
    {
        $opens = [
            'super_admin' => self::APPS,
            'sales_admin' => ['sales'],
            'operations' => ['operations'],
            'branch_manager' => ['operations'],
        ];
        $fits = ['sales_admin' => 'sales', 'operations' => 'operations', 'branch_manager' => 'operations'];

        foreach ($opens as $role => $allowed) {
            $user = $this->member($role);

            foreach (self::APPS as $app) {
                $response = $this->signIn($user, $app);
                if (in_array("{$app}/{$role}", ['owner/sales_admin', 'sales/sales_admin', 'system/super_admin'], true)) {
                    $this->example("sign-in-{$app}-as-{$role}", "POST /api/staff/sign-in (X-Team-App: {$app}, signing in as {$role})", $response);
                }

                if (in_array($app, $allowed, true)) {
                    $response->assertOk()->assertJsonPath('user.app', $app);
                    $this->assertDatabaseHas('user_tokens', ['token_hash' => hash('sha256', $response->json('token')), 'app' => $app]);
                } else {
                    $label = config("team_apps.{$app}.label");
                    $response->assertForbidden()->assertExactJson([
                        'message' => "This app is for {$label}. Use ".config("team_apps.{$fits[$role]}.label").'.',
                        'code' => 'app_not_for_role',
                        'suggestApp' => $fits[$role],
                    ]);
                    $this->assertNull($response->json('token'));
                }
            }

            // No header: the legacy app, every role, a token with no app and no `app` in the answer
            $legacy = $this->signIn($user, null)->assertOk();
            $this->assertArrayNotHasKey('app', $legacy->json('user'));
            $this->assertDatabaseHas('user_tokens', ['token_hash' => hash('sha256', $legacy->json('token')), 'app' => null]);
        }
    }

    public function test_an_unknown_app_is_a_422_and_a_wrong_password_is_never_told_the_app(): void
    {
        $user = $this->member('sales_admin');

        $this->signIn($user, 'payroll')->assertStatus(422);

        // Wrong password for an app that is not for this role: the same generic answer as any wrong password
        $wrong = $this->postJson('/api/staff/sign-in', ['email' => $user->email, 'password' => 'nope-nope-nope'], ['X-Team-App' => 'owner']);
        $wrong->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertNull($wrong->json('suggestApp'));
        $unknown = $this->postJson('/api/staff/sign-in', ['email' => 'nobody@example.com', 'password' => 'nope-nope-nope'], ['X-Team-App' => 'owner']);
        $this->assertSame($wrong->json('errors'), $unknown->json('errors'));
    }

    public function test_the_app_is_checked_after_the_two_factor_code_not_before(): void
    {
        $user = $this->member('sales_admin');
        $provider = AppAuthentication::make();
        $user->saveAppAuthenticationSecret($provider->generateSecret());
        $headers = ['X-Team-App' => 'owner'];

        // The password alone gets the challenge, not a "wrong app"
        $challenge = $this->postJson('/api/staff/sign-in', ['email' => $user->email, 'password' => 'a-long-test-password'], $headers)->assertOk()->json('twoFactor.challenge');

        // A wrong code is a wrong code
        $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $challenge, 'code' => '000000'], $headers)->assertStatus(422);

        // The right code: now the 403 with the app that fits
        $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $challenge, 'code' => $provider->getCurrentCode($user)], $headers)
            ->assertForbidden()
            ->assertJsonPath('code', 'app_not_for_role')
            ->assertJsonPath('suggestApp', 'sales');
        $this->assertSame(0, UserToken::count());

        // The right app signs in and the token remembers it (a code is used once, so another account with its own)
        $user = $this->member('sales_admin');
        $user->saveAppAuthenticationSecret($provider->generateSecret());
        $challenge = $this->postJson('/api/staff/sign-in', ['email' => $user->email, 'password' => 'a-long-test-password'], ['X-Team-App' => 'sales'])->assertOk()->json('twoFactor.challenge');
        $done = $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $challenge, 'code' => $provider->getCurrentCode($user)], ['X-Team-App' => 'sales'])->assertOk();
        $this->assertDatabaseHas('user_tokens', ['token_hash' => hash('sha256', $done->json('token')), 'app' => 'sales']);
        $done->assertJsonPath('user.app', 'sales');
    }

    public function test_a_token_only_works_for_the_app_it_was_issued_for(): void
    {
        $owner = $this->member('super_admin');
        $plain = $this->signIn($owner, 'owner')->json('token');
        $bearer = ['Authorization' => "Bearer {$plain}"];

        $this->getJson('/api/staff/me', $bearer + ['X-Team-App' => 'owner'])->assertOk();
        $this->example('token-for-another-app', 'GET /api/staff/me (token issued for owner, X-Team-App: sales)', $this->getJson('/api/staff/me', $bearer + ['X-Team-App' => 'sales']))->assertUnauthorized()->assertJson(['signIn' => true]);
        $this->getJson('/api/staff/me', $bearer)->assertUnauthorized()->assertJson(['signIn' => true]);
        $this->example('unknown-app', 'GET /api/staff/me (X-Team-App: payroll)', $this->getJson('/api/staff/me', $bearer + ['X-Team-App' => 'payroll']))->assertStatus(422);

        // A legacy token (no app) works only without the header
        $legacy = $this->bearer($owner);
        $this->getJson('/api/staff/me', $legacy)->assertOk();
        $this->getJson('/api/staff/me', $legacy + ['X-Team-App' => 'owner'])->assertUnauthorized()->assertJson(['signIn' => true]);
    }

    public function test_me_per_app_has_the_app_and_only_its_tabs_and_the_legacy_answer_is_unchanged(): void
    {
        $owner = $this->member('super_admin');

        $expected = [
            'owner' => ['overview', 'clients', 'inbox', 'me'],
            'sales' => ['today', 'clients', 'inbox', 'me'],
            'operations' => ['queue', 'inbox', 'clients', 'me'],
            'system' => ['system', 'me'],
        ];

        foreach ($expected as $app => $tabs) {
            $me = $this->example("me-{$app}", "GET /api/staff/me (X-Team-App: {$app}, super admin)", $this->getJson('/api/staff/me', $this->bearer($owner, $app)))->assertOk();
            $me->assertJsonPath('app', $app)->assertJsonPath('overflow', []);
            $this->assertSame($tabs, $me->json('tabs'), "{$app} tabs");
        }

        // The legacy app: no `app`, the old tabs (no system tab) and the overflow
        $legacy = $this->getJson('/api/staff/me', $this->bearer($owner))->assertOk();
        $this->assertArrayNotHasKey('app', $legacy->json());
        $this->assertSame(['queue', 'today', 'inbox', 'overview', 'me'], $legacy->json('tabs'));
        $this->assertSame(['clients'], $legacy->json('overflow'));
    }

    public function test_tabs_per_app_per_role_follow_the_accounts_areas(): void
    {
        $sales = $this->member('sales_admin');
        $this->assertSame(['today', 'clients', 'inbox', 'me'], $this->getJson('/api/staff/me', $this->bearer($sales, 'sales'))->json('tabs'));

        $detailer = $this->member('operations');
        $this->assertSame(['queue', 'clients', 'me'], $this->getJson('/api/staff/me', $this->bearer($detailer, 'operations'))->json('tabs'));

        $manager = $this->member('branch_manager');
        $this->assertSame(['queue', 'inbox', 'clients', 'me'], $this->getJson('/api/staff/me', $this->bearer($manager, 'operations'))->json('tabs'));

        // Only the areas the account has: customers only = clients, nothing else
        $custom = $this->member('operations', ['permissions' => ['customers.view']]);
        $this->assertSame(['clients', 'me'], $this->getJson('/api/staff/me', $this->bearer($custom, 'operations'))->json('tabs'));

        // No area at all: only Me
        $none = $this->member('operations', ['permissions' => []]);
        $this->assertSame(['me'], $this->getJson('/api/staff/me', $this->bearer($none, 'operations'))->json('tabs'));

        // The system tab needs the settings area
        $this->assertSame(['me'], $this->getJson('/api/staff/me', $this->bearer($none, 'system'))->json('tabs'));
    }

    public function test_the_system_endpoint_gives_booleans_and_versions_and_no_secrets(): void
    {
        $owner = $this->member('super_admin');
        MailSetting::instance()->forceFill(['password' => 'super-secret-mail-password'])->save();
        AppRelease::factory()->create(['version' => '0.4.0', 'version_code' => 4]);
        AppRelease::factory()->forApp('owner')->create(['version' => '0.1.0', 'version_code' => 1]);
        AppRelease::factory()->forApp('sales')->draft()->create(['version' => '0.2.0', 'version_code' => 2]);
        SecuritySetting::current()->forceFill(['require_two_factor' => false, 'allow_passkeys' => true])->save();

        $response = $this->example('system', 'GET /api/staff/system (X-Team-App: system)', $this->getJson('/api/staff/system', $this->bearer($owner, 'system')))->assertOk();

        $this->assertSame(['health', 'customerApp', 'teamApps', 'security'], array_keys($response->json()));
        $this->assertSame(['email', 'meta', 'google', 'flow'], array_keys($response->json('health')));
        foreach ($response->json('health') as $on) {
            $this->assertIsBool($on);
        }
        $this->assertSame(['version', 'versionCode', 'publishedAt'], array_keys($response->json('customerApp')));
        $response->assertJsonPath('customerApp.version', '0.4.0')->assertJsonPath('customerApp.versionCode', 4);
        $this->assertSame([
            ['app' => 'owner', 'label' => '826 Owner', 'version' => '0.1.0'],
            ['app' => 'sales', 'label' => '826 Sales', 'version' => null],
            ['app' => 'operations', 'label' => '826 Operations', 'version' => null],
            ['app' => 'system', 'label' => '826 System', 'version' => null],
        ], $response->json('teamApps'));
        $this->assertSame(['requireTwoFactor' => false, 'passkeysAllowed' => true], $response->json('security'));
        $this->assertStringNotContainsString('super-secret-mail-password', $response->getContent());

        // No release yet: customerApp is null
        AppRelease::query()->delete();
        $this->getJson('/api/staff/system', $this->bearer($owner, 'system'))->assertOk()->assertJsonPath('customerApp', null);
    }

    public function test_the_system_endpoint_is_for_the_system_app_and_the_settings_area_only(): void
    {
        $owner = $this->member('super_admin');
        $this->getJson('/api/staff/system')->assertUnauthorized();
        // Another app, or the legacy app, even for the owner
        $this->getJson('/api/staff/system', $this->bearer($owner, 'owner'))->assertForbidden();
        $this->example('system-forbidden', 'GET /api/staff/system (token for owner app)', $this->getJson('/api/staff/system', $this->bearer($owner, 'owner')))->assertForbidden();
        $this->getJson('/api/staff/system', $this->bearer($owner))->assertForbidden();

        // A token for the system app on an account without the settings area (cannot sign in to it, but a stale token stays safe)
        $sales = $this->member('sales_admin');
        $this->getJson('/api/staff/system', $this->bearer($sales, 'system'))->assertForbidden();
    }

    public function test_latest_is_per_app_and_the_legacy_app_gets_the_staff_release(): void
    {
        AppRelease::factory()->forApp('staff')->create(['version' => '0.1.1', 'version_code' => 11]);
        AppRelease::factory()->forApp('operations')->create(['version' => '0.3.0', 'version_code' => 3, 'sha256' => str_repeat('b', 64)]);
        AppRelease::factory()->forApp('operations')->draft()->create(['version' => '0.4.0', 'version_code' => 4]);
        AppRelease::factory()->create(['version' => '9.9.9', 'version_code' => 99]);

        $this->getJson('/api/staff/latest')->assertOk()->assertJsonPath('version', '0.1.1')->assertJsonPath('url', url('/download/826-team-0.1.1.apk'));
        $ops = $this->example('latest-operations', 'GET /api/staff/latest (X-Team-App: operations)', $this->getJson('/api/staff/latest', ['X-Team-App' => 'operations']))->assertOk();
        $ops->assertJsonPath('version', '0.3.0')->assertJsonPath('url', url('/download/826-operations-0.3.0.apk'));
        // An app with no build yet: versionCode 0
        $this->getJson('/api/staff/latest', ['X-Team-App' => 'owner'])->assertOk()->assertJsonPath('versionCode', 0)->assertJsonPath('version', null);
        $this->getJson('/api/staff/latest', ['X-Team-App' => 'payroll'])->assertStatus(422);
    }
}
