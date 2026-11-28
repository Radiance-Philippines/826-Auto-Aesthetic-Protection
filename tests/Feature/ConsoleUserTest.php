<?php

namespace Tests\Feature;

use App\Mail\Invitation;
use App\Models\Branch;
use App\Models\MailSetting;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Users: super admins only, closed while viewing as someone.
 */
class ConsoleUserTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const USERS = '/console/api/owner/users';

    private Branch $cainta;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
        $this->owner = User::factory()->owner()->create(['name' => 'Boss']);
        $this->member = $this->staffUser('operations', $this->cainta, ['name' => 'Jun Detailer']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return [
            'list' => ['getJson', ''],
            'invite' => ['postJson', ''],
            'update' => ['patchJson', '/{id}'],
            'delete' => ['deleteJson', '/{id}'],
            'resend' => ['postJson', '/{id}/resend-invite'],
            'reset 2FA' => ['postJson', '/{id}/reset-two-factor'],
            'passkeys' => ['postJson', '/{id}/remove-passkeys'],
            'view as' => ['postJson', '/{id}/view-as'],
        ];
    }

    private function url(string $path): string
    {
        return self::USERS.str_replace('{id}', (string) $this->member->id, $path);
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->{$method}($this->url($path))->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_only_a_super_admin_gets_in(string $method, string $path): void
    {
        $this->actingAs($this->staffUser('sales_admin', $this->cainta, ['permissions' => ['settings']]));

        $this->{$method}($this->url($path))->assertForbidden();
        $this->assertSame('Jun Detailer', $this->member->fresh()->name);
    }

    #[DataProvider('endpoints')]
    public function test_it_is_closed_while_viewing_as_someone(string $method, string $path): void
    {
        $second = User::factory()->owner()->create();
        $this->actingAs($this->owner)->withSession([Impersonation::IMPERSONATOR => $second->id, Impersonation::TARGET => $this->owner->id]);

        // A view of a super admin ends on the spot (419); either way nothing happens
        $this->assertContains($this->{$method}($this->url($path))->getStatusCode(), [403, 419]);
        $this->assertNotNull($this->member->fresh());
    }

    public function test_the_list_never_carries_secrets(): void
    {
        $this->member->forceFill(['app_authentication_secret' => 'TOPSECRETSEED', 'app_authentication_recovery_codes' => ['recovery-one']])->save();

        $response = $this->actingAs($this->owner)->getJson(self::USERS)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $row = collect($response->json('data'))->firstWhere('id', $this->member->id);
        $this->assertSame(['Cainta', true, false, 'Never', true, true], [$row['branchNames'], $row['twoFactor'], $row['ownList'], $row['lastViewedAs'], $row['canViewAs'], $row['canDelete']]);
        $self = collect($response->json('data'))->firstWhere('id', $this->owner->id);
        $this->assertSame(['Every branch', true, false, false], [$self['branchNames'], $self['isSelf'], $self['canViewAs'], $self['canDelete']]);

        foreach (['TOPSECRETSEED', 'recovery-one', $this->member->password, 'remember_token', 'app_authentication'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_a_team_member_is_invited_with_branches(): void
    {
        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'mailbox-secret']);
        $this->actingAs($this->owner);

        $this->postJson(self::USERS, ['name' => 'Mate', 'email' => 'Mate@Example.com', 'role' => 'sales_admin', 'branches' => [$this->cainta->id]])
            ->assertCreated()
            ->assertJsonPath('message', 'Invite sent to mate@example.com');

        $mate = User::where('email', 'mate@example.com')->sole();
        $this->assertSame([$this->cainta->id], $mate->branchIds());
        $this->assertTrue($mate->isPendingInvite());
        Mail::assertSent(Invitation::class);

        $this->postJson(self::USERS."/{$mate->id}/resend-invite")->assertOk()->assertJsonPath('message', 'New invite sent to mate@example.com');
        Mail::assertSentCount(2);
        $this->postJson(self::USERS."/{$this->member->id}/resend-invite")->assertForbidden();
    }

    public function test_what_the_invite_refuses(): void
    {
        $this->actingAs($this->owner);

        $this->postJson(self::USERS, [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'role', 'branches']);
        $this->postJson(self::USERS, ['name' => 'Mate', 'email' => $this->member->email, 'role' => 'nobody', 'branches' => [999999]])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'role', 'branches.0']);
        // The same address in other letters is already taken
        $this->postJson(self::USERS, ['name' => 'Mate', 'email' => strtoupper($this->member->email), 'role' => 'super_admin'])->assertUnprocessable()->assertJsonValidationErrors(['email']);
        // No mailbox saved: no account either
        $this->postJson(self::USERS, ['name' => 'Mate', 'email' => 'mate@example.com', 'role' => 'super_admin'])
            ->assertUnprocessable()->assertJsonPath('message', 'Email is not set up yet, so the invite can’t be sent. Add the mailbox password in Email settings first.');
        $this->assertDatabaseMissing('users', ['email' => 'mate@example.com']);
    }

    public function test_an_account_is_edited_and_only_a_changed_access_list_is_kept(): void
    {
        $this->actingAs($this->owner);
        $other = Branch::factory()->create();
        $url = self::USERS."/{$this->member->id}";
        $fields = ['name' => 'Jun', 'email' => $this->member->email, 'role' => 'operations', 'branches' => [$other->id]];

        $this->patchJson($url, [...$fields, 'access' => ['bookings.view', 'queue.update', 'customers.view', 'warranty', 'dashboard']])->assertOk();
        $this->assertNull($this->member->fresh()->permissions);
        $this->assertSame([$other->id], $this->member->fresh()->branches->pluck('id')->all());

        $this->patchJson($url, [...$fields, 'access' => ['dashboard']])->assertOk();
        $this->assertSame(['dashboard'], $this->member->fresh()->permissions);

        $this->patchJson($url, [...$fields, 'access' => ['users']])->assertUnprocessable()->assertJsonValidationErrors(['access.0']);
        $this->patchJson($url, ['name' => '', 'email' => $this->owner->email, 'role' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'role']);
    }

    public function test_nobody_demotes_themselves_or_the_last_super_admin(): void
    {
        $this->actingAs($this->owner);

        // What the form sends for your own role is ignored
        $this->patchJson(self::USERS."/{$this->owner->id}", ['name' => 'Boss', 'email' => $this->owner->email, 'role' => 'operations'])->assertOk();
        $this->assertTrue($this->owner->fresh()->isOwner());
        $this->deleteJson(self::USERS."/{$this->owner->id}")->assertForbidden();

        $second = User::factory()->owner()->create();
        $this->patchJson(self::USERS."/{$second->id}", ['name' => 'Two', 'email' => $second->email, 'role' => 'operations'])->assertOk();
        $this->assertFalse($second->fresh()->isOwner());

        $this->deleteJson(self::USERS."/{$second->id}")->assertNoContent();
        $this->assertNull($second->fresh());
        $this->deleteJson(self::USERS."/{$second->id}")->assertNotFound();
    }

    public function test_two_factor_and_passkeys_are_cleared(): void
    {
        $this->actingAs($this->owner);
        $url = self::USERS."/{$this->member->id}";

        $this->postJson("{$url}/reset-two-factor")->assertForbidden();
        $this->postJson("{$url}/remove-passkeys")->assertForbidden();

        $this->member->forceFill(['has_email_authentication' => true])->save();
        DB::table('passkeys')->insert(['authenticatable_id' => $this->member->id, 'name' => 'Phone', 'credential_id' => 'abc', 'data' => '{}']);

        $this->postJson("{$url}/reset-two-factor")->assertOk()->assertJsonPath('message', '2FA reset for Jun Detailer');
        $this->postJson("{$url}/remove-passkeys")->assertOk();

        $this->assertFalse($this->member->fresh()->hasTwoFactor());
        $this->assertSame(0, $this->member->passkeys()->count());
        $this->assertDatabaseHas('security_events', ['kind' => SecurityEvent::PASSKEYS_CLEARED, 'user_id' => $this->member->id, 'actor_id' => $this->owner->id, 'note' => '1 passkey']);
    }

    public function test_viewing_as_someone_starts_in_their_console_and_stops_back_on_users(): void
    {
        $this->actingAs($this->owner);

        $this->postJson(self::USERS."/{$this->owner->id}/view-as")->assertUnprocessable()->assertJsonPath('message', 'That is you.');

        $this->postJson(self::USERS."/{$this->member->id}/view-as")->assertOk()->assertJsonPath('url', url('/console/operations'));
        $this->assertSame($this->owner->id, session(Impersonation::IMPERSONATOR));
        $this->assertDatabaseHas('security_events', ['kind' => SecurityEvent::IMPERSONATION_STARTED, 'user_id' => $this->member->id, 'actor_id' => $this->owner->id]);
        $this->getJson('/console/api/operations/me')->assertOk()->assertJsonPath('impersonating.name', 'Jun Detailer');

        $this->from(url('/console/operations/queue'))->post(route('impersonation.stop'))->assertRedirect(url('/console/owner/users'));
        $this->getJson(self::USERS)->assertOk();
    }
}
