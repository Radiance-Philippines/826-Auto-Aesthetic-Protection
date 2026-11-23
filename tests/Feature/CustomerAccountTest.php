<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\MailSetting;
use App\Models\PaymentSetting;
use App\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsShop;
use Tests\TestCase;

/**
 * The customer's sign-in page and /account pages (the React shell), the
 * session-cookie profile endpoints behind them, and the shared referral link.
 */
class CustomerAccountTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
        $this->withoutVite();
        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->buildShop();
        SiteSetting::instance()->update(['google_client_id' => null, 'google_client_secret' => null]);
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);
        PaymentSetting::instance()->update(['reservation_fee_enabled' => true, 'amount_value' => 500, 'hold_minutes' => 30, 'gcash_enabled' => true, 'gcash_number' => '0917 000 0000']);
        $this->guest = Guest::factory()->create(['name' => 'Maria Santos', 'phone' => '0917 123 4567']);
    }

    public function test_the_login_and_account_pages_render_the_shell_and_stay_out_of_search(): void
    {
        foreach (['/login', '/account', '/account/garage', '/account/garage/12', '/account/rewards', '/account/warranty', '/account/me'] as $page) {
            $this->get($page)->assertOk()
                ->assertSee('<div id="app"></div>', escape: false)
                ->assertSee('<meta name="robots" content="noindex" />', escape: false);
        }

        $this->get('/account/nope')->assertNotFound();
        $this->get('/account/garage/abc')->assertNotFound();
    }

    public function test_the_login_page_knows_which_sign_ins_are_on(): void
    {
        $auth = $this->get('/login')->viewData('app')['auth'];
        $this->assertFalse($auth['googleEnabled']);
        $this->assertNull($auth['localSignInUrl']);

        config(['app.demo' => true]);
        $this->assertNotNull($this->get('/login')->viewData('app')['auth']['localSignInUrl']);
        config(['app.demo' => false]);

        SiteSetting::instance()->update(['google_client_id' => 'client.apps.googleusercontent.com', 'google_client_secret' => 'secret']);
        $auth = $this->get('/login')->viewData('app')['auth'];
        $this->assertTrue($auth['googleEnabled']);
        $this->assertNull($auth['localSignInUrl']);
    }

    public function test_a_signed_in_customer_gets_their_name_in_the_shell(): void
    {
        $this->assertSame('Maria Santos', $this->actingAs($this->guest, 'guest')->get('/account')->viewData('app')['auth']['guest']['name']);
    }

    public function test_the_profile_endpoints_need_a_signed_in_customer(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->patchJson('/api/me', ['name' => 'Nobody'])->assertUnauthorized();
        $this->postJson('/api/me/delete')->assertUnauthorized();
    }

    public function test_the_customer_reads_and_edits_their_name_and_phone_with_the_site_session(): void
    {
        $this->actingAs($this->guest, 'guest')->getJson('/api/me')
            ->assertOk()
            ->assertJson(['name' => 'Maria Santos', 'phone' => '0917 123 4567', 'email' => $this->guest->email]);

        $this->patchJson('/api/me', ['name' => 'Maria S. Santos', 'phone' => '+63 917 555 0100'])
            ->assertOk()
            ->assertJson(['name' => 'Maria S. Santos', 'phone' => '+63 917 555 0100']);

        $this->assertSame('Maria S. Santos', $this->guest->refresh()->name);

        $this->patchJson('/api/me', ['name' => 'Maria S. Santos', 'phone' => null])->assertOk();
        $this->assertNull($this->guest->refresh()->phone);
    }

    public function test_a_bad_name_or_phone_is_refused_with_a_plain_message(): void
    {
        $this->actingAs($this->guest, 'guest')->patchJson('/api/me', ['name' => '', 'phone' => '12'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'phone']);

        $this->assertSame('Maria Santos', $this->guest->refresh()->name);
    }

    public function test_deleting_the_account_erases_who_they_are_but_not_while_a_booking_is_open(): void
    {
        // Online booking needs Google sign-in to be set up
        SiteSetting::instance()->update(['google_client_id' => 'client.apps.googleusercontent.com', 'google_client_secret' => 'secret']);
        $this->actingAs($this->guest, 'guest')->postJson('/api/bookings', $this->reservation())->assertCreated();

        $this->postJson('/api/me/delete')->assertStatus(409);
        $this->assertSame('Maria Santos', $this->guest->refresh()->name);

        Booking::sole()->update(['status' => 'cancelled']);

        $this->postJson('/api/me/delete')->assertOk()->assertExactJson(['deleted' => true]);

        $this->guest->refresh();
        $this->assertSame('Deleted customer', $this->guest->name);
        $this->assertNull($this->guest->email);
        $this->assertNull($this->guest->phone);
    }

    public function test_the_demo_customer_is_not_changed_or_deleted_on_the_demo(): void
    {
        config(['app.demo' => true]);

        $this->actingAs($this->guest, 'guest')->patchJson('/api/me', ['name' => 'Someone Else'])->assertOk()->assertJson(['name' => 'Someone Else']);
        $this->assertSame('Maria Santos', $this->guest->refresh()->name);

        $this->postJson('/api/me/delete')->assertOk()->assertJson(['deleted' => true, 'demo' => true]);
        $this->assertSame('Maria Santos', $this->guest->refresh()->name);
    }

    public function test_the_other_account_pages_read_the_same_signed_in_customer(): void
    {
        $this->actingAs($this->guest, 'guest');

        $this->getJson('/api/my-vehicles')->assertOk();
        $this->getJson('/api/my-rewards')->assertOk()->assertJsonStructure(['pointsBalance', 'friends', 'ledger']);
        $this->getJson('/api/my-warranty')->assertOk();
        $this->getJson('/api/my-referral')->assertOk();
    }

    public function test_a_shared_referral_link_opens_the_booking_page_with_the_code_filled_in(): void
    {
        $this->get('/r/juan-7k3q')->assertRedirect(url('/book').'?ref=JUAN-7K3Q');
        $this->get('/r/JUAN-7K3Q')->assertRedirect(url('/book').'?ref=JUAN-7K3Q');
    }

    public function test_a_referral_link_with_something_that_is_not_a_code_gets_no_code(): void
    {
        foreach (['ab', 'juan_7k3q', 'juan%20x', str_repeat('A', 41), '%3Cscript%3E'] as $code) {
            $this->get("/r/{$code}")->assertRedirect(url('/book'));
        }
    }
}
