<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StaffApi;
use Tests\TestCase;

class ConsoleReferralSettingTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const REFERRAL = '/console/api/owner/referral';

    /**
     * @return array<string, mixed>
     */
    private function fields(array $with = []): array
    {
        return ['enabled' => true, 'discountType' => 'percent', 'discountValue' => 10, 'ownerPoints' => 50, 'pesoPerPoint' => null, ...$with];
    }

    public function test_signed_out_is_401_other_roles_403_and_closed_while_viewing(): void
    {
        $this->withoutVite();
        $this->getJson(self::REFERRAL)->assertUnauthorized();
        $this->postJson(self::REFERRAL, $this->fields())->assertUnauthorized();

        $this->actingAs($this->staffUser('sales_admin', Branch::factory()->create(), ['permissions' => ['settings']]));
        $this->getJson(self::REFERRAL)->assertForbidden();
        $this->postJson(self::REFERRAL, $this->fields())->assertForbidden();

        $admin = User::factory()->owner()->create();
        $this->actingAs($admin)->withSession([Impersonation::IMPERSONATOR => User::factory()->owner()->create()->id, Impersonation::TARGET => $admin->id]);
        $this->assertContains($this->postJson(self::REFERRAL, $this->fields())->getStatusCode(), [403, 419]);
        $this->assertFalse((bool) ReferralSetting::instance()->enabled);
    }

    public function test_the_referral_is_saved_and_checked(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->getJson(self::REFERRAL)->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('types.0', ['value' => 'fixed', 'label' => 'Pesos off']);
        $this->postJson(self::REFERRAL, $this->fields())->assertOk()->assertJsonPath('discountValue', 10)->assertJsonPath('pesoPerPoint', null);
        $this->assertSame('10% off', ReferralSetting::current()->label());

        $this->postJson(self::REFERRAL, [])->assertUnprocessable()->assertJsonValidationErrors(['enabled', 'discountType']);
        $this->postJson(self::REFERRAL, $this->fields(['discountValue' => 101, 'ownerPoints' => -1, 'pesoPerPoint' => 0]))->assertUnprocessable()->assertJsonValidationErrors(['discountValue', 'ownerPoints', 'pesoPerPoint']);
        $this->postJson(self::REFERRAL, $this->fields(['discountType' => 'fixed', 'discountValue' => 101]))->assertOk();
        $this->postJson(self::REFERRAL, $this->fields(['discountValue' => null]))->assertUnprocessable()->assertJsonValidationErrors(['discountValue' => 'Set the discount before accepting codes.']);
        // Off: no discount needed
        $this->postJson(self::REFERRAL, $this->fields(['enabled' => false, 'discountValue' => null]))->assertOk()->assertJsonPath('discountValue', null);
    }
}
