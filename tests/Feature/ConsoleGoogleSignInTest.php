<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StaffApi;
use Tests\TestCase;

class ConsoleGoogleSignInTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const GOOGLE = '/console/api/system/google-sign-in';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
    }

    public function test_signed_out_is_401_other_roles_403_and_closed_while_viewing(): void
    {
        $this->withoutVite();
        $this->getJson(self::GOOGLE)->assertUnauthorized();
        $this->postJson(self::GOOGLE, ['clientId' => 'x'])->assertUnauthorized();
        $this->postJson(self::GOOGLE.'/turn-off')->assertUnauthorized();

        $this->actingAs($this->staffUser('sales_admin', Branch::factory()->create(), ['permissions' => ['settings']]));
        $this->getJson(self::GOOGLE)->assertForbidden();
        $this->postJson(self::GOOGLE, ['clientId' => 'x'])->assertForbidden();

        $admin = User::factory()->owner()->create();
        $this->actingAs($admin)->withSession([Impersonation::IMPERSONATOR => User::factory()->owner()->create()->id, Impersonation::TARGET => $admin->id]);
        $this->assertContains($this->postJson(self::GOOGLE, ['clientId' => 'x'])->getStatusCode(), [403, 419]);
        $this->assertNull(SiteSetting::instance()->google_client_id);
    }

    public function test_the_secret_is_saved_kept_when_blank_and_never_sent_back(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->postJson(self::GOOGLE, ['clientId' => 'web.apps.googleusercontent.com'])
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('said.title', 'Saved');

        $on = $this->postJson(self::GOOGLE, ['clientId' => 'web.apps.googleusercontent.com', 'clientSecret' => 'GOCSPX-never-send', 'appleClientId' => 'ph.example.app'])
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('hasSecret', true)
            ->assertJsonPath('said.title', 'Google sign-in is on')
            ->assertJsonMissingPath('clientSecret');
        $kept = $this->postJson(self::GOOGLE, ['clientId' => 'web.apps.googleusercontent.com', 'clientSecret' => ''])->assertOk();
        $read = $this->getJson(self::GOOGLE)->assertOk()->assertJsonPath('redirectUrl', route('auth.google.callback'));

        $this->assertSame('GOCSPX-never-send', SiteSetting::instance()->google_client_secret);

        foreach ([$on, $kept, $read] as $response) {
            $this->assertStringNotContainsString('GOCSPX', $response->getContent());
        }

        $this->postJson(self::GOOGLE.'/turn-off')->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('clientId', null)->assertJsonPath('appleClientId', 'ph.example.app');
        $this->assertNull(SiteSetting::instance()->google_client_secret);
        $this->postJson(self::GOOGLE.'/turn-off')->assertForbidden();
    }

    public function test_what_the_form_refuses(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $long = str_repeat('a', 256);
        $this->postJson(self::GOOGLE, ['clientId' => $long, 'clientSecret' => $long, 'androidClientId' => $long, 'iosClientId' => $long, 'appleClientId' => $long])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['clientId', 'clientSecret', 'androidClientId', 'iosClientId', 'appleClientId']);
    }
}
