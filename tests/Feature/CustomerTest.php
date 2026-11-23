<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\ReferralRelationManager;
use App\Filament\Resources\Customers\RelationManagers\UnitsRelationManager;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\ReferralRedemption;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Livewire\Livewire;
use Mockery;
use Tests\Support\BuildsShop;
use Tests\TestCase;

/**
 * Customers (the CRM): walk-ins without Google, scoping by branch, the
 * units' size, and deleting.
 */
class CustomerTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private Branch $sanMateo;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->buildShop();
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);
        $this->staff = User::factory()->create();
        $this->staff->branches()->attach($this->branch);
    }

    public function test_the_same_phone_number_is_the_same_customer(): void
    {
        $first = Guest::findOrCreateByPhone('Pedro Reyes', '0917 123 4567', $this->branch->id);
        $again = Guest::findOrCreateByPhone('Pedro R.', '+63 917-123-4567', $this->sanMateo->id, 'pedro@example.com');

        $this->assertTrue($first->is($again));
        $this->assertSame(1, Guest::count());
        $this->assertSame(['Pedro Reyes', $this->branch->id, 'pedro@example.com', 'walk_in', null], [$again->name, $again->branch_id, $again->email, $again->source, $again->google_id]);
        $this->assertSame('9171234567', $again->phone_key);

        // A number that is too short can't be matched, so it never merges two people
        Guest::findOrCreateByPhone('A', '12345');
        Guest::findOrCreateByPhone('B', '12345');
        $this->assertSame(3, Guest::count());
    }

    public function test_a_booking_the_team_adds_goes_to_the_customer_with_that_phone(): void
    {
        $existing = Guest::factory()->create(['phone' => '0917 123 4567', 'email' => 'maria@example.com']);

        $this->actingAs($this->staff);

        Livewire::test(CreateBooking::class)
            ->fillForm([
                'kind' => 'onsite',
                'source' => 'phone',
                'branch_id' => $this->branch->id,
                'services' => [$this->detail->id],
                'drop_off_on' => '2026-11-17',
                'name' => 'Maria S.',
                'phone' => '+63 917 123 4567',
                'vehicle_year' => 2021,
                'vehicle_model' => 'Toyota Vios',
                'vehicle_type' => 'sedan',
                'plate_number' => 'ABC 1234',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $booking = Booking::sole();
        $this->assertSame($existing->id, $booking->guest_id);
        $this->assertSame(1, Guest::count());
        $this->assertSame('ABC1234', Vehicle::sole()->plate_number);
        $this->assertSame($existing->id, Vehicle::sole()->guest_id);

        // A new phone number makes a walk-in customer, with the branch as their home
        Livewire::test(CreateBooking::class)
            ->fillForm([
                'kind' => 'onsite', 'source' => 'walk_in', 'branch_id' => $this->branch->id, 'services' => [$this->detail->id], 'drop_off_on' => '2026-11-18',
                'name' => 'Pedro Reyes', 'phone' => '0922 555 0000', 'vehicle_year' => 2019, 'vehicle_model' => 'Honda City', 'vehicle_type' => 'sedan', 'plate_number' => 'XYZ 9999',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $walkIn = Guest::where('name', 'Pedro Reyes')->sole();
        $this->assertSame([null, null, 'walk_in', $this->branch->id], [$walkIn->google_id, $walkIn->email, $walkIn->source, $walkIn->branch_id]);
        $this->assertSame($walkIn->id, Booking::latest('id')->first()->guest_id);
    }

    public function test_a_customer_without_google_works_with_the_guest_guard_and_the_site(): void
    {
        $walkIn = Guest::factory()->walkIn()->create(['name' => 'Pedro Reyes']);
        $booking = Booking::factory()->create(['guest_id' => $walkIn->id, 'branch_id' => $this->branch->id]);

        $this->actingAs($walkIn, 'guest')->getJson('/api/my-bookings')->assertOk()->assertJsonPath('bookings.0.id', $booking->id);
        $this->assertSame(['name' => 'Pedro Reyes', 'email' => null, 'avatar' => null], $this->get('/')->viewData('app')['auth']['guest']);
        $this->assertTrue($booking->isOpenedBy($walkIn, null));
    }

    public function test_signing_in_with_google_adopts_the_walk_in_customer_with_that_email(): void
    {
        SiteSetting::instance()->update(['google_client_id' => 'client.apps.googleusercontent.com', 'google_client_secret' => 'secret']);
        $walkIn = Guest::factory()->walkIn()->create(['email' => 'maria@example.com']);
        $booking = Booking::factory()->create(['guest_id' => $walkIn->id, 'branch_id' => $this->branch->id]);

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn((new GoogleUser)->map(['id' => '1234567890', 'name' => 'Maria Santos', 'email' => 'Maria@Example.com', 'avatar' => null])->setRaw(['email_verified' => true]));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback')->assertRedirect();

        $this->assertSame(1, Guest::count());
        $this->assertAuthenticatedAs($walkIn, 'guest');
        $this->assertSame('1234567890', $walkIn->fresh()->google_id);
        $this->assertSame($walkIn->id, $booking->fresh()->guest_id);
    }

    public function test_an_unverified_google_email_does_not_adopt_the_walk_in_customer(): void
    {
        SiteSetting::instance()->update(['google_client_id' => 'client.apps.googleusercontent.com', 'google_client_secret' => 'secret']);
        $walkIn = Guest::factory()->walkIn()->create(['email' => 'maria@example.com']);

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn((new GoogleUser)->map(['id' => '999', 'name' => 'Someone Else', 'email' => 'maria@example.com', 'avatar' => null])->setRaw(['email_verified' => false]));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback')->assertRedirect();

        $this->assertNull($walkIn->fresh()->google_id);
        $this->assertSame(2, Guest::count());
    }

    public function test_staff_see_only_customers_of_their_branches(): void
    {
        $booked = Guest::factory()->create(['name' => 'Booked Here']);
        Booking::factory()->create(['guest_id' => $booked->id, 'branch_id' => $this->branch->id]);
        $home = Guest::factory()->walkIn()->create(['name' => 'Home Here', 'branch_id' => $this->branch->id]);
        $elsewhere = Guest::factory()->create(['name' => 'Booked Elsewhere']);
        Booking::factory()->create(['guest_id' => $elsewhere->id, 'branch_id' => $this->sanMateo->id]);
        $nobody = Guest::factory()->create(['name' => 'Nobody']);

        $this->actingAs($this->staff);

        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$booked, $home])
            ->assertCanNotSeeTableRecords([$elsewhere, $nobody]);

        $this->get("/admin/customers/{$booked->id}")->assertOk();
        $this->get("/admin/customers/{$elsewhere->id}")->assertNotFound();
        $this->get("/admin/customers/{$elsewhere->id}/edit")->assertNotFound();

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(ListCustomers::class)->assertCanSeeTableRecords([$booked, $home, $elsewhere, $nobody]);

        $this->actingAs(User::factory()->create());
        Livewire::test(ListCustomers::class)->assertCanNotSeeTableRecords([$booked, $home, $elsewhere]);
    }

    public function test_the_list_searches_by_name_phone_email_and_plate_and_filters_by_branch(): void
    {
        $maria = Guest::factory()->create(['name' => 'Maria Santos', 'phone' => '0917 111 2222', 'email' => 'maria@example.com', 'branch_id' => $this->branch->id]);
        Vehicle::factory()->create(['guest_id' => $maria->id, 'plate_number' => 'ABC1234']);
        $pedro = Guest::factory()->walkIn()->create(['name' => 'Pedro Reyes', 'phone' => '0922 333 4444', 'branch_id' => $this->sanMateo->id]);

        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ListCustomers::class)
            ->searchTable('abc 1234')->assertCanSeeTableRecords([$maria])->assertCanNotSeeTableRecords([$pedro])
            ->searchTable('0922')->assertCanSeeTableRecords([$pedro])->assertCanNotSeeTableRecords([$maria])
            ->searchTable('maria@example')->assertCanSeeTableRecords([$maria])
            ->searchTable('')
            ->filterTable('branch_id', $this->sanMateo->id)->assertCanSeeTableRecords([$pedro])->assertCanNotSeeTableRecords([$maria]);
    }

    public function test_the_team_adds_a_walk_in_customer(): void
    {
        $this->actingAs($this->staff);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Pedro Reyes', 'phone' => '0922 333 4444', 'branch_id' => $this->branch->id, 'sales_rep_id' => $this->staff->id, 'notes' => 'Repeat client'])
            ->call('create')
            ->assertHasNoFormErrors();

        $guest = Guest::sole();
        $this->assertSame([null, null, 'walk_in', $this->staff->id, '9223334444'], [$guest->google_id, $guest->email, $guest->source, $guest->sales_rep_id, $guest->phone_key]);
    }

    public function test_setting_a_unit_s_size_prices_its_waiting_bookings(): void
    {
        $guest = Guest::factory()->create();
        $vehicle = Vehicle::factory()->create(['guest_id' => $guest->id]);
        $waiting = Booking::factory()->create(['guest_id' => $guest->id, 'vehicle_id' => $vehicle->id, 'branch_id' => $this->branch->id]);
        $waiting->items()->create(['service_id' => $this->detail->id, 'service_name' => 'Exterior detailing']);
        $done = Booking::factory()->priced(999)->status('exited')->create(['guest_id' => $guest->id, 'vehicle_id' => $vehicle->id, 'branch_id' => $this->branch->id]);

        $this->actingAs($this->staff);

        Livewire::test(UnitsRelationManager::class, ['ownerRecord' => $guest, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$vehicle])
            ->callTableAction('setSize', $vehicle, ['vehicle_size_id' => $this->medium->id])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($vehicle->fresh()->isReviewed());
        $this->assertSame([2500, 'Medium'], [$waiting->fresh()->total_price, $waiting->fresh()->vehicle_size_name]);
        $this->assertNotNull($waiting->fresh()->priced_at);
        $this->assertSame(999, $done->fresh()->total_price);
    }

    public function test_the_booking_page_links_to_the_customer(): void
    {
        $guest = Guest::factory()->create();
        $booking = Booking::factory()->create(['guest_id' => $guest->id, 'branch_id' => $this->branch->id]);

        $this->actingAs($this->staff)->get("/admin/bookings/{$booking->id}")->assertOk()->assertSee("/admin/customers/{$guest->id}", escape: false);
    }

    public function test_only_owners_delete_and_never_a_customer_with_bookings(): void
    {
        $withBookings = Guest::factory()->create();
        Booking::factory()->create(['guest_id' => $withBookings->id, 'branch_id' => $this->branch->id]);
        $empty = Guest::factory()->walkIn()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->staff);
        Livewire::test(ViewCustomer::class, ['record' => $empty->id])->assertActionHidden('delete');

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(ViewCustomer::class, ['record' => $withBookings->id])->callAction('delete');
        $this->assertModelExists($withBookings);

        Livewire::test(ViewCustomer::class, ['record' => $empty->id])->callAction('delete');
        $this->assertModelMissing($empty);
    }

    public function test_the_new_pages_open_for_staff_and_the_customer_page_shows_its_sections(): void
    {
        $guest = Guest::factory()->create(['name' => 'Maria Santos', 'branch_id' => $this->branch->id, 'referral_code' => 'MARIA-7K3Q']);
        Vehicle::factory()->create(['guest_id' => $guest->id, 'plate_number' => 'ABC1234']);
        Booking::factory()->create(['guest_id' => $guest->id, 'branch_id' => $this->branch->id]);

        $this->actingAs($this->staff);

        foreach (['/admin/customers', '/admin/customers/create', "/admin/customers/{$guest->id}/edit", '/admin/warranty', '/admin/manual-transaction'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get("/admin/customers/{$guest->id}")->assertOk()->assertSee('MARIA-7K3Q')->assertSee('services are covered now');
    }

    public function test_a_unit_shows_its_jobs_last_service_and_history_at_the_branches_you_see(): void
    {
        $guest = Guest::factory()->create();
        $unit = Vehicle::factory()->create(['guest_id' => $guest->id, 'model' => 'Honda City']);
        $empty = Vehicle::factory()->create(['guest_id' => $guest->id]);
        $make = fn (Branch $branch, string $status, ?string $exited, ?int $price, string $service) => tap(
            Booking::factory()->create(['guest_id' => $guest->id, 'vehicle_id' => $unit->id, 'branch_id' => $branch->id, 'status' => $status, 'exited_at' => $exited, 'drop_off_on' => '2026-11-01', 'total_price' => $price, 'priced_at' => $price ? now() : null]),
            fn (Booking $booking) => $booking->items()->create(['service_name' => $service, 'price' => $price]),
        );
        $first = $make($this->branch, 'exited', '2026-09-05 12:00:00', 2500, 'Exterior detailing');
        $second = $make($this->branch, 'exited', '2026-10-20 12:00:00', 18000, 'Graphene coating');
        $make($this->branch, 'confirmed', null, null, 'Interior detailing');
        // Another unit's booking and another branch's booking are not in this staff member's history
        $make($this->sanMateo, 'exited', '2026-11-10 12:00:00', 999, 'San Mateo wash');
        Booking::factory()->create(['guest_id' => $guest->id, 'vehicle_id' => $empty->id, 'branch_id' => $this->branch->id, 'exited_at' => '2026-11-12 12:00:00', 'status' => 'exited']);

        $this->actingAs($this->staff);

        $table = Livewire::test(UnitsRelationManager::class, ['ownerRecord' => $guest, 'pageClass' => ViewCustomer::class])
            ->assertCanRenderTableColumn('jobs_count')
            ->assertCanRenderTableColumn('last_service_at')
            ->assertTableColumnStateSet('jobs_count', 3, $unit)
            ->assertTableColumnStateSet('jobs_count', 1, $empty)
            ->assertTableColumnFormattedStateSet('last_service_at', 'Oct 20, 2026', $unit)
            ->assertTableColumnFormattedStateSet('last_service_at', 'Nov 12, 2026', $empty)
            ->assertTableActionVisible('history', $unit)
            ->assertTableActionHasIcon('history', Heroicon::OutlinedClipboardDocumentList, $unit)
            ->mountTableAction('history', $unit)
            ->assertMountedActionModalSee($first->reference)
            ->assertMountedActionModalSee($second->reference)
            ->assertMountedActionModalSee('Exterior detailing')
            ->assertMountedActionModalSee('Graphene coating')
            ->assertMountedActionModalSee('Interior detailing')
            ->assertMountedActionModalSee('Oct 20, 2026')
            ->assertMountedActionModalSee('₱18,000')
            ->assertMountedActionModalSee('Size needed')
            ->assertMountedActionModalSee('Cainta')
            ->assertMountedActionModalSee('/admin/bookings/'.$second->id, escape: false)
            ->assertMountedActionModalDontSee('San Mateo wash')
            ->assertMountedActionModalDontSee('₱999');

        // The owner sees the San Mateo job too
        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(UnitsRelationManager::class, ['ownerRecord' => $guest, 'pageClass' => ViewCustomer::class])
            ->assertTableColumnStateSet('jobs_count', 4, $unit)
            ->assertTableColumnFormattedStateSet('last_service_at', 'Nov 10, 2026', $unit)
            ->mountTableAction('history', $unit)
            ->assertMountedActionModalSee('San Mateo wash');
    }

    public function test_a_unit_with_no_finished_job_has_no_last_service_date(): void
    {
        $guest = Guest::factory()->create();
        $unit = Vehicle::factory()->create(['guest_id' => $guest->id]);
        $this->actingAs($this->staff);

        Livewire::test(UnitsRelationManager::class, ['ownerRecord' => $guest, 'pageClass' => ViewCustomer::class])
            ->assertTableColumnStateSet('jobs_count', 0, $unit)
            ->assertTableColumnStateSet('last_service_at', null, $unit)
            ->mountTableAction('history', $unit)
            ->assertMountedActionModalSee('No bookings for this unit at your branches.');
    }

    public function test_the_referral_tab_shows_who_used_the_code_the_booking_the_status_and_the_points(): void
    {
        $owner = Guest::factory()->create(['name' => 'Juan dela Cruz']);
        $awarded = Booking::factory()->create(['guest_id' => Guest::factory()->create(['name' => 'Maria Santos'])->id, 'branch_id' => $this->branch->id, 'status' => 'exited']);
        $pending = Booking::factory()->create(['guest_id' => Guest::factory()->create(['name' => 'Pedro Reyes'])->id, 'branch_id' => $this->branch->id, 'status' => 'confirmed']);
        $voided = Booking::factory()->create(['guest_id' => Guest::factory()->create(['name' => 'Ana Lim'])->id, 'branch_id' => $this->branch->id, 'status' => 'cancelled']);
        $rows = [
            [$awarded, ['points_awarded' => 100, 'awarded_at' => now()]],
            [$pending, []],
            [$voided, ['voided_at' => now()]],
        ];
        $redemptions = collect($rows)->map(fn (array $row) => ReferralRedemption::create(['code_guest_id' => $owner->id, 'used_by_guest_id' => $row[0]->guest_id, 'booking_id' => $row[0]->id, ...$row[1]]));

        $this->actingAs($this->staff);

        Livewire::test(ReferralRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords($redemptions)
            ->assertTableColumnStateSet('usedBy.name', 'Maria Santos', $redemptions[0])
            ->assertTableColumnStateSet('booking.reference', $awarded->reference, $redemptions[0])
            ->assertTableColumnStateSet('status', 'Awarded', $redemptions[0])
            ->assertTableColumnStateSet('points_awarded', 100, $redemptions[0])
            ->assertTableColumnStateSet('usedBy.name', 'Pedro Reyes', $redemptions[1])
            ->assertTableColumnStateSet('status', 'Pending', $redemptions[1])
            ->assertTableColumnStateSet('status', 'Voided', $redemptions[2]);
    }
}
