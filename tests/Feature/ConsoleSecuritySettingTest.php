<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StaffApi;
use Tests\TestCase;

class ConsoleSecuritySettingTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const SECURITY = '/console/api/system/security';

    public function test_signed_out_is_401_other_roles_403_and_closed_while_viewing(): void
    {
        $this->withoutVite();
        $body = ['requireTwoFactor' => true, 'allowPasskeys' => false];
        $this->getJson(self::SECURITY)->assertUnauthorized();
        $this->postJson(self::SECURITY, $body)->assertUnauthorized();

        $this->actingAs($this->staffUser('sales_admin', Branch::factory()->create(), ['permissions' => ['settings']]));
        $this->getJson(self::SECURITY)->assertForbidden();
        $this->postJson(self::SECURITY, $body)->assertForbidden();

        $admin = User::factory()->owner()->create();
        $this->actingAs($admin)->withSession([Impersonation::IMPERSONATOR => User::factory()->owner()->create()->id, Impersonation::TARGET => $admin->id]);
        $this->assertContains($this->postJson(self::SECURITY, $body)->getStatusCode(), [403, 419]);
        $this->assertTrue(SecuritySetting::passkeysAllowed());
    }

    public function test_the_switches_are_saved_and_two_factor_then_applies(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->getJson(self::SECURITY)->assertOk()->assertExactJson(['requireTwoFactor' => false, 'allowPasskeys' => true]);
        $this->postJson(self::SECURITY, [])->assertUnprocessable()->assertJsonValidationErrors(['requireTwoFactor', 'allowPasskeys']);
        $this->postJson(self::SECURITY, ['requireTwoFactor' => true, 'allowPasskeys' => false])->assertOk()->assertJsonPath('allowPasskeys', false);

        $this->assertFalse(SecuritySetting::passkeysAllowed());
        // The owner here has none, so the next call asks for it
        $this->getJson(self::SECURITY)->assertForbidden()->assertJsonPath('message', 'Set up two-factor sign-in first.');
    }
}
