<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Queue;
use App\Mail\Invitation;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\MailSetting;
use App\Models\User;
use App\Support\AdminUrl;
use App\Support\Invitations;
use App\Support\Panels;
use App\Support\Reservations;
use Carbon\CarbonImmutable;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The admin is three panels over one code base: /owner, /sales and
 * /operations (with /admin as the one sign-in page and the old links).
 */
class PanelsTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private Branch $cainta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
    }

    private function staff(string $role, array $state = []): User
    {
        return $this->staffUser($role, $this->cainta, $state);
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
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

    // Who may open which panel

    public function test_a_panel_is_open_to_its_roles_only(): void
    {
        $can = fn (string $role, string $panel) => $this->staff($role)->canAccessPanel(Filament::getPanel($panel));

        $this->assertTrue($can('super_admin', 'owner') && $can('super_admin', 'sales') && $can('super_admin', 'operations'));
        $this->assertTrue($can('sales_admin', 'sales'));
        $this->assertFalse($can('sales_admin', 'owner') || $can('sales_admin', 'operations'));
        foreach (['operations', 'branch_manager'] as $role) {
            $this->assertTrue($can($role, 'operations'));
            $this->assertFalse($can($role, 'owner') || $can($role, 'sales'));
        }

        // /admin is only the shared sign-in: any account with a panel, nobody else
        $this->assertTrue($can('operations', 'admin'));
        $this->assertFalse($this->staff('nonsense')->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($this->staff('nonsense')->canAccessPanel(Filament::getPanel('owner')));
    }

    #[DataProvider('homes')]
    public function test_each_role_opens_its_own_panel_and_is_sent_there_from_the_others(string $role, string $home): void
    {
        $user = $this->staff($role);
        $this->actingAs($user);

        foreach (['owner', 'sales', 'operations'] as $panel) {
            $response = $this->get($this->inPanel($panel, '/bookings'));

            if (Panels::canOpen($user, $panel)) {
                $response->assertOk();
            } else {
                // The same page in their own panel, not a 403
                $response->assertRedirect(url($this->inPanel($home, '/bookings')));
            }
        }

        $this->assertSame($home, Panels::homeId($user));
        $this->get($this->dashboard())->assertOk();
    }

    public function test_a_super_admin_opens_all_three_panels_and_lands_in_owner(): void
    {
        $owner = $this->owner();

        $this->assertSame(url('/owner'), Panels::homeUrl($owner));

        $this->actingAs($owner);
        $this->get('/owner')->assertOk();
        $this->get('/sales')->assertOk();
        $this->get('/operations')->assertRedirect(url('/operations/queue'));
        $this->get('/operations/dashboard')->assertOk();
    }

    public function test_an_account_without_a_panel_is_forbidden_everywhere(): void
    {
        $this->actingAs($this->staff('nonsense'));

        foreach (['/owner', '/sales/bookings', '/operations/queue'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_the_operations_home_is_the_queue(): void
    {
        $this->actingAs($this->staff('operations'));

        $this->get('/operations')->assertRedirect(Queue::getUrl(panel: 'operations'));
        $this->get('/operations/queue')->assertOk();
    }

    public function test_visitors_are_sent_to_the_sign_in_page(): void
    {
        $this->get('/owner/bookings')->assertRedirect(url('/owner/login'));
        $this->get('/sales')->assertRedirect(url('/sales/login'));
        $this->get('/operations/queue')->assertRedirect(url('/operations/login'));
        $this->get('/admin')->assertRedirect(url('/admin/login'));
        $this->get('/admin/bookings/5')->assertRedirect(url('/admin/login'));

        foreach (['admin', 'owner', 'sales', 'operations'] as $panel) {
            $this->get($this->inPanel($panel, '/login'))->assertOk();
        }
    }

    // The old /admin

    #[DataProvider('homes')]
    public function test_old_admin_links_land_on_the_same_page_in_the_signed_in_persons_panel(string $role, string $home): void
    {
        $this->actingAs($this->staff($role));

        $this->get('/admin')->assertRedirect(url("/{$home}"));
        $this->get('/admin/bookings/5')->assertRedirect(url("/{$home}/bookings/5"));
        $this->get('/admin/bookings?activeTab=action')->assertRedirect(url("/{$home}/bookings?activeTab=action"));
        $this->get('/admin/queue')->assertRedirect(url("/{$home}/queue"));
        // A page this panel does not have goes to its home
        $this->get('/admin/nothing-here')->assertRedirect(url("/{$home}"));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function logins(): array
    {
        $all = [];

        foreach (['admin', 'owner', 'sales', 'operations'] as $panel) {
            foreach (self::homes() as $label => [$role, $home]) {
                $all["{$label} at /{$panel}/login"] = [$panel, $role, $home];
            }
        }

        return $all;
    }

    #[DataProvider('logins')]
    public function test_the_login_page_sends_each_role_to_its_own_panel(string $panelId, string $role, string $home): void
    {
        $user = $this->staff($role);
        Filament::setCurrentPanel($panelId);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(url("/{$home}"), app(LoginResponse::class)->toResponse(request())->getTargetUrl());
    }

    public function test_the_login_page_still_refuses_a_wrong_password_and_an_account_without_a_panel(): void
    {
        Filament::setCurrentPanel('admin');
        $user = $this->staff('operations');

        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'nope'])->call('authenticate')->assertHasFormErrors(['email']);

        $nobody = $this->staff('nonsense');
        Livewire::test(Login::class)->fillForm(['email' => $nobody->email, 'password' => 'password'])->call('authenticate')->assertHasFormErrors(['email']);

        Filament::setCurrentPanel('owner');
    }

    // Invitations and links built for a person

    public function test_the_invitation_link_is_in_the_invited_persons_panel(): void
    {
        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'mailbox-secret']);

        foreach ([['sales_admin', 'sales'], ['operations', 'operations'], ['branch_manager', 'operations'], ['super_admin', 'owner']] as [$role, $panel]) {
            $user = app(Invitations::class)->invite('Mate '.$role, "{$role}@example.com", null, $role);

            Mail::assertSent(Invitation::class, fn (Invitation $mail) => $mail->hasTo($user->email)
                && str_contains($mail->setPasswordUrl, "/{$panel}/password-reset/reset")
                && str_contains($mail->render(), url("/{$panel}")));
        }
    }

    public function test_links_are_built_for_the_recipients_panel(): void
    {
        $booking = Booking::factory()->create(['branch_id' => $this->cainta->id]);

        $this->assertSame(url("/sales/bookings/{$booking->id}"), AdminUrl::for($this->staff('sales_admin'))->booking($booking));
        $this->assertSame(url("/operations/bookings/{$booking->id}"), AdminUrl::for($this->staff('branch_manager'))->booking($booking));
        $this->assertSame(url("/owner/bookings/{$booking->id}"), AdminUrl::for($this->owner())->booking($booking));
        $this->assertSame(url('/sales/reviews'), AdminUrl::for($this->staff('sales_admin'))->reviews());
        $this->assertSame(url("/admin/bookings/{$booking->id}"), AdminUrl::legacyBooking($booking));
    }

    public function test_the_bell_links_open_in_each_recipients_panel(): void
    {
        $sales = $this->staff('sales_admin');
        $manager = $this->staff('branch_manager');
        $booking = Booking::factory()->create(['branch_id' => $this->cainta->id, 'ready_on' => '2026-11-20', 'drop_off_on' => '2026-11-19']);

        $this->actingAs($this->staff('operations'));
        Reservations::changeReadyDate($booking, CarbonImmutable::parse('2026-11-24'));

        $this->assertSame(url("/sales/bookings/{$booking->id}"), $sales->notifications()->sole()->data['actions'][0]['url']);
        $this->assertSame(url("/operations/bookings/{$booking->id}"), $manager->notifications()->sole()->data['actions'][0]['url']);
    }

    // The staff app and passkeys are not tied to a panel

    #[DataProvider('homes')]
    public function test_the_staff_app_signs_in_every_role(string $role): void
    {
        $user = $this->staff($role, ['email' => 'member@example.com', 'password' => 'a-long-test-password']);

        $this->postJson('/api/staff/sign-in', ['email' => $user->email, 'password' => 'a-long-test-password', 'device_name' => 'Galaxy S24'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    // What each panel shows

    private function assertNavigation(User $user, array $present, array $absent): void
    {
        $this->actingAs($user);
        $panel = Panels::homeId($user);
        $html = $this->get($this->dashboard($user))->assertOk()->assertSee('app-brand-rest')->getContent();

        $this->assertStringContainsString('>'.ucfirst($panel).'</span>', $html, 'the panel names itself in the brand');

        foreach ($present as $path) {
            $this->assertStringContainsString('href="'.url("/{$panel}{$path}").'"', $html, "{$user->role} should see {$path} in /{$panel}");
        }

        foreach ($absent as $path) {
            $this->assertStringNotContainsString('href="'.url("/{$panel}{$path}").'"', $html, "{$user->role} should not see {$path} in /{$panel}");
        }
    }

    public function test_the_owner_panel_has_everything_with_the_system_pages_in_their_own_group(): void
    {
        $this->assertNavigation($this->owner(), [
            '/bookings', '/queue', '/customers', '/warranty', '/reviews', '/inbox', '/manual-transaction', '/branch-blocks',
            '/branches', '/services', '/service-categories', '/vehicle-sizes', '/backjob-types', '/photos', '/videos',
            '/users', '/app-releases', '/booking-rules', '/security', '/site-settings', '/email-settings',
        ], []);

        $this->get('/owner')->assertSee('System');
    }

    public function test_the_sales_panel_shows_the_sales_work_only(): void
    {
        $this->assertNavigation($this->staff('sales_admin'),
            ['/bookings', '/queue', '/customers', '/warranty', '/reviews', '/inbox', '/manual-transaction'],
            ['/branch-blocks', '/branches', '/services', '/photos', '/videos', '/users', '/app-releases', '/security', '/booking-rules'],
        );
    }

    public function test_the_operations_panel_shows_the_detailers_work_only(): void
    {
        $this->assertNavigation($this->staff('operations'),
            ['/queue', '/bookings', '/customers', '/warranty', '/dashboard'],
            ['/reviews', '/inbox', '/manual-transaction', '/branch-blocks', '/branches', '/photos', '/users', '/security'],
        );
    }

    public function test_a_branch_manager_works_in_operations_with_the_extra_pages_their_areas_allow(): void
    {
        $this->assertNavigation($this->staff('branch_manager'),
            ['/queue', '/bookings', '/customers', '/warranty', '/reviews', '/inbox', '/manual-transaction', '/branch-blocks', '/dashboard'],
            ['/branches', '/services', '/photos', '/users', '/app-releases', '/security', '/booking-rules'],
        );
    }

    public function test_an_extra_access_override_adds_its_pages_to_the_panel(): void
    {
        $detailer = $this->staff('operations', ['permissions' => ['bookings.view', 'queue.update', 'reviews', 'content', 'dashboard']]);

        $this->assertNavigation($detailer,
            ['/queue', '/bookings', '/reviews', '/photos', '/videos'],
            ['/customers', '/warranty', '/inbox', '/manual-transaction', '/branches', '/users'],
        );
    }

    public function test_each_panel_dashboard_has_its_own_widgets(): void
    {
        $widgets = function (string $panel, User $user): array {
            $this->actingAs($user);
            Filament::setCurrentPanel($panel);

            return collect(Livewire::test(Dashboard::class)->instance()->getWidgets())->map(fn (string $widget) => class_basename($widget))->all();
        };

        $owner = $this->owner();

        $this->assertSame(['BookingStats', 'NeedsAttention', 'SalesOverview', 'SalesChart', 'ServicesInsight', 'BranchesInsight', 'OperationsInsight', 'CustomersInsight', 'StaffInsight'], $widgets('owner', $owner));
        $this->assertSame(['BookingStats', 'NeedsAttention', 'SalesOverview', 'SalesChart', 'ServicesInsight', 'CustomersInsight'], $widgets('sales', $owner));
        $this->assertSame(['BookingStats', 'NeedsAttention', 'OperationsInsight', 'CustomersInsight'], $widgets('operations', $owner));

        Filament::setCurrentPanel('owner');
    }

    public function test_the_dashboards_render_in_every_panel_for_the_roles_that_work_there(): void
    {
        foreach (self::homes() as [$role]) {
            $user = $this->staff($role);
            $this->actingAs($user);

            foreach (Panels::idsFor($user) as $panel) {
                $this->get($this->inPanel($panel, $panel === 'operations' ? '/dashboard' : ''))->assertOk();
            }
        }
    }

    // Every page in every panel it is registered in

    public function test_livewire_routes_exist_in_each_panel_for_the_shared_pages(): void
    {
        foreach (['owner', 'sales', 'operations'] as $panel) {
            foreach (['resources.bookings.index', 'resources.bookings.view', 'resources.customers.index', 'pages.queue', 'pages.warranty', 'pages.dashboard', 'auth.login', 'auth.profile', 'auth.password-reset.request'] as $name) {
                $this->assertTrue(Route::has("filament.{$panel}.{$name}"), "filament.{$panel}.{$name}");
            }
        }

        $this->assertTrue(Route::has('filament.admin.auth.login'));
        $this->assertFalse(Route::has('filament.admin.resources.bookings.index'));
    }

    public function test_no_registered_page_throws_in_any_panel(): void
    {
        $booking = Booking::factory()->create(['branch_id' => $this->cainta->id]);

        foreach ([['super_admin', ['owner', 'sales', 'operations']], ['sales_admin', ['sales']], ['branch_manager', ['operations']], ['operations', ['operations']]] as [$role, $panels]) {
            $user = $role === 'super_admin' ? $this->owner() : $this->staff($role);
            $this->actingAs($user);

            foreach ($panels as $id) {
                Filament::setCurrentPanel($id);
                $panel = Filament::getPanel($id);

                foreach ($panel->getPages() as $page) {
                    /** @var class-string<Page> $page */
                    $response = $this->get($page::getUrl(panel: $id));

                    // The operations home is the queue, so only its own dashboard is a page here
                    $page::canAccess() ? $response->assertOk() : $response->assertForbidden();
                }

                foreach ($panel->getResources() as $resource) {
                    /** @var class-string<resource> $resource */
                    $response = $this->get($resource::getUrl('index', panel: $id));

                    $resource::canViewAny() ? $response->assertOk() : $response->assertForbidden();
                }

                if ($booking->exists) {
                    $this->get($this->inPanel($id, "/bookings/{$booking->id}"))->assertOk();
                }
            }
        }

        Filament::setCurrentPanel('owner');
    }
}
