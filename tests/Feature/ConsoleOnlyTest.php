<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADMIN_CONSOLE_ONLY: old admin addresses open the React admin */
class ConsoleOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_addresses_open_the_same_place_in_the_new_admin(): void
    {
        config(['app.console_only' => true]);

        foreach ([
            '/admin' => '/console',
            '/admin/login' => '/console/login',
            '/owner/login' => '/console/login',
            '/operations' => '/console/operations',
            '/sales/bookings?tab=size' => '/console/sales/bookings?tab=size',
            '/owner/users/5/edit' => '/console/owner/users/5/edit',
            '/owner/multi-factor-authentication/set-up' => '/console/owner/profile',
            '/admin/password-reset/request' => '/console/forgot-password',
            '/admin/password-reset/reset?email=a%40b.test&token=abc&signature=x' => '/console/reset-password?token=abc&email=a%40b.test',
        ] as $old => $new) {
            $this->get($old)->assertRedirect(url($new));
        }
    }

    public function test_the_site_and_the_new_admin_are_left_alone(): void
    {
        config(['app.console_only' => true]);
        $this->withoutVite();

        $this->get('/console/login')->assertOk();
        $this->get('/robots.txt')->assertOk();
        // A signed-in person on the shared address is sent to their own panel by the gateway, then on
        $this->actingAs(User::factory()->owner()->create())->get('/admin/bookings')->assertRedirect();
    }

    public function test_switched_off_the_old_admin_answers(): void
    {
        config(['app.console_only' => false]);
        $this->withoutVite();

        $this->get('/admin/login')->assertOk();
    }
}
