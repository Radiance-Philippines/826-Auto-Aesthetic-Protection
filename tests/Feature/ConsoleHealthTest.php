<?php

namespace Tests\Feature;

use App\Models\AppRelease;
use App\Models\Branch;
use App\Models\MailSetting;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StaffApi;
use Tests\TestCase;

class ConsoleHealthTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const HEALTH = '/console/api/system/health';

    public function test_signed_out_is_401_and_other_roles_403(): void
    {
        $this->withoutVite();
        $this->getJson(self::HEALTH)->assertUnauthorized();

        $this->actingAs($this->staffUser('sales_admin', Branch::factory()->create(), ['permissions' => ['settings']]));
        $this->getJson(self::HEALTH)->assertForbidden();
    }

    public function test_yes_or_no_and_the_version_and_never_a_secret(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
        $this->actingAs(User::factory()->owner()->create());

        $this->getJson(self::HEALTH)->assertOk()->assertJsonPath('version', null)->assertJsonPath('checks.0', ['label' => 'Email is set up', 'on' => false]);

        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'mailbox-secret']);
        SiteSetting::instance()->update(['google_client_id' => 'web-id', 'google_client_secret' => 'GOCSPX-never-send']);
        AppRelease::factory()->create(['version' => '0.4.0', 'is_published' => true]);

        $response = $this->getJson(self::HEALTH)->assertJsonPath('version', '0.4.0')->assertJsonPath('checks.0.on', true)->assertJsonPath('checks.2.on', true);

        foreach (['mailbox-secret', 'GOCSPX', 'web-id'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }
}
