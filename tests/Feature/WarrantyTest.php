<?php

namespace Tests\Feature;

use App\Filament\Pages\Warranty as WarrantyPage;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\Backjob;
use App\Models\BackjobType;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\Service;
use App\Models\User;
use App\Support\Warranty;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsShop;
use Tests\TestCase;

/**
 * The warranty checker: coverage dates from the exit day, no warranty,
 * the snapshot on each booking item, and logging backjobs.
 */
class WarrantyTest extends TestCase
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

    /**
     * @return array{0: Booking, 1: BookingItem}
     */
    private function finished(string $exited, ?int $months, array $booking = []): array
    {
        $booking = Booking::factory()->status('exited')->create(['branch_id' => $this->branch->id, 'exited_at' => CarbonImmutable::parse($exited, 'Asia/Manila'), ...$booking]);
        $item = $booking->items()->create(['service_id' => $this->coating->id, 'service_name' => 'Graphene coating', 'warranty_months' => $months]);

        return [$booking, $item];
    }

    public function test_coverage_ends_the_same_day_of_the_month_or_the_month_s_last_day(): void
    {
        [$booking, $item] = $this->finished('2026-01-31 14:00', 1);
        $this->assertSame('2026-02-28', Warranty::endsOn($booking, $item)->toDateString());

        [$booking, $item] = $this->finished('2026-08-31 09:00', 6);
        $this->assertSame('2027-02-28', Warranty::endsOn($booking, $item)->toDateString());

        [$booking, $item] = $this->finished('2024-02-29 09:00', 24);
        $this->assertSame('2026-02-28', Warranty::endsOn($booking, $item)->toDateString());

        [$booking, $item] = $this->finished('2026-05-15 09:00', 12);
        $this->assertSame('2027-05-15', Warranty::endsOn($booking, $item)->toDateString());
    }

    public function test_active_through_the_last_day_then_expired_and_none_without_months(): void
    {
        [$booking, $item] = $this->finished('2025-11-16 10:00', 12);
        $this->assertSame('active', Warranty::status($booking, $item));

        $this->travelTo(CarbonImmutable::parse('2026-11-17 07:00', 'Asia/Manila'));
        $this->assertSame('expired', Warranty::status($booking, $item));

        [$booking, $item] = $this->finished('2026-11-01 10:00', null);
        $this->assertSame('none', Warranty::status($booking, $item));
        $this->assertNull(Warranty::endsOn($booking, $item));

        $open = Booking::factory()->create(['branch_id' => $this->branch->id]);
        $this->assertNull(Warranty::endsOn($open, $item));
    }

    public function test_a_booking_keeps_the_warranty_it_was_booked_with(): void
    {
        $this->coating->update(['warranty_months' => 12]);
        $guest = Guest::factory()->create();

        $this->actingAs($guest, 'guest')->postJson('/api/bookings', $this->reservation(['services' => [$this->coating->id, $this->detail->id]]))->assertCreated();

        $this->assertSame([null, 12], Booking::sole()->items->pluck('warranty_months')->all());

        $this->coating->update(['warranty_months' => 6]);
        $this->assertSame([null, 12], Booking::sole()->items->pluck('warranty_months')->all());
    }

    public function test_the_service_form_saves_the_months_and_the_backjob_types_it_covers(): void
    {
        $peeling = BackjobType::create(['name' => 'Coating peeling']);
        BackjobType::create(['name' => 'Tint bubbling']);

        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(EditService::class, ['record' => $this->coating->id])
            ->fillForm(['warranty_months' => 18, 'backjobTypes' => [$peeling->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(18, $this->coating->fresh()->warranty_months);
        $this->assertSame([$peeling->id], $this->coating->backjobTypes()->pluck('warranty_backjob_types.id')->all());
        $this->assertSame(0, BackjobType::where('name', 'like', 'Sample%')->count());
    }

    public function test_backjob_types_are_not_seeded_and_only_owners_manage_them(): void
    {
        $this->seed();
        $this->assertSame(0, BackjobType::count());
        $this->assertSame(0, Service::whereNotNull('warranty_months')->count());

        $this->actingAs($this->staff)->get($this->panel('/backjob-types'))->assertForbidden();
        $this->actingAs(User::factory()->owner()->create())->get($this->panel('/backjob-types'))->assertOk();
    }

    public function test_the_checker_finds_a_job_by_plate_name_phone_or_reference(): void
    {
        [$booking] = $this->finished('2026-10-01 10:00', 12, ['plate_number' => 'ABC1234', 'name' => 'Maria Santos', 'phone' => '0917 123 4567']);
        $booking->items()->first()->service->backjobTypes()->create(['name' => 'Coating peeling']);
        $this->finished('2026-10-01 10:00', 12, ['plate_number' => 'ZZZ9999', 'name' => 'Pedro Reyes', 'phone' => '0922 555 0000']);

        $this->actingAs($this->staff);

        foreach (['abc 1234', 'maria', '0917123', '+63 917 123 4567', $booking->reference] as $term) {
            Livewire::test(WarrantyPage::class)
                ->set('search', $term)
                ->assertSee($booking->reference)
                ->assertSee('Oct 1, 2026')
                ->assertSee('Oct 1, 2027')
                ->assertSee('Active')
                ->assertSee('Coating peeling')
                ->assertDontSee('ZZZ9999');
        }

        Livewire::test(WarrantyPage::class)->assertSee('Search for a finished job');
        Livewire::test(WarrantyPage::class)->set('search', 'nobody here')->assertSee('No finished job found');
    }

    public function test_the_checker_lists_expired_and_no_warranty_and_skips_unfinished_and_other_branches(): void
    {
        $this->finished('2025-01-10 10:00', 6, ['name' => 'Old Job']);
        $this->finished('2026-10-10 10:00', null, ['name' => 'Old Job']);
        Booking::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Old Job Open', 'status' => 'in_progress']);
        Booking::factory()->status('exited')->create(['branch_id' => $this->sanMateo->id, 'name' => 'Old Job Elsewhere', 'exited_at' => now()]);

        $this->actingAs($this->staff);

        Livewire::test(WarrantyPage::class)
            ->set('search', 'Old Job')
            ->assertSee('Expired')
            ->assertSee('No warranty')
            ->assertDontSee('Old Job Open')
            ->assertDontSee('Old Job Elsewhere');
    }

    public function test_the_checker_can_show_one_customer_and_refuses_an_unseen_one(): void
    {
        $guest = Guest::factory()->create(['name' => 'Maria Santos']);
        [$mine] = $this->finished('2026-10-01 10:00', 12, ['guest_id' => $guest->id]);
        [$other] = $this->finished('2026-10-01 10:00', 12);
        $elsewhere = Guest::factory()->create();
        Booking::factory()->create(['guest_id' => $elsewhere->id, 'branch_id' => $this->sanMateo->id]);

        $this->actingAs($this->staff);

        Livewire::withQueryParams(['customer' => $guest->id])->test(WarrantyPage::class)
            ->assertSee($mine->reference)
            ->assertDontSee($other->reference);

        $this->get($this->panel('/warranty?customer=').$elsewhere->id)->assertNotFound();
    }

    public function test_logging_a_backjob_with_a_type_the_service_covers_or_other(): void
    {
        [$booking, $item] = $this->finished('2026-10-01 10:00', 12);
        $peeling = BackjobType::create(['name' => 'Coating peeling']);
        $bubbling = BackjobType::create(['name' => 'Tint bubbling']);
        $this->coating->backjobTypes()->attach($peeling);

        $this->actingAs($this->staff);

        $page = Livewire::test(WarrantyPage::class)->set('search', $booking->reference);

        $page->callAction('logBackjob', ['booking_id' => $booking->id, 'booking_item_id' => $item->id, 'backjob_type_id' => $peeling->id, 'noted_on' => '2026-11-10', 'note' => 'Peeling at the hood'], ['booking' => $booking->id])
            ->assertHasNoActionErrors();

        $backjob = Backjob::sole();
        $this->assertSame([$booking->id, $item->id, $peeling->id, $this->branch->id, 'open', $this->staff->id, 'Peeling at the hood'], [$backjob->booking_id, $backjob->booking_item_id, $backjob->backjob_type_id, $backjob->branch_id, $backjob->status, $backjob->created_by, $backjob->note]);
        $this->assertSame('2026-11-10', $backjob->noted_on->toDateString());

        // A type the service doesn't cover is refused; Other is allowed
        $page->callAction('logBackjob', ['booking_id' => $booking->id, 'booking_item_id' => $item->id, 'backjob_type_id' => $bubbling->id, 'noted_on' => '2026-11-11'], ['booking' => $booking->id])
            ->assertHasActionErrors(['backjob_type_id']);
        $this->assertSame(1, Backjob::count());

        Livewire::test(WarrantyPage::class)->callAction('logBackjob', ['booking_id' => $booking->id, 'booking_item_id' => $item->id, 'backjob_type_id' => 'other', 'noted_on' => '2026-11-12'], ['booking' => $booking->id])
            ->assertHasNoActionErrors();
        $this->assertSame([null], Backjob::latest('id')->limit(1)->pluck('backjob_type_id')->all());

        // The history shows them, and one can be marked done
        Livewire::test(WarrantyPage::class)->set('search', $booking->reference)->assertSee('Peeling at the hood')->assertSee('Other');

        Livewire::test(WarrantyPage::class)->set('search', $booking->reference)->callAction('markBackjobDone', arguments: ['backjob' => $backjob->id]);
        $this->assertSame('done', $backjob->fresh()->status);
    }

    public function test_staff_cannot_log_or_close_a_backjob_on_another_branch(): void
    {
        $elsewhere = Booking::factory()->status('exited')->create(['branch_id' => $this->sanMateo->id, 'exited_at' => now()]);
        $item = $elsewhere->items()->create(['service_name' => 'Graphene coating']);
        $backjob = Backjob::create(['booking_id' => $elsewhere->id, 'branch_id' => $this->sanMateo->id, 'noted_on' => today(), 'status' => 'open']);

        $this->actingAs($this->staff);

        Livewire::test(WarrantyPage::class)->callAction('logBackjob', ['booking_id' => $elsewhere->id, 'booking_item_id' => $item->id, 'backjob_type_id' => 'other', 'noted_on' => '2026-11-12'], ['booking' => $elsewhere->id]);
        $this->assertSame(1, Backjob::count());

        try {
            Livewire::test(WarrantyPage::class)->callAction('markBackjobDone', arguments: ['backjob' => $backjob->id]);
        } catch (\Throwable) {
        }

        $this->assertSame('open', $backjob->fresh()->status);
    }
}
