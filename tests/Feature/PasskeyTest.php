<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\SecuritySettings;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\SecurityEvent;
use App\Models\SecuritySetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\LaravelPasskeys\Models\Passkey;
use Tests\Support\SoftwareAuthenticator;
use Tests\TestCase;

/**
 * Passkeys for the admin team: adding one in the profile, signing in with
 * it (no email, no 2FA code), and the rules around it. The device is a
 * software authenticator that signs for real; the server's checks
 * (challenge, origin, signature, user verification) are the package's own.
 */
class PasskeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['passkeys.relying_party.id' => 'localhost']);
        RateLimiter::clear('passkey-login|127.0.0.1');
    }

    /**
     * Adds a passkey from the profile, the way the browser does.
     *
     * @return array{0: SoftwareAuthenticator, 1: array<string, mixed>}
     */
    private function addPasskey(User $user, string $name = 'Work laptop', ?SoftwareAuthenticator $device = null): array
    {
        $device ??= new SoftwareAuthenticator;
        $this->actingAs($user);

        $options = null;
        $page = Livewire::test(EditProfile::class);
        $page->call('passkeyOptions')->assertReturned(function (string $json) use (&$options) {
            $options = json_decode($json, true);

            return true;
        });

        $result = null;
        $page->call('storePasskey', json_encode($device->register($options)), $name)
            ->assertReturned(function (array $value) use (&$result) {
                $result = $value;

                return true;
            });

        return [$device, $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function signInOptions(): array
    {
        return $this->getJson('/admin-passkeys/options')->assertOk()->json();
    }

    private function signIn(array $assertion)
    {
        return $this->postJson('/admin-passkeys/login', ['credential' => json_encode($assertion)]);
    }

    // Adding

    public function test_a_passkey_is_added_from_the_profile_and_checked_for_real(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $options = null;
        Livewire::test(EditProfile::class)->call('passkeyOptions')->assertReturned(function (string $json) use (&$options) {
            $options = json_decode($json, true);

            return true;
        });

        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
        $this->assertSame('required', $options['authenticatorSelection']['residentKey']);
        $this->assertSame('localhost', $options['rp']['id']);

        [, $result] = $this->addPasskey($user, 'Work laptop');

        $this->assertTrue($result['ok']);
        $this->assertSame('Work laptop', $user->passkeys()->sole()->name);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'kind' => 'passkey_added', 'note' => 'Work laptop']);
    }

    public function test_a_passkey_made_without_user_verification_is_refused(): void
    {
        $user = User::factory()->create();

        [, $result] = $this->addPasskey($user, 'Weak', new SoftwareAuthenticator(userVerified: false));

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $user->passkeys()->count());
        $this->assertDatabaseMissing('security_events', ['kind' => 'passkey_added']);
    }

    public function test_a_passkey_from_another_address_is_refused(): void
    {
        $user = User::factory()->create();

        [, $result] = $this->addPasskey($user, 'Phish', new SoftwareAuthenticator(origin: 'https://evil.example', rpId: 'localhost'));

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $user->passkeys()->count());
    }

    public function test_a_person_can_have_several_passkeys_and_a_blank_name_gets_one(): void
    {
        $user = User::factory()->create();

        $this->addPasskey($user, 'Work laptop');
        $this->addPasskey($user, '   ');

        $this->assertEqualsCanonicalizing(['Work laptop', 'Passkey 2'], $user->passkeys()->pluck('name')->all());
    }

    public function test_an_existing_passkey_is_excluded_from_new_registrations(): void
    {
        $user = User::factory()->create();
        $this->addPasskey($user);

        Livewire::test(EditProfile::class)->call('passkeyOptions')->assertReturned(function (string $json) {
            $this->assertCount(1, json_decode($json, true)['excludeCredentials']);

            return true;
        });
    }

    // Signing in

    public function test_a_passkey_signs_in_the_right_person_without_a_2fa_code(): void
    {
        $other = User::factory()->create();
        $user = User::factory()->create();
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        [$device] = $this->addPasskey($user, 'Work laptop');
        $this->addPasskey($other, 'Other');
        auth()->logout();

        $this->get('/admin/bookings')->assertRedirect();

        $options = $this->signInOptions();
        $this->assertSame('required', $options['userVerification']);
        $this->assertSame([], $options['allowCredentials'] ?? []);

        $this->signIn($device->assert($options, $user->id))->assertOk()->assertJsonStructure(['redirect']);

        $this->assertAuthenticatedAs($user);
        $this->get('/admin/bookings')->assertOk();
        $this->assertNotNull($user->passkeys()->sole()->last_used_at);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'kind' => 'passkey_sign_in', 'note' => 'Work laptop']);
        $this->assertDatabaseMissing('security_events', ['user_id' => $other->id, 'kind' => 'passkey_sign_in']);
    }

    public function test_a_second_answer_while_signed_in_is_not_logged_again(): void
    {
        $user = User::factory()->create();
        [$device] = $this->addPasskey($user);
        auth()->logout();

        $this->signIn($device->assert($this->signInOptions(), $user->id))->assertOk();
        $this->signIn($device->assert($this->signInOptions(), $user->id))->assertOk()->assertJsonStructure(['redirect']);

        $this->assertSame(1, SecurityEvent::where('kind', 'passkey_sign_in')->count());
    }

    public function test_a_password_sign_in_still_asks_for_the_2fa_code(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass-1']);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->addPasskey($user);
        auth()->logout();

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'secret-pass-1'])
            ->call('authenticate')
            ->assertNotSet('userUndertakingMultiFactorAuthentication', null);

        $this->assertGuest();
    }

    public function test_a_sign_in_is_refused_without_user_verification_a_valid_challenge_or_the_right_address(): void
    {
        $user = User::factory()->create();
        [$device] = $this->addPasskey($user);
        auth()->logout();

        $device->userVerified = false;
        $this->signIn($device->assert($this->signInOptions(), $user->id))->assertStatus(422);
        $this->assertGuest();

        $device->userVerified = true;
        $this->signIn($device->assert($this->signInOptions(), $user->id, 'https://evil.example'))->assertStatus(422);
        $this->assertGuest();

        // An answer to a challenge the server never made
        $this->signInOptions();
        $this->signIn($device->assert(['challenge' => 'AAAAAAAAAAAAAAAAAAAAAA'], $user->id))->assertStatus(422);
        $this->assertGuest();

        // Without asking for a challenge first
        $this->flushSession();
        $this->signIn($device->assert(['challenge' => 'AAAAAAAAAAAAAAAAAAAAAA'], $user->id))->assertStatus(422);
        $this->assertGuest();
    }

    public function test_a_challenge_works_once(): void
    {
        $user = User::factory()->create();
        [$device] = $this->addPasskey($user);
        auth()->logout();

        $assertion = $device->assert($this->signInOptions(), $user->id);
        $this->signIn($assertion)->assertOk();
        auth()->logout();

        $this->signIn($assertion)->assertStatus(422);
        $this->assertGuest();
    }

    public function test_a_removed_passkey_can_no_longer_sign_in(): void
    {
        $user = User::factory()->create();
        [$device] = $this->addPasskey($user);
        $passkey = $user->passkeys()->sole();

        Livewire::test(EditProfile::class)->callAction('removePasskey', arguments: ['passkey' => $passkey->id]);
        auth()->logout();

        $this->assertSame(0, $user->passkeys()->count());
        $this->signIn($device->assert($this->signInOptions(), $user->id))->assertStatus(422);
        $this->assertGuest();
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'kind' => 'passkey_removed', 'note' => 'Work laptop']);
    }

    public function test_the_passkey_sign_in_is_rate_limited_like_the_password_form(): void
    {
        $user = User::factory()->create();
        [$device] = $this->addPasskey($user);
        auth()->logout();

        foreach (range(1, 5) as $try) {
            $this->signIn($device->assert(['challenge' => 'AAAAAAAAAAAAAAAAAAAAAA'], $user->id))->assertStatus(422);
        }

        $this->signIn($device->assert($this->signInOptions(), $user->id))->assertStatus(429);
        $this->assertGuest();
    }

    // The switch

    public function test_the_switch_hides_the_button_and_blocks_the_routes(): void
    {
        $user = User::factory()->create();
        [$device] = $this->addPasskey($user);
        auth()->logout();

        $this->get('/admin/login')->assertOk()->assertSee('Sign in with a passkey');

        SecuritySetting::instance()->update(['allow_passkeys' => false]);

        $this->get('/admin/login')->assertOk()->assertDontSee('Sign in with a passkey');
        $this->getJson('/admin-passkeys/options')->assertNotFound();
        $this->signIn($device->assert(['challenge' => 'AAAAAAAAAAAAAAAAAAAAAA'], $user->id))->assertNotFound();
        $this->assertGuest();
    }

    public function test_passkey_sign_in_is_on_by_default(): void
    {
        $this->assertTrue(SecuritySetting::instance()->allow_passkeys);
        $this->assertTrue(SecuritySetting::passkeysAllowed());
    }

    public function test_a_super_admin_changes_the_switch_in_security_settings(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(SecuritySettings::class)
            ->assertFormSet(['allow_passkeys' => true])
            ->fillForm(['allow_passkeys' => false])
            ->call('save');

        $this->assertFalse(SecuritySetting::passkeysAllowed());
    }

    // Two-factor for everyone

    public function test_a_passkey_counts_as_two_factor_when_it_is_required_for_everyone(): void
    {
        $user = User::factory()->create();
        SecuritySetting::instance()->update(['require_two_factor' => true]);
        $this->actingAs($user);
        $setUp = Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl();

        $this->get('/admin/bookings')->assertRedirect($setUp);

        $this->addPasskey($user);
        $this->get('/admin/bookings')->assertOk();

        // Not while passkeys are switched off: then it is not a way to sign in
        SecuritySetting::instance()->update(['allow_passkeys' => false]);
        $this->get('/admin/bookings')->assertRedirect($setUp);
    }

    // Who can touch what

    public function test_nobody_can_remove_or_rename_someone_elses_passkey(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $this->addPasskey($theirs, 'Theirs');
        $passkey = $theirs->passkeys()->sole();

        $this->actingAs($mine);
        Livewire::test(EditProfile::class)
            ->callAction('removePasskey', arguments: ['passkey' => $passkey->id])
            ->callAction('renamePasskey', data: ['name' => 'Taken'], arguments: ['passkey' => $passkey->id]);

        $this->assertSame('Theirs', $passkey->fresh()->name);
        $this->assertDatabaseMissing('security_events', ['kind' => 'passkey_removed']);
    }

    public function test_a_passkey_is_renamed(): void
    {
        $user = User::factory()->create();
        $this->addPasskey($user, 'Old name');

        Livewire::test(EditProfile::class)
            ->callAction('renamePasskey', data: ['name' => 'Front desk iPad'], arguments: ['passkey' => $user->passkeys()->sole()->id])
            ->assertHasNoActionErrors();

        $this->assertSame('Front desk iPad', $user->passkeys()->sole()->name);
    }

    public function test_a_super_admin_removes_a_lost_devices_passkeys_and_it_is_logged(): void
    {
        $member = User::factory()->operations()->create();
        $this->addPasskey($member, 'Lost phone');
        $this->addPasskey($member, 'Laptop');
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner);
        Livewire::test(ListUsers::class)
            ->assertCanRenderTableColumn('passkeys_count')
            ->assertTableColumnStateSet('passkeys_count', 2, $member)
            ->callTableAction('removePasskeys', $member);

        $this->assertSame(0, $member->passkeys()->count());
        $event = SecurityEvent::where('kind', 'passkeys_cleared')->sole();
        $this->assertSame($member->id, $event->user_id);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame('2 passkeys', $event->note);

        Livewire::test(ListUsers::class)->assertTableActionHidden('removePasskeys', $member);
    }

    public function test_only_a_super_admin_can_remove_passkeys_from_users(): void
    {
        $member = User::factory()->operations()->create();
        $this->addPasskey($member);

        $this->actingAs(User::factory()->salesAdmin()->create());
        $this->get('/admin/users')->assertForbidden();
        $this->assertSame(1, $member->passkeys()->count());
    }

    // The profile

    public function test_the_profile_lists_passkeys_and_a_read_only_security_trail_of_ones_own(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$device] = $this->addPasskey($user, 'Work laptop');
        $this->addPasskey($other, 'Someone elses');
        auth()->logout();
        $this->signIn($device->assert($this->signInOptions(), $user->id))->assertOk();

        $this->get('/admin/profile')->assertOk()
            ->assertSee('Work laptop')
            ->assertSee('Passkey added')
            ->assertSee('Signed in with a passkey')
            ->assertDontSee('Someone elses');
    }

    public function test_a_passkey_row_belongs_to_one_user_and_goes_with_them(): void
    {
        $user = User::factory()->create();
        $this->addPasskey($user);

        $user->delete();

        $this->assertSame(0, Passkey::count());
    }
}
