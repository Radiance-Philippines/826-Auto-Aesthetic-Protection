<?php

namespace Tests\Feature;

use App\Filament\Pages\BookingSettings;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\VehicleSizes\VehicleSizeResource;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Support\AdminConsole;
use App\Support\CurrentBranch;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's shell (/console/{panel}) and its `me`: the same sign-in,
 * panel split, sidebar, branch selector and two-factor rule as Filament.
 */
class ConsoleShellTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private Branch $cainta;

    private Branch $sanMateo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);
    }

    public function test_a_guest_is_sent_to_the_sign_in_page_and_comes_back(): void
    {
        $this->get('/console')->assertRedirect(url('/console/login'));
        $this->get('/console/operations/queue?date=2026-11-14')->assertRedirect(url('/console/login'));

        $this->assertSame(url('/console/operations/queue?date=2026-11-14'), session('url.intended'));
    }

    public function test_a_guest_gets_a_json_401(): void
    {
        $this->getJson('/console/api/operations/me')->assertUnauthorized();
        $this->get('/console/api/operations/me')->assertUnauthorized();
    }

    public function test_the_token_of_a_team_app_does_not_sign_in_here(): void
    {
        $user = $this->staffUser('operations', $this->cainta);

        $this->getJson('/console/api/operations/me', $this->bearer($user))->assertUnauthorized();
    }

    #[DataProvider('homes')]
    public function test_console_opens_in_the_persons_own_panel(string $role, string $panel): void
    {
        $this->actingAs($this->staffUser($role, $this->cainta))
            ->get('/console')
            ->assertRedirect(url("/console/{$panel}"));

        $this->get("/console/{$panel}")->assertOk()->assertSee('"panel":"'.$panel.'"', false);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function homes(): array
    {
        return [
            'super admin' => ['super_admin', 'owner'],
            'sales admin' => ['sales_admin', 'sales'],
            'operations' => ['operations', 'operations'],
            'branch manager' => ['branch_manager', 'operations'],
        ];
    }

    public function test_an_account_without_a_panel_gets_403(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']));

        $this->get('/console')->assertForbidden();
        $this->get('/console/owner')->assertForbidden();
        $this->getJson('/console/api/owner/me')->assertForbidden();
    }

    public function test_another_panel_redirects_the_page_and_refuses_its_json(): void
    {
        $this->actingAs($this->staffUser('sales_admin', $this->cainta));

        $this->get('/console/operations')->assertRedirect(url('/console/sales'));
        $this->getJson('/console/api/operations/me')->assertForbidden()->assertJsonPath('message', 'Your role doesn’t include this panel.');
        $this->getJson('/console/api/system/me')->assertForbidden();
        $this->getJson('/console/api/sales/me')->assertOk();
    }

    public function test_a_screen_opens_in_the_panel_that_has_it(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->get('/console/operations/vehicle-sizes')->assertRedirect(url('/console/owner/vehicle-sizes'));
        $this->get('/console/owner/queue?date=2026-11-14')->assertRedirect(url('/console/operations/queue?date=2026-11-14'));
        $this->get('/console/owner/vehicle-sizes')->assertOk();
        $this->get('/console/owner/no-such-screen')->assertNotFound()->assertSee('id="console"', false);
    }

    public function test_a_screen_in_nobodys_panel_of_theirs_or_not_allowed_is_403(): void
    {
        $this->actingAs($this->staffUser('operations', $this->cainta));
        $this->get('/console/operations/vehicle-sizes')->assertForbidden();

        $this->actingAs($this->staffUser('operations', $this->cainta, ['permissions' => ['dashboard']]));
        $this->get('/console/operations/queue')->assertForbidden();
    }

    public function test_me_has_the_filament_sidebar_with_slugs_and_links(): void
    {
        $this->actingAs($this->staffUser('operations', $this->cainta));
        Booking::factory()->create(['branch_id' => $this->cainta->id]);

        $response = $this->getJson('/console/api/operations/me')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('panel', ['id' => 'operations', 'brand' => '826 Operations'])
            ->assertJsonPath('user.roleLabel', 'Operations (detailers)')
            ->assertJsonPath('launcher', [])
            ->assertJsonPath('impersonating', null)
            ->assertJsonPath('links.classic', url('/operations'))
            ->assertJsonPath('links.profile', url('/console/operations/profile'))
            ->assertJsonPath('links.logout', url('/console/logout'));

        $items = collect($response->json('navigation'))->flatMap(fn (array $group) => $group['items'])->keyBy('key');

        $this->assertSame(['Queue', 'Bookings'], [$items['queue']['label'], $items['bookings']['label']]);
        $this->assertSame(url('/operations/queue'), $items['queue']['href']);
        $this->assertSame(url('/operations/bookings'), $items['bookings']['href']);
        $this->assertSame('1', $items['bookings']['badge']);
        $this->assertFalse($items->has('vehicle-sizes'));
        $this->assertFalse($items->has('inbox'));
    }

    public function test_me_gives_each_panel_its_own_sidebar(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $owner = collect($this->getJson('/console/api/owner/me')->json('navigation'))->flatMap(fn (array $group) => $group['items'])->pluck('key');
        $sales = collect($this->getJson('/console/api/sales/me')->json('navigation'))->flatMap(fn (array $group) => $group['items'])->pluck('key');
        $system = collect($this->getJson('/console/api/system/me')->json('navigation'))->flatMap(fn (array $group) => $group['items'])->pluck('key');

        // Filament lists a branch's "Services" in every sidebar; only operations opens it
        $services = collect($this->getJson('/console/api/owner/me')->json('navigation'))->flatMap(fn (array $group) => $group['items'])->firstWhere('key', 'branch-services');
        $this->assertFalse($services['here']);

        $this->assertContains('vehicle-sizes', $owner);
        $this->assertNotContains('queue', $owner);
        $this->assertContains('manual-transaction', $sales);
        $this->assertContains('security', $system);
        foreach (['owner', 'sales', 'system'] as $panel) {
            $bare = collect($this->getJson("/console/api/{$panel}/me")->json('navigation'))->flatMap(fn (array $group) => $group['items'])->whereNull('key')->pluck('href')->all();
            $this->assertSame([], $bare, "every {$panel} sidebar entry has a slug");
        }
    }

    public function test_me_lists_the_launcher_and_the_branch_selector(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);

        $this->getJson('/console/api/owner/me')
            ->assertJsonPath('launcher.0', ['key' => 'owner', 'label' => 'Owner', 'glyph' => 'store', 'state' => 'current', 'href' => url('/console/owner')])
            ->assertJsonPath('launcher.4.state', 'soon')
            ->assertJsonPath('launcher.4.href', null)
            ->assertJsonPath('branches.options', [['id' => $this->cainta->id, 'name' => 'Cainta'], ['id' => $this->sanMateo->id, 'name' => 'San Mateo']])
            ->assertJsonPath('branches.chosen', null)
            ->assertJsonPath('branches.locked', false)
            ->assertJsonPath('branches.action', route('panel.branch'));

        // The Filament endpoint stores the choice and comes back to the console page
        $this->from('/console/owner/vehicle-sizes')->post(route('panel.branch'), ['branch' => $this->sanMateo->id])->assertRedirect(url('/console/owner/vehicle-sizes'));
        $this->getJson('/console/api/owner/me')->assertJsonPath('branches.chosen', $this->sanMateo->id);
        $this->assertSame($this->sanMateo->id, session(CurrentBranch::SESSION));
    }

    public function test_one_branch_is_shown_locked(): void
    {
        $this->actingAs($this->staffUser('operations', $this->cainta))
            ->getJson('/console/api/operations/me')
            ->assertJsonPath('branches.options', [['id' => $this->cainta->id, 'name' => 'Cainta']])
            ->assertJsonPath('branches.locked', true);
    }

    public function test_required_two_factor_applies_to_the_page_and_the_json(): void
    {
        SecuritySetting::instance()->update(['require_two_factor' => true]);
        $this->actingAs($this->staffUser('operations', $this->cainta));
        $setUp = url('/console/operations/profile');

        $this->get('/console/operations/queue')->assertRedirect($setUp);
        $this->getJson('/console/api/operations/me')->assertOk()->assertJsonPath('setupRequired', true)->assertJsonPath('navigation', []);
        $this->getJson('/console/api/operations/queue')->assertForbidden()->assertJsonPath('redirect', $setUp)->assertJsonMissingPath('columns');
    }

    public function test_viewing_as_someone_shows_the_banner_and_their_access(): void
    {
        $admin = User::factory()->owner()->create();
        $detailer = $this->staffUser('operations', $this->cainta, ['name' => 'Sample Detailer']);

        $this->actingAs($detailer)->withSession([Impersonation::IMPERSONATOR => $admin->id, Impersonation::TARGET => $detailer->id]);

        $this->getJson('/console/api/operations/me')
            ->assertJsonPath('impersonating', ['name' => 'Sample Detailer', 'roleLabel' => 'Operations (detailers)', 'branches' => 'Cainta', 'stopUrl' => route('impersonation.stop')]);
        $this->getJson('/console/api/owner/me')->assertForbidden();
    }

    public function test_a_view_that_can_no_longer_go_on_answers_419_to_a_background_call(): void
    {
        $detailer = $this->staffUser('operations', $this->cainta);

        // The stored "super admin" is not one any more
        $this->actingAs($detailer)->withSession([Impersonation::IMPERSONATOR => $this->staffUser('sales_admin', $this->cainta)->id, Impersonation::TARGET => $detailer->id]);

        $this->getJson('/console/api/operations/me')->assertStatus(419);
    }

    public function test_settings_and_users_screens_are_closed_while_viewing_as_someone(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        // No ported screen is one of these yet: the rule the later ones get from ConsoleScreen
        foreach ([BookingSettings::class, UserResource::class] as $class) {
            AdminConsole::authorize($class);
        }

        AdminConsole::authorize(VehicleSizeResource::class);
        session()->put(Impersonation::IMPERSONATOR, 1);
        AdminConsole::authorize(VehicleSizeResource::class);

        foreach ([BookingSettings::class, UserResource::class] as $class) {
            try {
                AdminConsole::authorize($class);
                $this->fail("{$class} stayed open");
            } catch (HttpException $refused) {
                $this->assertSame([403, 'Not available while viewing as someone else.'], [$refused->getStatusCode(), $refused->getMessage()]);
            }
        }
    }

    public function test_addresses_follow_the_folder_the_app_is_served_from(): void
    {
        // The server strips the folder from the path it hands the app, and every address it builds carries it
        URL::forceRootUrl('https://dran.dev/demo/826');
        URL::forceScheme('https');
        $this->actingAs(User::factory()->owner()->create());

        $this->get('http://localhost/console/owner')->assertOk()->assertSee('"base":"\/demo\/826"', false);
        $this->getJson('http://localhost/console/api/owner/me')
            ->assertJsonPath('launcher.1.href', 'https://dran.dev/demo/826/console/sales')
            ->assertJsonPath('branches.action', 'https://dran.dev/demo/826/panel-branch')
            ->assertJsonPath('links.classic', 'https://dran.dev/demo/826/owner');

        $keys = collect($this->getJson('http://localhost/console/api/owner/me')->json('navigation'))->flatMap(fn (array $group) => $group['items'])->pluck('key');
        $this->assertContains('vehicle-sizes', $keys);
        $this->assertNotContains(null, $keys);
    }

    public function test_every_console_route_has_the_web_group_so_writes_need_the_csrf_token(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'console.'));

        $this->assertGreaterThan(5, $routes->count());

        foreach ($routes as $route) {
            $this->assertContains('web', $route->gatherMiddleware(), $route->uri());
            $this->assertStringStartsWith('console', $route->uri());
        }
    }
}
