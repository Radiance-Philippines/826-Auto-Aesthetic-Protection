<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteUnlockController;
use App\Http\Middleware\AppPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * With SITE_PIN set, only the team sees the website; everything else stays open.
 */
class UnderConstructionTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '482913';

    private const PAGES = ['/', '/gallery', '/book', '/download', '/r/ABC123', '/no-such-page'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_without_a_pin_the_site_is_open(): void
    {
        config(['app.site_pin' => null]);

        $this->get('/')->assertOk()->assertDontSee('Under construction')->assertSee('APP826', false);
        $this->post('/unlock', ['pin' => self::PIN])->assertNotFound();
    }

    public function test_pages_are_closed_until_the_pin_and_close_again_when_it_changes(): void
    {
        config(['app.site_pin' => self::PIN]);

        foreach (self::PAGES as $page) {
            $this->get($page)->assertOk()->assertSee('Under construction')->assertDontSee('APP826', false);
        }

        // The car photo's licence asks for the credit: don't drop it while the photo is there
        $this->get('/')->assertSee('Damian B Oh')->assertSee('creativecommons.org/licenses/by-sa/4.0', false);

        // Visitors don't see the PIN field; after a wrong PIN it stays up with the reason
        $this->assertMatchesRegularExpression('/<form[^>]*data-pin\s+hidden/', $this->get('/')->getContent());
        $this->from('/gallery')->post('/unlock', ['pin' => '000000'])->assertRedirect('/gallery')->assertSessionHasErrors('pin');
        $wrong = $this->followingRedirects()->from('/gallery')->post('/unlock', ['pin' => '000000'])->assertSee('That PIN is not right.');
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*hidden/', $wrong->getContent());
        $this->followingRedirects = false;

        $this->from('/gallery')->post('/unlock', ['pin' => self::PIN])->assertRedirect('/gallery');
        $this->get('/gallery')->assertOk()->assertDontSee('Under construction')->assertSee('APP826', false);

        config(['app.site_pin' => '771204']);
        $this->get('/gallery')->assertSee('Under construction');
    }

    public function test_guessing_is_stopped_even_with_the_right_pin_afterwards(): void
    {
        config(['app.site_pin' => self::PIN]);

        for ($try = 0; $try < SiteUnlockController::TRIES; $try++) {
            $this->post('/unlock', ['pin' => '000000']);
        }

        $this->post('/unlock', ['pin' => self::PIN])->assertSessionHasErrors('pin')->assertSessionMissing('site_unlocked');
    }

    public function test_the_admin_and_webhooks_are_not_behind_the_pin(): void
    {
        config(['app.site_pin' => self::PIN]);

        $this->get('/owner/login')->assertOk()->assertDontSee('Under construction');
        $this->get('/webhooks/meta')->assertDontSee('Under construction');
        $this->get('/robots.txt')->assertDontSee('Under construction');
    }

    public function test_the_customer_app_needs_the_pin_too(): void
    {
        config(['app.site_pin' => self::PIN, 'app.demo' => true]);
        $pin = [AppPin::HEADER => self::PIN];

        $this->getJson('/api/app/catalog')->assertStatus(423)->assertJsonPath('code', 'pin_required');
        $this->postJson('/api/app/sign-in/demo', [])->assertStatus(423);
        $this->getJson('/api/app/latest')->assertOk();

        $token = $this->postJson('/api/app/sign-in/demo', [], $pin)->assertOk()->json('token');
        $this->getJson('/api/app/catalog', $pin)->assertOk();

        // A token from before the lock is no way round it, on the app's calls or the site's
        $this->flushHeaders();
        $this->getJson('/api/app/me', ['Authorization' => "Bearer {$token}"])->assertStatus(423);
        $this->getJson('/api/my-bookings', ['Authorization' => "Bearer {$token}"])->assertStatus(423);
        $this->getJson('/api/my-bookings', ['Authorization' => "Bearer {$token}", ...$pin])->assertOk();
    }

    public function test_guessing_the_pin_from_the_app_is_stopped(): void
    {
        config(['app.site_pin' => self::PIN]);

        for ($try = 0; $try < SiteUnlockController::TRIES; $try++) {
            $this->getJson('/api/app/catalog', [AppPin::HEADER => '000000'])->assertStatus(423);
        }

        $this->getJson('/api/app/catalog', [AppPin::HEADER => self::PIN])->assertStatus(423)->assertJsonPath('message', 'Too many tries. Wait a minute, then try again.');
    }

    public function test_the_apk_downloads_are_behind_the_pin(): void
    {
        config(['app.site_pin' => self::PIN]);

        foreach (['/download/826-auto-1.0.0.apk', '/download/826-team-1.0.0.apk', '/download/826-owner-1.0.0.apk'] as $apk) {
            $this->get($apk)->assertOk()->assertSee('Under construction');
        }

        // Past the gate there is no such release in this test
        $this->post('/unlock', ['pin' => self::PIN]);
        $this->get('/download/826-auto-1.0.0.apk')->assertNotFound();
    }
}
