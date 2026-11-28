<?php

namespace Tests\Feature;

use App\Models\BookingSetting;
use App\Models\Branch;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StaffApi;
use Tests\TestCase;

class ConsoleBookingRuleTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const RULES = '/console/api/owner/booking-rules';

    /**
     * @return array<string, mixed>
     */
    private function fields(array $with = []): array
    {
        return ['cancelCutoffHours' => 12, 'rescheduleEnabled' => true, 'rescheduleCutoffHours' => 6, 'maxReschedules' => 3, 'maxUnpaidHoldsPerGuest' => 1, 'maxUpcomingPerGuest' => 0, 'policyText' => 'Fees are not refunded.', ...$with];
    }

    public function test_signed_out_is_401_other_roles_403_and_closed_while_viewing(): void
    {
        $this->withoutVite();
        $this->getJson(self::RULES)->assertUnauthorized();
        $this->postJson(self::RULES, $this->fields())->assertUnauthorized();

        $this->actingAs($this->staffUser('branch_manager', Branch::factory()->create(), ['permissions' => ['settings']]));
        $this->getJson(self::RULES)->assertForbidden();
        $this->postJson(self::RULES, $this->fields())->assertForbidden();

        $admin = User::factory()->owner()->create();
        $this->actingAs($admin)->withSession([Impersonation::IMPERSONATOR => User::factory()->owner()->create()->id, Impersonation::TARGET => $admin->id]);
        $this->assertContains($this->postJson(self::RULES, $this->fields())->getStatusCode(), [403, 419]);
        $this->assertNotSame(12, BookingSetting::instance()->cancel_cutoff_hours);
    }

    public function test_the_rules_are_saved_and_the_cache_follows(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->postJson(self::RULES, $this->fields())->assertOk()->assertJson($this->fields());
        $this->assertSame([12, 6, 3, 'Fees are not refunded.'], [BookingSetting::current()->cancel_cutoff_hours, BookingSetting::current()->reschedule_cutoff_hours, BookingSetting::current()->max_reschedules, BookingSetting::current()->policy_text]);

        // Moving off: its limits are hidden, and kept
        $this->postJson(self::RULES, $this->fields(['rescheduleEnabled' => false, 'rescheduleCutoffHours' => 999, 'maxReschedules' => null, 'policyText' => '']))
            ->assertOk()
            ->assertJsonPath('rescheduleCutoffHours', 6)
            ->assertJsonPath('maxReschedules', 3)
            ->assertJsonPath('policyText', null);
        $this->getJson(self::RULES)->assertJsonPath('rescheduleEnabled', false);
    }

    public function test_what_the_form_refuses(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->postJson(self::RULES, [])->assertUnprocessable()->assertJsonValidationErrors(['cancelCutoffHours', 'rescheduleEnabled', 'maxUnpaidHoldsPerGuest', 'maxUpcomingPerGuest']);
        $this->postJson(self::RULES, $this->fields(['cancelCutoffHours' => 721, 'rescheduleCutoffHours' => -1, 'maxReschedules' => 0, 'maxUnpaidHoldsPerGuest' => 21, 'maxUpcomingPerGuest' => 1.5, 'policyText' => str_repeat('a', 4001)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cancelCutoffHours', 'rescheduleCutoffHours', 'maxReschedules', 'maxUnpaidHoldsPerGuest', 'maxUpcomingPerGuest', 'policyText']);
    }
}
