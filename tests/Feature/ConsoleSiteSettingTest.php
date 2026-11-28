<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StaffApi;
use Tests\TestCase;

class ConsoleSiteSettingTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const SITE = '/console/api/owner/site-settings';

    public function test_signed_out_is_401_other_roles_403_and_closed_while_viewing(): void
    {
        $this->withoutVite();
        $this->getJson(self::SITE)->assertUnauthorized();
        $this->postJson(self::SITE, ['tagline' => 'x'])->assertUnauthorized();

        $this->actingAs($this->staffUser('sales_admin', Branch::factory()->create(), ['permissions' => ['settings']]));
        $this->getJson(self::SITE)->assertForbidden();
        $this->postJson(self::SITE, ['tagline' => 'x'])->assertForbidden();

        $admin = User::factory()->owner()->create();
        $this->actingAs($admin)->withSession([Impersonation::IMPERSONATOR => User::factory()->owner()->create()->id, Impersonation::TARGET => $admin->id]);
        $this->assertContains($this->postJson(self::SITE, ['tagline' => 'x'])->getStatusCode(), [403, 419]);
        $this->assertNull(SiteSetting::instance()->tagline);
    }

    public function test_the_texts_and_pictures_are_saved_and_the_secret_never_leaves(): void
    {
        Storage::fake('public');
        SiteSetting::instance()->update(['google_client_id' => 'id.apps.googleusercontent.com', 'google_client_secret' => 'GOCSPX-never-send']);
        $this->actingAs(User::factory()->owner()->create());

        $response = $this->post(self::SITE, ['tagline' => 'Shine', 'phone' => '0917 000 0000', 'facebookUrl' => 'https://www.facebook.com/826', 'heroImage' => UploadedFile::fake()->image('hero.jpg', 800, 400), 'google_client_secret' => 'overwritten'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('tagline', 'Shine');

        $settings = SiteSetting::instance();
        $this->assertStringEndsWith('.webp', $settings->hero_image);
        Storage::disk('public')->assertExists($settings->hero_image);
        $this->assertNotNull($response->json('heroImageUrl'));
        $this->assertSame('GOCSPX-never-send', $settings->google_client_secret);
        $this->assertStringNotContainsString('GOCSPX', $this->getJson(self::SITE)->getContent());

        // A save without a new picture keeps it; asking removes it and its file
        $this->postJson(self::SITE, ['tagline' => ''])->assertOk()->assertJsonPath('tagline', null);
        $this->assertSame($settings->hero_image, SiteSetting::instance()->hero_image);
        $this->postJson(self::SITE, ['removeHeroImage' => true])->assertOk()->assertJsonPath('heroImageUrl', null);
        Storage::disk('public')->assertMissing($settings->hero_image);
        // A field not sent is left alone
        $this->assertSame('0917 000 0000', SiteSetting::instance()->phone);
    }

    public function test_what_the_form_refuses(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->post(self::SITE, [
            'tagline' => str_repeat('a', 121),
            'intro' => str_repeat('a', 401),
            'phone' => 'call me',
            'contactEmail' => 'nope',
            'facebookUrl' => 'http://facebook.com/826',
            'instagramUrl' => 'javascript:alert(1)',
            'heroImage' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tagline', 'intro', 'phone', 'contactEmail', 'facebookUrl', 'instagramUrl', 'heroImage']);
    }
}
