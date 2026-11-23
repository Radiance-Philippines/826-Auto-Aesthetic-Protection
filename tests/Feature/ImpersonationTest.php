<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\BookingSettings;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\SecurityEvent;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Providers\Filament\BasePanelProvider;
use App\Providers\Filament\OperationsPanelProvider;
use App\Providers\Filament\OwnerPanelProvider;
use App\Providers\Filament\SalesPanelProvider;
use App\Providers\Filament\SystemPanelProvider;
use App\Support\Access;
use App\Support\Impersonation;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * "View as this user": a super admin opens the panels as someone else, with a
 * banner, an audit trail, and the target's own access.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $cainta;

    private Branch $sanMateo;

    private User $admin;

    private User $detailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);
        $this->admin = User::factory()->owner()->create(['name' => 'Boss']);
        $this->detailer = User::factory()->operations()->create(['name' => 'Sample Detailer 1']);
        $this->detailer->branches()->attach($this->cainta);
    }

    private function viewAs(User $target): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListUsers::class)->callAction(TestAction::make('viewAs')->table($target));
    }

    /** What a request does when the stored session user is looked up again (not the guard's cached model) */
    private function freshRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_a_super_admin_starts_and_stops_viewing_as_someone(): void
    {
        $this->viewAs($this->detailer);

        $this->assertTrue(Impersonation::active());
        $this->assertSame($this->admin->id, session(Impersonation::IMPERSONATOR));
        $this->assertTrue(auth()->user()->is($this->detailer));
        $this->assertDatabaseHas('security_events', ['kind' => SecurityEvent::IMPERSONATION_STARTED, 'user_id' => $this->detailer->id, 'actor_id' => $this->admin->id]);

        $this->post(route('impersonation.stop'))->assertRedirect(url('/owner/users'));

        $this->assertFalse(Impersonation::active());
        $this->assertTrue(auth()->user()->is($this->admin));
        $this->assertDatabaseHas('security_events', ['kind' => SecurityEvent::IMPERSONATION_STOPPED, 'user_id' => $this->detailer->id, 'actor_id' => $this->admin->id]);
        $this->assertSame(1, User::withCount('impersonationEvents')->find($this->detailer->id)->impersonation_events_count);
    }

    public function test_it_lands_on_the_targets_own_home_panel(): void
    {
        $sales = User::factory()->salesAdmin()->create();
        $sales->branches()->attach($this->cainta);

        $this->actingAs($this->admin);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('viewAs')->table($sales))
            ->assertRedirect(url('/sales'));
    }

    public function test_the_rules_for_who_can_be_viewed(): void
    {
        $otherAdmin = User::factory()->owner()->create();
        $pending = User::factory()->operations()->unverified()->create(['invited_at' => now()]);
        $noPanel = User::factory()->create(['role' => 'customer']);

        $this->assertNull(Impersonation::refusal($this->admin, $this->detailer));
        $this->assertNotNull(Impersonation::refusal($this->admin, $otherAdmin), 'another super admin');
        $this->assertNotNull(Impersonation::refusal($this->admin, $this->admin), 'yourself');
        $this->assertNotNull(Impersonation::refusal($this->admin, $pending), 'a pending invite');
        $this->assertNotNull(Impersonation::refusal($this->admin, $noPanel), 'no panel');
        $this->assertNotNull(Impersonation::refusal($this->detailer, $this->admin), 'not a super admin');

        $this->actingAs($this->admin);

        foreach ([$otherAdmin, $pending, $noPanel] as $target) {
            Livewire::test(ListUsers::class)->assertTableActionHidden('viewAs', $target);
        }

        $this->assertFalse(Impersonation::active());
        $this->assertDatabaseCount('security_events', 0);
    }

    public function test_a_forced_call_for_a_refused_target_does_nothing(): void
    {
        $this->expectException(HttpException::class);

        try {
            Impersonation::start($this->admin, User::factory()->owner()->create(), 'owner');
        } finally {
            $this->assertFalse(Impersonation::active());
            $this->assertDatabaseCount('security_events', 0);
        }
    }

    public function test_it_cannot_be_nested_and_a_non_super_admin_cannot_start(): void
    {
        $this->viewAs($this->detailer);
        $other = User::factory()->operations()->create();
        $other->branches()->attach($this->cainta);

        $this->assertNotNull(Impersonation::refusal($this->admin, $other), 'nested');
        $this->assertTrue(auth()->user()->is($this->detailer));

        // The detailer, who is now signed in, has no Users page to start from
        $this->assertFalse(UserResource::canViewAny());
        $this->assertSame($this->admin->id, session(Impersonation::IMPERSONATOR));
    }

    public function test_it_behaves_exactly_as_the_target(): void
    {
        $mine = Booking::factory()->create(['branch_id' => $this->cainta->id]);
        $theirs = Booking::factory()->create(['branch_id' => $this->sanMateo->id]);

        $this->viewAs($this->detailer);
        $this->freshRequest();

        $this->get('/operations/queue')->assertOk();
        $this->assertFalse(UserResource::canViewAny());
        $this->get('/operations/users')->assertRedirect(url('/operations'));
        $this->get('/owner/users')->assertRedirect(url('/operations'));
        $this->get($this->panel("/bookings/{$mine->id}"))->assertOk();
        $this->get($this->panel("/bookings/{$theirs->id}"))->assertNotFound();
        $this->assertSame([$this->cainta->id], auth()->user()->branchIds());
        $this->assertFalse(auth()->user()->isOwner());
    }

    public function test_the_banner_is_on_every_panel_while_viewing_and_nowhere_else(): void
    {
        $sales = User::factory()->salesAdmin()->create(['name' => 'Sample Sales admin']);
        $sales->branches()->attach($this->cainta);

        $this->actingAs($this->detailer);
        $this->get('/operations/queue')->assertOk()->assertDontSee('You are viewing as');

        foreach ([[$this->detailer, '/operations/queue', 'Operations (detailers), Cainta'], [$sales, '/sales', 'Sales admin, Cainta']] as [$target, $page, $label]) {
            $this->post(route('impersonation.stop'));
            $this->viewAs($target);
            $this->freshRequest();

            $this->get($page)
                ->assertOk()
                ->assertSee('You are viewing as')
                ->assertSee($target->name)
                ->assertSee($label)
                ->assertSee('Everything you do is recorded as this person.', false)
                ->assertSee(route('impersonation.stop'), false)
                ->assertSee('Stop impersonating');
        }

        $this->post(route('impersonation.stop'));
        $this->actingAs($this->admin)->get('/owner/users')->assertOk()->assertDontSee('You are viewing as');
    }

    public function test_every_panel_carries_the_banner_hook(): void
    {
        foreach (['owner', 'sales', 'operations', 'system'] as $id) {
            $this->assertInstanceOf(BasePanelProvider::class, app()->getProvider(match ($id) {
                'owner' => OwnerPanelProvider::class,
                'sales' => SalesPanelProvider::class,
                'operations' => OperationsPanelProvider::class,
                'system' => SystemPanelProvider::class,
            }));
        }
    }

    public function test_logout_ends_it(): void
    {
        $this->viewAs($this->detailer);

        $this->post('/operations/logout')->assertRedirect();

        $this->assertFalse(Impersonation::active());
        $this->assertGuest();
    }

    public function test_a_demoted_or_deleted_super_admin_loses_the_view_and_the_session(): void
    {
        $this->viewAs($this->detailer);
        $this->admin->forceFill(['role' => Access::OPERATIONS])->save();
        $this->freshRequest();

        $this->get('/operations/queue')->assertRedirect(url('/owner/login'));
        $this->assertFalse(Impersonation::active());
        $this->assertGuest();

        $this->admin->forceFill(['role' => Access::SUPER_ADMIN])->save();
        $this->viewAs($this->detailer);
        $this->admin->delete();
        $this->freshRequest();

        $this->get('/operations/queue')->assertRedirect(url('/owner/login'));
        $this->assertFalse(Impersonation::active());
        $this->assertGuest();
    }

    public function test_a_deleted_or_panel_less_target_returns_to_the_super_admin(): void
    {
        $this->viewAs($this->detailer);
        $this->detailer->delete();
        $this->freshRequest();

        $this->get('/operations/queue')->assertRedirect(url('/owner/users'));
        $this->assertFalse(Impersonation::active());
        $this->assertTrue(auth()->user()->is($this->admin));

        $other = User::factory()->operations()->create();
        $other->branches()->attach($this->cainta);
        $this->viewAs($other);
        $other->forceFill(['role' => 'customer'])->save();
        $this->freshRequest();

        $this->get('/operations/queue')->assertRedirect(url('/owner/users'));
        $this->assertFalse(Impersonation::active());
        $this->assertTrue(auth()->user()->is($this->admin));
    }

    public function test_sensitive_pages_are_blocked_while_viewing(): void
    {
        $this->detailer->forceFill(['permissions' => ['bookings.view', 'settings']])->save();

        // Without viewing, the account's own settings access works
        $this->actingAs($this->detailer)->get('/operations/profile')->assertOk();

        $this->viewAs($this->detailer);
        $this->freshRequest();

        $this->get('/operations/profile')->assertRedirect(url('/operations'));
        $this->get($this->panel('/booking-rules'))->assertRedirect(url('/operations'));

        // A tab opened earlier cannot change anything either
        Livewire::test(EditProfile::class)->assertForbidden();
        Livewire::test(BookingSettings::class)->assertForbidden();
        $this->assertTrue(Impersonation::refusal($this->admin, $this->detailer) !== null);
    }

    public function test_a_viewed_account_is_not_asked_to_set_up_two_factor(): void
    {
        SecuritySetting::instance()->update(['require_two_factor' => true]);

        $this->viewAs($this->detailer);
        $this->freshRequest();

        $this->get('/operations/queue')->assertOk();
    }

    public function test_the_audit_shows_in_the_targets_own_profile_activity(): void
    {
        $this->viewAs($this->detailer);
        $this->post(route('impersonation.stop'));

        $this->actingAs($this->detailer);

        Livewire::test(EditProfile::class)->assertSee('A super admin started viewing as this person')->assertSee('(by Boss)');
    }
}
