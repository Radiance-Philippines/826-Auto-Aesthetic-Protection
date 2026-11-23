<?php

namespace Tests\Feature;

use App\Models\AppRelease;
use App\Models\Branch;
use App\Models\SecurityEvent;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Models\UserToken;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The 826 Team app's sign-in: password, authenticator code (Filament's real
 * TOTP and recovery codes), tokens, and which tabs each role gets.
 */
class StaffAuthTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private Branch $cainta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
    }

    private function member(string $role = 'branch_manager', array $state = []): User
    {
        return $this->staffUser($role, $this->cainta, ['email' => 'dennis@example.com', 'password' => 'a-long-test-password', ...$state]);
    }

    /**
     * @return array<string, string>
     */
    private function credentials(array $override = []): array
    {
        return ['email' => 'dennis@example.com', 'password' => 'a-long-test-password', 'device_name' => 'Galaxy S24', ...$override];
    }

    private function withTwoFactor(User $user): string
    {
        $provider = AppAuthentication::make();
        $user->saveAppAuthenticationSecret($provider->generateSecret());

        return $provider->getCurrentCode($user);
    }

    public function test_a_team_member_signs_in_and_gets_a_token_that_works(): void
    {
        $user = $this->member();

        $response = $this->postJson('/api/staff/sign-in', $this->credentials())->assertOk();
        $this->example('sign-in', 'POST /api/staff/sign-in {email, password, device_name}', $response);

        $response->assertJsonStructure(['token', 'expiresAt', 'user' => ['id', 'name', 'email', 'role', 'roleLabel', 'isOwner', 'areas', 'branches', 'tabs', 'overflow']]);
        $token = $response->json('token');

        $this->assertSame(40, strlen($token));
        // Only the hash is kept
        $this->assertDatabaseMissing('user_tokens', ['token_hash' => $token]);
        $this->assertDatabaseHas('user_tokens', ['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'name' => 'Galaxy S24']);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'kind' => SecurityEvent::STAFF_APP_SIGN_IN]);

        $me = $this->example('me', 'GET /api/staff/me', $this->getJson('/api/staff/me', ['Authorization' => "Bearer {$token}"]))->assertOk();
        $me->assertJsonPath('id', $user->id)->assertJsonPath('roleLabel', 'Branch manager')->assertJsonPath('branches.0.name', 'Cainta');
    }

    public function test_wrong_details_are_one_generic_message_whether_or_not_the_email_exists(): void
    {
        $this->member();

        $wrongPassword = $this->example('sign-in-wrong', 'POST /api/staff/sign-in (wrong email or password: 422, same text either way)', $this->postJson('/api/staff/sign-in', $this->credentials(['password' => 'nope-nope-nope'])))->assertUnprocessable();
        $noSuchEmail = $this->postJson('/api/staff/sign-in', $this->credentials(['email' => 'nobody@example.com']))->assertUnprocessable();

        $this->assertSame($wrongPassword->json('errors.email'), $noSuchEmail->json('errors.email'));
        $this->assertDatabaseHas('security_events', ['kind' => SecurityEvent::STAFF_APP_SIGN_IN_FAILED, 'note' => 'Wrong password']);
        $this->assertSame(0, UserToken::count());
    }

    public function test_a_pending_invite_cannot_sign_in(): void
    {
        $this->member('branch_manager', ['invited_at' => now(), 'email_verified_at' => null]);

        $this->postJson('/api/staff/sign-in', $this->credentials())->assertUnprocessable();
    }

    public function test_wrong_passwords_are_limited_per_email(): void
    {
        $this->member();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/staff/sign-in', $this->credentials(['password' => 'wrong-password']))->assertUnprocessable();
        }

        // Even the right password waits
        $this->postJson('/api/staff/sign-in', $this->credentials())->assertStatus(429);
    }

    public function test_sign_in_is_limited_per_address(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/staff/sign-in', $this->credentials(['email' => "x{$i}@example.com"]))->assertUnprocessable();
        }

        $this->postJson('/api/staff/sign-in', $this->credentials(['email' => 'y@example.com']))->assertStatus(429);
    }

    public function test_an_authenticator_code_is_asked_for_before_a_token_is_given(): void
    {
        $user = $this->member();
        $code = $this->withTwoFactor($user);

        $challenge = $this->postJson('/api/staff/sign-in', $this->credentials());
        $this->example('sign-in-two-factor-required', 'POST /api/staff/sign-in (account has an authenticator app)', $challenge);
        $challenge->assertOk()->assertJsonMissingPath('token')->assertJsonStructure(['twoFactor' => ['challenge', 'expiresInSeconds']]);
        $this->assertSame(0, UserToken::count());

        $signedIn = $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $challenge->json('twoFactor.challenge'), 'code' => $code]);
        $this->example('sign-in-two-factor', 'POST /api/staff/sign-in/two-factor {challenge, code}', $signedIn);
        $signedIn->assertOk()->assertJsonStructure(['token', 'user' => ['id']]);

        $this->assertDatabaseHas('user_tokens', ['user_id' => $user->id, 'name' => 'Galaxy S24']);
    }

    public function test_a_challenge_is_used_once_and_a_code_cannot_be_replayed(): void
    {
        $user = $this->member();
        $code = $this->withTwoFactor($user);

        $first = $this->postJson('/api/staff/sign-in', $this->credentials())->json('twoFactor.challenge');
        $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $first, 'code' => $code])->assertOk();

        // The same challenge again: gone
        $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $first, 'code' => $code])->assertUnprocessable();

        // A new challenge with the same (already used) code: refused
        $second = $this->postJson('/api/staff/sign-in', $this->credentials())->json('twoFactor.challenge');
        $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $second, 'code' => $code])->assertUnprocessable()->assertJsonPath('errors.code.0', 'That code isn’t right. Check the app and try again.');
        $this->assertSame(1, UserToken::count());
    }

    public function test_wrong_codes_burn_the_challenge_after_five_tries_and_are_logged(): void
    {
        $user = $this->member();
        $good = $this->withTwoFactor($user);
        $challenge = $this->postJson('/api/staff/sign-in', $this->credentials())->json('twoFactor.challenge');
        $wrong = $good === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $challenge, 'code' => $wrong])->assertUnprocessable();
        }

        $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $challenge, 'code' => $wrong])->assertStatus(429)->assertJsonPath('restart', true);
        // Even the right code no longer works on that challenge
        $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $challenge, 'code' => $good])->assertUnprocessable();

        $this->assertSame(5, SecurityEvent::where('kind', SecurityEvent::STAFF_APP_SIGN_IN_FAILED)->where('note', 'Wrong two-factor code')->count());
        $this->assertSame(0, UserToken::count());
    }

    public function test_a_challenge_expires_after_five_minutes(): void
    {
        $user = $this->member();
        $code = $this->withTwoFactor($user);
        $challenge = $this->postJson('/api/staff/sign-in', $this->credentials())->json('twoFactor.challenge');

        $this->travel(6)->minutes();

        $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $challenge, 'code' => $code])->assertUnprocessable();
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        $user = $this->member();
        $this->withTwoFactor($user);
        AppAuthentication::make()->saveRecoveryCodes($user, ['aaaaaaaaaa-bbbbbbbbbb', 'cccccccccc-dddddddddd']);

        $challenge = $this->postJson('/api/staff/sign-in', $this->credentials())->json('twoFactor.challenge');
        $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $challenge, 'code' => 'aaaaaaaaaa-bbbbbbbbbb'])->assertOk()->assertJsonStructure(['token']);
        $this->assertCount(1, $user->fresh()->app_authentication_recovery_codes);

        $again = $this->postJson('/api/staff/sign-in', $this->credentials())->json('twoFactor.challenge');
        $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $again, 'code' => 'aaaaaaaaaa-bbbbbbbbbb'])->assertUnprocessable();

        // The other one still works
        $third = $this->postJson('/api/staff/sign-in', $this->credentials())->json('twoFactor.challenge');
        $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $third, 'code' => 'cccccccccc-dddddddddd'])->assertOk();
    }

    public function test_a_recovery_code_is_refused_for_an_account_with_none(): void
    {
        $user = $this->member();
        $this->withTwoFactor($user);

        $challenge = $this->postJson('/api/staff/sign-in', $this->credentials())->json('twoFactor.challenge');

        $this->postJson('/api/staff/sign-in/two-factor', ['challenge' => $challenge, 'code' => 'aaaaaaaaaa-bbbbbbbbbb'])->assertUnprocessable();
    }

    public function test_required_two_factor_without_setup_is_a_plain_403_and_no_token(): void
    {
        $this->member();
        SecuritySetting::instance()->update(['require_two_factor' => true]);

        $response = $this->postJson('/api/staff/sign-in', $this->credentials());
        $this->example('sign-in-two-factor-not-set-up', 'POST /api/staff/sign-in (two-factor required, not set up)', $response);

        $response->assertForbidden()->assertJsonPath('message', fn (string $message) => str_contains($message, 'set up an authenticator app first'));
        $this->assertSame(0, UserToken::count());
    }

    public function test_an_account_with_only_email_codes_is_told_the_app_cannot_use_them(): void
    {
        $this->member('branch_manager', ['has_email_authentication' => true]);

        $this->postJson('/api/staff/sign-in', $this->credentials())
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'emailed code'));
        $this->assertSame(0, UserToken::count());
    }

    public function test_a_token_stops_working_when_two_factor_becomes_required_and_the_account_has_none(): void
    {
        $user = $this->member();
        $headers = $this->bearer($user);
        $this->getJson('/api/staff/me', $headers)->assertOk();

        SecuritySetting::instance()->update(['require_two_factor' => true]);

        $this->getJson('/api/staff/me', $headers)->assertUnauthorized()->assertJsonPath('signIn', true);
    }

    public function test_a_missing_bad_revoked_or_cookie_only_sign_in_is_a_401(): void
    {
        $user = $this->member();

        $this->example('unauthorized', 'Any /api/staff call without a live token (401)', $this->getJson('/api/staff/me'))->assertUnauthorized()->assertExactJson(['message' => 'Sign in to continue.', 'signIn' => true]);
        $this->getJson('/api/staff/me', ['Authorization' => 'Bearer not-a-token'])->assertUnauthorized();
        // The website's session cookie is not a staff sign-in
        $this->actingAs($user)->getJson('/api/staff/me')->assertUnauthorized();
    }

    public function test_sign_out_revokes_this_device_only(): void
    {
        $user = $this->member();
        $phone = $this->bearer($user);
        $tablet = $this->bearer($user);

        $this->example('sign-out', 'POST /api/staff/sign-out', $this->postJson('/api/staff/sign-out', [], $phone))->assertOk()->assertJsonPath('ok', true);

        $this->getJson('/api/staff/me', $phone)->assertUnauthorized();
        $this->getJson('/api/staff/me', $tablet)->assertOk();
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'kind' => SecurityEvent::STAFF_APP_SIGN_OUT]);
    }

    public function test_a_token_lasts_thirty_days_from_its_last_use(): void
    {
        $user = $this->member();
        $headers = $this->bearer($user);

        $this->travel(20)->days();
        $this->getJson('/api/staff/me', $headers)->assertOk();

        // 20 + 20 days: past the 30 of the first token, but it slid forward on use
        $this->travel(20)->days();
        $this->getJson('/api/staff/me', $headers)->assertOk();

        $this->travel(31)->days();
        $this->getJson('/api/staff/me', $headers)->assertUnauthorized();
    }

    public function test_deleting_the_account_ends_its_tokens(): void
    {
        $user = $this->member();
        $headers = $this->bearer($user);

        $user->delete();

        $this->getJson('/api/staff/me', $headers)->assertUnauthorized();
    }

    public function test_each_role_gets_its_tabs(): void
    {
        $this->cainta->update(['status' => 'open']);
        $expected = [
            // Every area: six tabs, so Clients moves to overflow
            'super_admin' => [['queue', 'today', 'inbox', 'overview', 'me'], ['clients']],
            'branch_manager' => [['queue', 'today', 'inbox', 'overview', 'me'], ['clients']],
            'sales_admin' => [['queue', 'today', 'inbox', 'overview', 'me'], ['clients']],
            // The detailer: the queue, clients (customers.view is one of their default areas), overview, me
            'operations' => [['queue', 'clients', 'overview', 'me'], []],
        ];

        foreach ($expected as $role => [$tabs, $overflow]) {
            $user = $this->staffUser($role, $this->cainta);
            $response = $this->getJson('/api/staff/me', $this->bearer($user))->assertOk();
            $this->assertSame($tabs, $response->json('tabs'), "{$role} tabs");
            $this->assertSame($overflow, $response->json('overflow'), "{$role} overflow");
        }
    }

    public function test_tabs_follow_the_accounts_own_areas_and_branches(): void
    {
        // No inbox without a branch; no queue without bookings.view or queue.update; no today without bookings.sales
        $loose = User::factory()->state(['role' => 'sales_admin'])->create();
        $this->assertSame(['queue', 'today', 'clients', 'overview', 'me'], $this->getJson('/api/staff/me', $this->bearer($loose))->json('tabs'));

        $custom = $this->staffUser('operations', $this->cainta, ['permissions' => ['customers.view']]);
        $this->assertSame(['clients', 'me'], $this->getJson('/api/staff/me', $this->bearer($custom))->json('tabs'));

        $queueOnly = $this->staffUser('operations', $this->cainta, ['permissions' => ['queue.update']]);
        $this->assertSame(['queue', 'me'], $this->getJson('/api/staff/me', $this->bearer($queueOnly))->json('tabs'));

        $viewOnly = $this->staffUser('operations', $this->cainta, ['permissions' => ['bookings.view']]);
        $this->assertSame(['queue', 'me'], $this->getJson('/api/staff/me', $this->bearer($viewOnly))->json('tabs'));
    }

    public function test_the_latest_staff_build_is_separate_from_the_customer_app(): void
    {
        $this->getJson('/api/staff/latest')->assertOk()->assertExactJson(['version' => null, 'versionCode' => 0, 'url' => null, 'sha256' => null, 'notes' => null, 'mandatory' => false]);

        AppRelease::factory()->create(['version' => '9.9.9', 'version_code' => 99]);
        AppRelease::factory()->staff()->create(['version' => '0.1.0', 'version_code' => 1, 'sha256' => str_repeat('a', 64), 'notes' => 'First team build']);
        AppRelease::factory()->staff()->draft()->create(['version' => '0.2.0', 'version_code' => 2]);

        $response = $this->example('latest', 'GET /api/staff/latest', $this->getJson('/api/staff/latest'))->assertOk();
        $response->assertJsonPath('version', '0.1.0')->assertJsonPath('versionCode', 1)->assertJsonPath('url', url('/download/826-team-0.1.0.apk'));
        // The customer app still gets its own
        $this->getJson('/api/app/latest')->assertJsonPath('version', '9.9.9');
        // The same version code may exist in both apps
        AppRelease::factory()->create(['version' => '0.1.0', 'version_code' => 1]);
        $this->assertSame(2, AppRelease::where('version_code', 1)->count());
    }

    public function test_a_signed_in_person_has_a_generous_per_minute_budget(): void
    {
        $headers = $this->bearer($this->member());

        $this->getJson('/api/staff/me', $headers)->assertHeader('X-RateLimit-Limit', 240);
    }

    public function test_the_password_is_checked_with_the_real_hash(): void
    {
        $user = $this->member();

        $this->assertTrue(Hash::check('a-long-test-password', $user->password));
    }
}
