<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MetaSetting;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StaffApi;
use Tests\TestCase;

class ConsoleMetaSettingTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const META = '/console/api/system/meta';

    public function test_signed_out_is_401_other_roles_403_and_closed_while_viewing(): void
    {
        $this->withoutVite();
        $token = MetaSetting::instance()->webhook_verify_token;
        $this->getJson(self::META)->assertUnauthorized();
        $this->postJson(self::META, ['enabled' => true])->assertUnauthorized();
        $this->postJson(self::META.'/verify-token')->assertUnauthorized();
        $this->postJson(self::META.'/verify-token/reveal')->assertUnauthorized();

        $this->actingAs($this->staffUser('sales_admin', Branch::factory()->create(), ['permissions' => ['settings']]));
        $this->getJson(self::META)->assertForbidden();
        $this->postJson(self::META.'/verify-token/reveal')->assertForbidden();
        $this->postJson(self::META.'/verify-token')->assertForbidden();

        $admin = User::factory()->owner()->create();
        $this->actingAs($admin)->withSession([Impersonation::IMPERSONATOR => User::factory()->owner()->create()->id, Impersonation::TARGET => $admin->id]);
        $this->assertContains($this->postJson(self::META.'/verify-token')->getStatusCode(), [403, 419]);
        $this->assertSame($token, MetaSetting::instance()->webhook_verify_token);
        $this->assertNull(MetaSetting::instance()->verify_token_shown_at);
    }

    public function test_the_token_is_sent_once_and_the_secret_never(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $token = MetaSetting::instance()->webhook_verify_token;

        // Reading the screen's record does not use up the one look
        $this->getJson(self::META)->assertOk()->assertJsonPath('callbackUrl', route('webhooks.meta'))->assertJsonMissingPath('verifyToken');
        $this->assertStringNotContainsString($token, $this->getJson(self::META)->getContent());
        $this->postJson(self::META.'/verify-token/reveal')->assertOk()->assertExactJson(['verifyToken' => $token]);
        $this->postJson(self::META.'/verify-token/reveal')->assertExactJson(['verifyToken' => null]);

        $new = $this->postJson(self::META.'/verify-token')->assertOk()->json('verifyToken');
        $this->assertNotSame($token, $new);
        $this->assertSame($new, MetaSetting::instance()->webhook_verify_token);
        $this->postJson(self::META.'/verify-token/reveal')->assertExactJson(['verifyToken' => null]);

        $saved = $this->postJson(self::META, ['appId' => '1234567890', 'appSecret' => 'meta-app-secret', 'enabled' => true])->assertOk()->assertJsonPath('hasSecret', true);
        // A blank secret keeps the saved one
        $kept = $this->postJson(self::META, ['appId' => '1234567890', 'appSecret' => '', 'enabled' => false])->assertOk()->assertJsonPath('enabled', false);
        $this->assertSame('meta-app-secret', MetaSetting::instance()->app_secret);

        foreach ([$saved, $kept, $this->getJson(self::META)] as $response) {
            $this->assertStringNotContainsString('meta-app-secret', $response->getContent());
            $this->assertStringNotContainsString($new, $response->getContent());
        }
    }

    public function test_what_the_form_refuses(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->postJson(self::META, [])->assertUnprocessable()->assertJsonValidationErrors(['enabled']);
        $this->postJson(self::META, ['appId' => 'abc', 'appSecret' => str_repeat('a', 1001), 'enabled' => true])->assertUnprocessable()->assertJsonValidationErrors(['appId' => 'The App ID is digits only.', 'appSecret']);
    }
}
