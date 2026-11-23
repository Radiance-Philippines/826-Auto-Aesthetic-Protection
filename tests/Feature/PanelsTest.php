<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Queue;
use App\Filament\Resources\Bookings\BookingResource;
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
 * The admin is four panels over one code base: /owner, /sales, /operations
 * and /system (with /admin as the one sign-in page and the old links). Each
 * panel shows and opens only its own pages.
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

        $this->assertTrue($can('super_admin', 'owner') && $can('super_admin', 'sales') && $can('super_admin', 'operations') && $can('super_admin', 'system'));
        $this->assertTrue($can('sales_admin', 'sales'));
        $this->assertFalse($can('sales_admin', 'owner') || $can('sales_admin', 'operations') || $can('sales_admin', 'system'));
        foreach (['operations', 'branch_manager'] as $role) {
            $this->assertTrue($can($role, 'operations'));
            $this->assertFalse($can($role, 'owner') || $can($role, 'sales') || $can($role, 'system'));
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

        foreach (['sales', 'operations', 'system'] as $panel) {
            $response = $this->get($this->inPanel($panel, '/bookings'));

            if (! Panels::canOpen($user, $panel)) {
                // The same page in their own panel, not a 403
                $response->assertRedirect(url($this->inPanel($home === 'owner' ? 'sales' : $home, '/bookings')));
            } elseif (Panels::shows(BookingResource::class, $panel)) {
                $response->assertOk();
            }
        }

        $this->assertSame($home, Panels::homeId($user));
        $this->get($this->dashboard())->assertOk();
    }

    public function test_a_super_admin_opens_all_four_panels_and_lands_in_owner(): void
    {
        $owner = $this->owner();

        $this->assertSame(url('/owner'), Panels::homeUrl($owner));

        $this->actingAs($owner);
        $this->get('/owner')->assertOk();
        $this->get('/sales')->assertOk();
        $this->get('/operations')->assertRedirect(url('/operations/queue'));
        $this->get('/operations/dashboard')->assertOk();
        $this->get('/system')->assertOk()->assertSee('System health');
    }

    public function test_only_a_super_admin_opens_system(): void
    {
        foreach (['sales_admin', 'operations', 'branch_manager'] as $role) {
            $this->actingAs($this->staff($role));

            // Sent to their own panel (the page if it is there, else the home), never inside /system
            $this->get('/system')->assertRedirect(Panels::homeUrl(auth()->user()));
            $this->get('/system/app-releases')->assertRedirect(Panels::homeUrl(auth()->user()));
            $this->get('/system/meta')->assertRedirect(Panels::homeUrl(auth()->user()));
        }
    }

    public function test_a_page_outside_a_panels_set_is_sent_to_the_panel_where_it_lives(): void
    {
        $this->actingAs($this->owner());

        // The business owner's setup is in /owner, the day-to-day work in /sales and /operations, the connections in /system
        foreach ([
            '/owner/bookings' => '/sales/bookings',
            '/owner/bookings/5' => '/sales/bookings/5',
            '/owner/customers' => '/sales/customers',
            '/owner/inbox' => '/sales/inbox',
            '/owner/queue' => '/operations/queue',
            '/sales/queue' => '/operations/queue',
            '/sales/users' => '/owner/users',
            '/sales/branches?x=1' => '/owner/branches?x=1',
            '/sales/photos' => '/owner/photos',
            '/sales/security' => '/system/security',
            '/operations/meta' => '/system/meta',
            '/operations/app-releases' => '/system/app-releases',
            '/owner/app-releases' => '/system/app-releases',
            '/owner/email-settings' => '/system/email-settings',
            '/owner/google-sign-in' => '/system/google-sign-in',
            '/sales/site-settings' => '/owner/site-settings',
            '/operations/booking-rules' => '/owner/booking-rules',
        ] as $from => $to) {
            $this->get($from)->assertRedirect(url($to));
        }

        // Where the page does live, nothing moves
        foreach (['/owner/users', '/owner/branch-blocks', '/owner/reviews', '/sales/bookings', '/sales/branch-blocks', '/operations/queue', '/operations/warranty', '/system/app-releases', '/system/security'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_a_page_none_of_a_persons_panels_has_is_forbidden(): void
    {
        $this->actingAs($this->staff('sales_admin'));
        $this->get('/sales/queue')->assertForbidden();
        $this->get('/sales/branches')->assertForbidden();
        $this->get('/sales/app-releases')->assertForbidden();

        // An extra access grant does not bring a page of another panel in
        $this->actingAs($this->staff('operations', ['permissions' => ['bookings.view', 'content', 'settings']]));
        $this->get('/operations/photos')->assertForbidden();
        $this->get('/operations/security')->assertForbidden();
    }

    public function test_an_account_without_a_panel_is_forbidden_everywhere(): void
    {
        $this->actingAs($this->staff('nonsense'));

        foreach (['/owner', '/sales/bookings', '/operations/queue', '/system'] as $url) {
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
        $this->get('/system')->assertRedirect(url('/system/login'));
        $this->get('/admin')->assertRedirect(url('/admin/login'));
        $this->get('/admin/bookings/5')->assertRedirect(url('/admin/login'));

        foreach (['admin', 'owner', 'sales', 'operations', 'system'] as $panel) {
            $this->get($this->inPanel($panel, '/login'))->assertOk();
        }
    }

    // The old /admin

    #[DataProvider('homes')]
    public function test_old_admin_links_land_on_the_same_page_in_the_signed_in_persons_panel(string $role, string $home): void
    {
        $user = $this->staff($role);
        $this->actingAs($user);

        // The panel of theirs that has the page: a super admin's bookings are in /sales, the queue in /operations
        $bookings = $role === 'super_admin' ? 'sales' : $home;

        $this->get('/admin')->assertRedirect(url("/{$home}"));
        $this->get('/admin/bookings/5')->assertRedirect(url("/{$bookings}/bookings/5"));
        $this->get('/admin/bookings?activeTab=action')->assertRedirect(url("/{$bookings}/bookings?activeTab=action"));
        $this->get('/admin/queue')->assertRedirect(url($role === 'sales_admin' ? "/{$home}" : '/operations/queue'));
        $this->get('/admin/security')->assertRedirect(url($role === 'super_admin' ? '/system/security' : "/{$home}"));
        $this->get('/admin/users')->assertRedirect(url($role === 'super_admin' ? '/owner/users' : "/{$home}"));
        // A page this panel does not have goes to its home
        $this->get('/admin/nothing-here')->assertRedirect(url("/{$home}"));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function logins(): array
    {
        $all = [];

        foreach (['admin', 'owner', 'sales', 'operations', 'system'] as $panel) {
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
        // A super admin's bookings are in /sales, their home (/owner) only has the setup
        $this->assertSame(url("/sales/bookings/{$booking->id}"), AdminUrl::for($this->owner())->booking($booking));
        $this->assertSame(url('/owner'), AdminUrl::for($this->owner())->home());
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

    /**
     * The sidebar of one panel as one person sees it: present and absent are
     * pages of that panel (a path under /panel), looked for in the sidebar only.
     *
     * @param  list<string>  $present
     * @param  list<string>  $absent
     */
    private function assertNavigation(User $user, string $panel, array $present, array $absent): void
    {
        $this->actingAs($user);
        $response = $this->get($panel === Panels::OPERATIONS ? "/{$panel}/dashboard" : "/{$panel}")->assertOk()->assertSee('app-brand-rest');

        $this->assertStringContainsString('>'.ucfirst($panel).'</span>', $response->getContent(), 'the panel names itself in the brand');
        $this->assertSame(1, preg_match('#<nav[^>]*class="fi-sidebar-nav".*?</nav>#s', $response->getContent(), $match), 'the sidebar');

        foreach ($present as $path) {
            $this->assertStringContainsString('href="'.url("/{$panel}{$path}").'"', $match[0], "{$user->role} should see {$path} in /{$panel}");
        }

        foreach ($absent as $path) {
            $this->assertStringNotContainsString('href="'.url("/{$panel}{$path}").'"', $match[0], "{$user->role} should not see {$path} in /{$panel}");
        }
    }

    /**
     * Every page of the admin: the pages a panel does not show are its absent list.
     *
     * @param  list<string>  $shown
     * @return list<string>
     */
    private function allBut(array $shown): array
    {
        $all = [
            '/bookings', '/queue', '/customers', '/warranty', '/reviews', '/inbox', '/manual-transaction', '/branch-blocks',
            '/branches', '/services', '/service-categories', '/vehicle-sizes', '/backjob-types', '/photos', '/videos',
            '/users', '/app-releases', '/booking-rules', '/referral', '/payment-settings', '/site-settings',
            '/email-settings', '/google-sign-in', '/meta', '/security',
        ];

        return array_values(array_diff($all, $shown));
    }

    public function test_the_owner_panel_has_the_setup_and_not_the_daily_work_or_the_system_pages(): void
    {
        $shown = ['/branch-blocks', '/branches', '/services', '/service-categories', '/vehicle-sizes', '/backjob-types', '/photos', '/videos', '/reviews', '/users', '/booking-rules', '/referral', '/payment-settings', '/site-settings'];

        $this->assertNavigation($this->owner(), 'owner', ['', ...$shown], $this->allBut($shown));
    }

    public function test_the_system_panel_has_the_connections_and_nothing_else(): void
    {
        $shown = ['/app-releases', '/email-settings', '/google-sign-in', '/meta', '/security'];

        $this->assertNavigation($this->owner(), 'system', ['', ...$shown], $this->allBut($shown));
    }

    public function test_a_super_admin_sees_only_the_sales_work_in_sales(): void
    {
        $shown = ['/bookings', '/customers', '/warranty', '/reviews', '/inbox', '/manual-transaction', '/branch-blocks'];

        // Every area, and still no Queue, Shop, Site, Settings, Users or System in it
        $this->assertNavigation($this->owner(), 'sales', ['', ...$shown], $this->allBut($shown));
    }

    public function test_the_sales_panel_shows_the_sales_work_only(): void
    {
        $this->assertNavigation($this->staff('sales_admin'), 'sales',
            ['', '/bookings', '/customers', '/warranty', '/reviews', '/inbox', '/manual-transaction'],
            $this->allBut(['/bookings', '/customers', '/warranty', '/reviews', '/inbox', '/manual-transaction']),
        );
    }

    public function test_a_super_admin_sees_only_the_operations_work_in_operations(): void
    {
        $shown = ['/queue', '/bookings', '/customers', '/warranty', '/branch-blocks', '/inbox', '/reviews', '/manual-transaction'];

        // Every area, and still no Shop, Site, Settings, Users or System in it
        $this->assertNavigation($this->owner(), 'operations', ['/dashboard', ...$shown], $this->allBut($shown));
    }

    public function test_the_operations_panel_shows_the_detailers_work_only(): void
    {
        $this->assertNavigation($this->staff('operations'), 'operations',
            ['/queue', '/bookings', '/customers', '/warranty', '/dashboard'],
            $this->allBut(['/queue', '/bookings', '/customers', '/warranty']),
        );
    }

    public function test_a_branch_manager_works_in_operations_with_the_extra_pages_their_areas_allow(): void
    {
        $shown = ['/queue', '/bookings', '/customers', '/warranty', '/reviews', '/inbox', '/manual-transaction', '/branch-blocks'];

        $this->assertNavigation($this->staff('branch_manager'), 'operations', ['/dashboard', ...$shown], $this->allBut($shown));
    }

    public function test_an_extra_access_override_adds_only_the_pages_of_the_panel_it_works_in(): void
    {
        $detailer = $this->staff('operations', ['permissions' => ['bookings.view', 'queue.update', 'reviews', 'content', 'dashboard']]);

        // Reviews is in /operations; Photos and Videos (the content area) live in /owner, which a detailer cannot open
        $this->assertNavigation($detailer, 'operations',
            ['/queue', '/bookings', '/reviews'],
            $this->allBut(['/queue', '/bookings', '/reviews']),
        );
    }

    public function test_the_panel_launcher_lists_the_panels_a_person_can_open(): void
    {
        $this->actingAs($this->owner());

        $response = $this->get('/owner')->assertOk()->assertSee('aria-label="Switch panel"', false)->assertDontSee('Open Sales');
        $response->assertSeeInOrder(['>Owner', '>Sales', '>Operations', '>System'], false);

        // The current panel is marked, the others are not
        $this->assertSame(1, preg_match_all('~class="pl-tile"\s+role="menuitem"[^>]*aria-current="true"~s', $response->getContent()));
        $this->assertMatchesRegularExpression('~aria-current="true"\s*>\s*<span class="pl-icon">.*?</span>\s*<span>Owner~s', $response->getContent());
        $this->get('/system')->assertOk()->assertSee('(current panel)', false);
    }

    public function test_the_launcher_links_to_the_same_page_in_the_other_panel_else_its_home(): void
    {
        $this->actingAs($this->owner());

        // Bookings live in /sales (not /owner or /system): from /sales, Operations has them too
        $this->get('/sales/bookings')->assertOk()
            ->assertSee('href="'.url('/operations/bookings').'"', false)
            ->assertSee('href="'.url('/owner').'"', false);

        // The Users page exists only in /owner: the other panels' tiles go home
        $this->get('/owner/users')->assertOk()->assertSee('href="'.url('/sales').'"', false)->assertDontSee('href="'.url('/sales/users').'"', false);
    }

    public function test_the_launcher_is_hidden_for_people_with_one_panel_or_none(): void
    {
        foreach (['sales_admin', 'operations', 'branch_manager'] as $role) {
            $this->actingAs($this->staff($role));
            $this->get($this->panel('/bookings'))->assertOk()->assertDontSee('Switch panel')->assertDontSee('pl-grid', false);
        }

        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $this->get('/operations')->assertForbidden()->assertDontSee('Switch panel');
    }

    public function test_the_system_home_says_what_is_switched_on_and_never_a_secret(): void
    {
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'mailbox-secret']);
        $this->actingAs($this->owner());

        $response = $this->get('/system')->assertOk();

        $response->assertSee(['Email is set up', 'Meta (Messenger) is connected', 'Google sign-in is set up', 'Live updates (Radiance Flow) are on', 'Yes', 'No', 'None published']);
        $response->assertDontSee('mailbox-secret');
        $response->assertDontSee('hello@826auto.test');
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
        foreach (['owner', 'sales', 'operations', 'system'] as $panel) {
            foreach (['resources.bookings.index', 'resources.bookings.view', 'resources.customers.index', 'pages.queue', 'pages.warranty', 'auth.login', 'auth.profile', 'auth.password-reset.request'] as $name) {
                $this->assertTrue(Route::has("filament.{$panel}.{$name}"), "filament.{$panel}.{$name}");
            }
        }

        foreach (['owner', 'sales', 'operations'] as $panel) {
            $this->assertTrue(Route::has("filament.{$panel}.pages.dashboard"));
        }

        $this->assertTrue(Route::has('filament.system.pages.health'));

        $this->assertTrue(Route::has('filament.admin.auth.login'));
        $this->assertFalse(Route::has('filament.admin.resources.bookings.index'));
    }

    public function test_no_registered_page_throws_in_any_panel(): void
    {
        $booking = Booking::factory()->create(['branch_id' => $this->cainta->id]);

        foreach ([['super_admin', ['owner', 'sales', 'operations', 'system']], ['sales_admin', ['sales']], ['branch_manager', ['operations']], ['operations', ['operations']]] as [$role, $panels]) {
            $user = $role === 'super_admin' ? $this->owner() : $this->staff($role);
            $this->actingAs($user);

            foreach ($panels as $id) {
                Filament::setCurrentPanel($id);
                $panel = Filament::getPanel($id);

                foreach ($panel->getPages() as $page) {
                    /** @var class-string<Page> $page */
                    $response = $this->get($page::getUrl(panel: $id));

                    if (! Panels::shows($page, $id)) {
                        // Another panel's page: sent there, or refused when this person has no panel with it
                        $this->assertContains($response->getStatusCode(), [302, 403], "{$role}: {$page} in /{$id}");
                    } else {
                        $page::canAccess() ? $response->assertOk() : $response->assertForbidden();
                    }
                }

                foreach ($panel->getResources() as $resource) {
                    /** @var class-string<resource> $resource */
                    $response = $this->get($resource::getUrl('index', panel: $id));

                    if (! Panels::shows($resource, $id)) {
                        $this->assertContains($response->getStatusCode(), [302, 403], "{$role}: {$resource} in /{$id}");
                    } else {
                        $resource::canViewAny() ? $response->assertOk() : $response->assertForbidden();
                    }
                }

                if (Panels::shows(BookingResource::class, $id)) {
                    $this->get($this->inPanel($id, "/bookings/{$booking->id}"))->assertOk();
                }
            }
        }

        Filament::setCurrentPanel('owner');
    }
}
