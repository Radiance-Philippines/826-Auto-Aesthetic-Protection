<?php

namespace Tests\Feature;

use App\Models\AppRelease;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * GET /api/staff/apps: the team apps' app drawer. The tiles come from
 * App\Support\AppLauncher (the same ones as the web launcher), the app that
 * asks is `current`, planned apps are `soon` for the super admin only.
 */
class StaffAppsTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private Branch $cainta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->cainta = Branch::factory()->create(['name' => 'Cainta', 'status' => 'open']);
    }

    private function member(string $role): User
    {
        return $this->staffUser($role, $this->cainta);
    }

    /**
     * @return list<array{string, string}>
     */
    private function states(array $apps): array
    {
        return array_map(fn (array $app) => [$app['key'], $app['state']], $apps);
    }

    public function test_the_super_admin_gets_the_four_apps_then_the_planned_ones_as_soon(): void
    {
        $user = $this->member('super_admin');

        $apps = $this->getJson('/api/staff/apps', $this->bearer($user, 'sales'))->assertOk()->json('apps');

        $this->assertSame([
            ['owner', 'available'], ['sales', 'current'], ['operations', 'available'], ['system', 'available'],
            ['hr', 'soon'], ['payroll', 'soon'], ['accounting', 'soon'], ['inventory', 'soon'],
        ], $this->states($apps));
        $this->assertSame(['826 Owner', '826 Sales', '826 Operations', '826 System', 'HR', 'Payroll', 'Accounting', 'Inventory'], array_column($apps, 'label'));
        $this->assertSame(['store', 'banknote', 'columns', 'settings', 'users', 'wallet', 'calculator', 'archive'], array_column($apps, 'icon'));
    }

    public function test_the_current_app_follows_the_header(): void
    {
        $user = $this->member('super_admin');

        foreach (['owner', 'sales', 'operations', 'system'] as $app) {
            $current = array_values(array_filter($this->getJson('/api/staff/apps', $this->bearer($user, $app))->assertOk()->json('apps'), fn ($tile) => $tile['state'] === 'current'));

            $this->assertSame([$app], array_column($current, 'key'));
        }
    }

    public function test_a_sales_admin_gets_only_sales_and_no_planned_apps(): void
    {
        $apps = $this->getJson('/api/staff/apps', $this->bearer($this->member('sales_admin'), 'sales'))->assertOk()->json('apps');

        $this->assertSame([['sales', 'current']], $this->states($apps));
    }

    public function test_operations_and_branch_managers_get_only_operations(): void
    {
        foreach (['operations', 'branch_manager'] as $role) {
            $apps = $this->getJson('/api/staff/apps', $this->bearer($this->member($role), 'operations'))->assertOk()->json('apps');

            $this->assertSame([['operations', 'current']], $this->states($apps));
        }
    }

    public function test_a_panel_app_links_to_its_published_apk_and_has_no_url_before_that(): void
    {
        AppRelease::factory()->forApp('owner')->create(['version' => '0.1.0', 'version_code' => 1]);
        AppRelease::factory()->forApp('sales')->draft()->create(['version' => '0.2.0', 'version_code' => 2]);
        $apps = collect($this->getJson('/api/staff/apps', $this->bearer($this->member('super_admin'), 'operations'))->assertOk()->json('apps'))->keyBy('key');

        $this->assertSame(url('/download/826-owner-0.1.0.apk'), $apps['owner']['url']);
        // A draft is not public, and the app already open or a planned one has nothing to open
        $this->assertArrayNotHasKey('url', $apps['sales']);
        $this->assertArrayNotHasKey('url', $apps['system']);
        $this->assertArrayNotHasKey('url', $apps['hr']);
    }

    public function test_the_old_app_without_a_header_still_works_and_has_no_current_app(): void
    {
        $apps = $this->getJson('/api/staff/apps', $this->bearer($this->member('super_admin')))->assertOk()->json('apps');

        $this->assertCount(8, $apps);
        $this->assertNotContains('current', array_column($apps, 'state'));
    }

    public function test_it_needs_a_team_token_for_the_app_that_asks(): void
    {
        $this->getJson('/api/staff/apps')->assertUnauthorized();
        $this->getJson('/api/staff/apps', ['X-Team-App' => 'sales'])->assertUnauthorized();
        // A token issued for another app, and an unknown app, are refused like every staff call
        $this->getJson('/api/staff/apps', [...$this->bearer($this->member('super_admin'), 'owner'), 'X-Team-App' => 'sales'])->assertUnauthorized();
        $this->getJson('/api/staff/apps', [...$this->bearer($this->member('super_admin'), 'owner'), 'X-Team-App' => 'nope'])->assertStatus(422);
    }

    public function test_it_tells_nothing_but_the_tiles(): void
    {
        $user = $this->member('super_admin');
        $body = $this->getJson('/api/staff/apps', $this->bearer($user, 'owner'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();

        $this->assertSame(['apps'], array_keys($body));

        foreach ($body['apps'] as $app) {
            $this->assertEmpty(array_diff(array_keys($app), ['key', 'label', 'icon', 'state', 'url']));
        }

        $this->assertStringNotContainsString($user->email, json_encode($body));
    }
}
