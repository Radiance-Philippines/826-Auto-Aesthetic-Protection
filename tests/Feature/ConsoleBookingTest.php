<?php

namespace Tests\Feature;

use App\Mail\CustomerBookingUpdate;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\MailSetting;
use App\Models\PaymentSetting;
use App\Models\User;
use App\Support\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsShop;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Bookings endpoints, under the Filament resource's rules:
 * the panel, the policy, each action's area and the person's branches.
 */
class ConsoleBookingTest extends TestCase
{
    use BuildsShop, RefreshDatabase, StaffApi;

    private const BOOKINGS = '/console/api/sales/bookings';

    private Branch $sanMateo;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->buildShop();
        $this->sanMateo = Branch::factory()->cap(2)->create(['name' => 'San Mateo']);
        Mail::fake();
        MailSetting::instance()->update(['address' => 'shop@826auto.test', 'password' => 'secret', 'from_name' => '826', 'notify_email' => 'alerts@826auto.test']);
    }

    private function sales(): User
    {
        $user = $this->staffUser('sales_admin', $this->branch);
        $this->actingAs($user);

        return $user;
    }

    private function booking(array $state = [], ?Branch $branch = null): Booking
    {
        return Booking::factory()->on('2026-11-17')->create(['branch_id' => ($branch ?? $this->branch)->id, ...$state]);
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $over = []): array
    {
        return [
            'kind' => 'onsite',
            'source' => 'phone',
            'branchId' => $this->branch->id,
            'services' => [$this->detail->id],
            'dropOffOn' => '2026-11-18',
            'vehicleYear' => 2021,
            'vehicleModel' => 'Toyota Vios',
            'vehicleType' => 'sedan',
            'plateNumber' => 'abc 1234',
            'name' => 'Maria Santos',
            'phone' => '0917 123 4567',
            ...$over,
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return [
            'list' => ['getJson', ''],
            'create' => ['postJson', ''],
            'form' => ['getJson', '/form'],
            'view' => ['getJson', '/{id}'],
            'edit' => ['getJson', '/{id}/edit'],
            'update' => ['patchJson', '/{id}'],
            'delete' => ['deleteJson', '/{id}'],
            'bulk mark' => ['postJson', '/mark'],
            'bulk delete' => ['postJson', '/delete'],
            'mark unread' => ['postJson', '/{id}/unread'],
            'cancel' => ['postJson', '/{id}/cancel'],
            'no-show' => ['postJson', '/{id}/no-show'],
            'move dates' => ['getJson', '/{id}/dates'],
            'move' => ['postJson', '/{id}/reschedule'],
            'schedule' => ['postJson', '/{id}/schedule'],
            'approve payment' => ['postJson', '/{id}/payment/approve'],
            'reject payment' => ['postJson', '/{id}/payment/reject'],
            'size' => ['postJson', '/{id}/size'],
            'stage' => ['postJson', '/{id}/stage'],
            'ready date' => ['postJson', '/{id}/ready-date'],
            'assigned to' => ['postJson', '/{id}/assigned-to'],
        ];
    }

    private function callEndpoint(string $method, string $path, Booking $booking): TestResponse
    {
        $url = self::BOOKINGS.str_replace('{id}', (string) $booking->id, $path);

        return $method === 'getJson' ? $this->getJson($url) : $this->{$method}($url, [
            ...$this->form(),
            'ids' => [$booking->id],
            'read' => false,
            'to' => 'arrived',
            'date' => '2026-11-20',
            'reason' => 'x',
            'assignedTo' => 'Jun',
            'vehicle_size_id' => $this->medium->id,
        ]);
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $booking = $this->booking();

        $this->callEndpoint($method, $path, $booking)->assertUnauthorized();
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    #[DataProvider('endpoints')]
    public function test_without_bookings_view_every_call_is_403(string $method, string $path): void
    {
        $booking = $this->booking();
        $this->actingAs($this->staffUser('sales_admin', $this->branch, ['permissions' => ['customers.view']]));

        $this->callEndpoint($method, $path, $booking)->assertForbidden();
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame(1, Booking::count());
    }

    #[DataProvider('endpoints')]
    public function test_a_panel_that_does_not_show_bookings_is_404(string $method, string $path): void
    {
        $booking = $this->booking();
        $this->actingAs(User::factory()->owner()->create());
        $url = str_replace('/sales/', '/owner/', self::BOOKINGS).str_replace('{id}', (string) $booking->id, $path);

        ($method === 'getJson' ? $this->getJson($url) : $this->{$method}($url))->assertNotFound();
    }

    public function test_another_branchs_booking_is_404_and_untouched(): void
    {
        $other = $this->booking(['status' => 'payment_review', 'name' => 'Elsewhere'], $this->sanMateo);
        $this->sales();

        foreach (self::endpoints() as [$method, $path]) {
            if (str_contains($path, '{id}') && ! in_array($path, ['/{id}/stage', '/{id}/ready-date', '/{id}/assigned-to'], true)) {
                $this->callEndpoint($method, $path, $other)->assertNotFound();
            }
        }

        $this->postJson(self::BOOKINGS."/{$other->id}/stage", ['to' => 'arrived'])->assertForbidden();
        $this->getJson(self::BOOKINGS.'?tab=all&search=Elsewhere')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson(self::BOOKINGS.'/mark', ['ids' => [$other->id], 'read' => true])->assertOk()->assertJsonPath('count', 0);
        $this->assertSame('payment_review', $other->fresh()->status);
    }

    public function test_the_list_has_the_filament_tabs_filters_search_and_only_their_branch(): void
    {
        $today = $this->booking(['drop_off_on' => '2026-11-16', 'name' => 'Ana Today', 'plate_number' => 'AAA111']);
        $later = $this->booking(['drop_off_on' => '2026-11-20', 'name' => 'Ben Later', 'priced_at' => now()]);
        $done = $this->booking(['drop_off_on' => '2026-11-10', 'status' => 'exited', 'name' => 'Cara Done', 'priced_at' => now()]);
        $this->booking([], $this->sanMateo);
        $this->sales();

        $this->getJson(self::BOOKINGS)
            ->assertOk()
            ->assertJsonPath('tab', 'upcoming')
            ->assertJsonPath('data.*.id', [$today->id, $later->id])
            ->assertJsonPath('meta', ['total' => 2, 'perPage' => 25])
            ->assertJsonPath('tabs.0', ['key' => 'action', 'label' => 'Needs you', 'badge' => 1])
            ->assertJsonPath('tabs.1.badge', 1)
            ->assertJsonPath('tabs.*.label', ['Needs you', 'Needs size review', 'Today', 'In the shop', 'Upcoming', 'Unpaid holds', 'Home service', 'Done', 'Cancelled', 'All'])
            ->assertJsonPath('filters.branches', [])
            ->assertJsonPath('can', ['create' => true, 'delete' => false])
            ->assertJsonPath('data.0.unread', true);

        $this->getJson(self::BOOKINGS.'?tab=today')->assertJsonPath('data.*.id', [$today->id]);
        $this->getJson(self::BOOKINGS.'?tab=past')->assertJsonPath('data.*.id', [$done->id]);
        $this->getJson(self::BOOKINGS.'?tab=all&search=ben+later')->assertJsonPath('data.*.id', [$later->id]);
        $this->getJson(self::BOOKINGS.'?tab=all&search=aaa111')->assertJsonPath('data.*.id', [$today->id]);
        $this->getJson(self::BOOKINGS.'?tab=all&status[]=exited')->assertJsonPath('data.*.id', [$done->id]);
        $this->getJson(self::BOOKINGS.'?tab=all&kind=home')->assertJsonPath('meta.total', 0);
        $this->getJson(self::BOOKINGS.'?sort=dropOff&desc=1')->assertJsonPath('data.*.id', [$later->id, $today->id]);
    }

    public function test_the_top_bars_branch_narrows_the_list(): void
    {
        $here = $this->booking();
        $there = $this->booking([], $this->sanMateo);
        $this->actingAs(User::factory()->owner()->create());

        $this->getJson(self::BOOKINGS)->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'filters.branches');
        $this->getJson(self::BOOKINGS."?branch={$this->sanMateo->id}")->assertJsonPath('data.*.id', [$there->id]);

        $this->withSession([CurrentBranch::SESSION => $this->branch->id]);
        $this->getJson(self::BOOKINGS)->assertJsonPath('data.*.id', [$here->id]);
        $this->getJson(self::BOOKINGS."/{$there->id}")->assertNotFound();
    }

    public function test_opening_a_booking_marks_it_read_and_offers_actions_by_area_and_state(): void
    {
        $booking = $this->booking(['status' => 'payment_review', 'amount_due' => 500]);
        $this->sales();

        $this->getJson(self::BOOKINGS."/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('title', "{$booking->name} · {$booking->reference}")
            ->assertJsonPath('actions', ['markPaid', 'rejectProof', 'setSize', 'reschedule', 'cancelBooking'])
            ->assertJsonPath('can', ['edit' => true, 'delete' => false])
            ->assertJsonPath('fee.amount', '₱500')
            ->assertJsonPath('link', $booking->customerUrl());
        $this->assertNotNull($booking->fresh()->read_at);

        $this->postJson(self::BOOKINGS."/{$booking->id}/unread")->assertNoContent();
        $this->assertNull($booking->fresh()->read_at);

        // A detailer sees no money and none of the sales actions
        $this->actingAs($this->staffUser('operations', $this->branch));
        $this->getJson(str_replace('sales', 'operations', self::BOOKINGS)."/{$booking->id}")
            ->assertJsonPath('actions', ['assignedTo'])
            ->assertJsonPath('fee', null);
    }

    public function test_the_sales_steps_are_closed_to_a_detailer(): void
    {
        $booking = $this->booking(['status' => 'confirmed', 'drop_off_on' => '2026-11-10']);
        $this->actingAs($this->staffUser('operations', $this->branch));
        $url = str_replace('sales', 'operations', self::BOOKINGS)."/{$booking->id}";

        foreach (['cancel', 'no-show', 'reschedule', 'schedule', 'size', 'payment/approve', 'payment/reject'] as $step) {
            $this->postJson("{$url}/{$step}", ['dropOffOn' => '2026-11-20', 'reason' => 'x', 'vehicle_size_id' => $this->medium->id])->assertForbidden();
        }

        $this->deleteJson($url)->assertForbidden();
        $this->postJson(self::BOOKINGS.'/mark')->assertForbidden();
        $this->assertSame('confirmed', $booking->fresh()->status);

        // But the details of the unit are theirs to correct, and only those
        $this->patchJson($url, ['vehicleYear' => 2020, 'vehicleModel' => 'Honda City', 'vehicleType' => 'sedan', 'plateNumber' => 'xyz 99', 'name' => 'Changed', 'phone' => '0999'])->assertOk();
        $this->assertSame(['Honda City', 'XYZ99', $booking->name], array_values($booking->fresh()->only('vehicle_model', 'plate_number', 'name')));
    }

    public function test_a_booking_is_added_and_a_full_day_is_refused_until_forced(): void
    {
        $user = $this->sales();

        $this->getJson(self::BOOKINGS.'/form?branch='.$this->branch->id.'&date=2026-11-18')
            ->assertOk()
            ->assertJsonPath('services.0.label', 'Exterior detailing · 1 day')
            ->assertJsonPath('canOverbook', false)
            ->assertJsonPath('can.create', true);

        $id = $this->postJson(self::BOOKINGS, $this->form())
            ->assertCreated()
            ->assertJsonPath('message', 'Booked: Wed, Nov 18 · ready around Wed, Nov 18')
            ->json('id');
        $booking = Booking::findOrFail($id);
        $this->assertSame(['confirmed', 'phone', 'ABC1234', 'Maria Santos'], [$booking->status, $booking->source, $booking->plate_number, $booking->name]);
        $this->assertSame('phone', Guest::findOrFail($booking->guest_id)->source);

        $this->booking(['drop_off_on' => '2026-11-18']);
        $this->postJson(self::BOOKINGS, $this->form(['force' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dropOffOn' => 'ask a branch manager']);

        $manager = $this->staffUser('branch_manager', $this->branch);
        $this->actingAs($manager);
        $url = str_replace('sales', 'operations', self::BOOKINGS);
        $this->getJson($url.'/form?branch='.$this->branch->id.'&date=2026-11-18')->assertJsonPath('canOverbook', true);
        $this->postJson($url, $this->form(['force' => true, 'plateNumber' => 'def 5678']))->assertCreated();
        $this->assertNotSame($user->id, $manager->id);
    }

    public function test_a_home_request_is_added(): void
    {
        $this->sales();

        $id = $this->postJson(self::BOOKINGS, $this->form(['kind' => 'home', 'dropOffOn' => null, 'address' => '12 Sampaguita St', 'preferredDate' => '2026-11-20', 'hasWater' => true, 'hasPower' => false]))
            ->assertCreated()
            ->assertJsonPath('message', 'Home service request added')
            ->json('id');
        $booking = Booking::findOrFail($id);

        $this->assertSame(['home', 'requested', true, false, null], [$booking->kind, $booking->status, $booking->has_water, $booking->has_power, $booking->has_shade]);
        $this->assertSame('2026-11-20', $booking->preferred_date->toDateString());
    }

    public function test_what_the_form_refuses(): void
    {
        $this->sales();

        $this->postJson(self::BOOKINGS, [])->assertUnprocessable()->assertJsonValidationErrors(['kind', 'source', 'branchId', 'services', 'dropOffOn', 'vehicleYear', 'vehicleModel', 'vehicleType', 'plateNumber', 'name', 'phone']);
        $this->postJson(self::BOOKINGS, $this->form(['branchId' => $this->sanMateo->id]))->assertUnprocessable()->assertJsonValidationErrors(['branchId']);
        $this->postJson(self::BOOKINGS, $this->form(['kind' => 'home', 'services' => [$this->coating->id]]))->assertUnprocessable()->assertJsonValidationErrors(['services.0', 'address']);
        $this->postJson(self::BOOKINGS, $this->form(['vehicleYear' => 1900, 'phone' => 'call me', 'email' => 'no']))->assertUnprocessable()->assertJsonValidationErrors(['vehicleYear', 'phone', 'email']);
        $this->assertSame(0, Booking::count());
    }

    public function test_editing_a_changed_unit_clears_its_price(): void
    {
        $booking = $this->booking(['priced_at' => now(), 'vehicle_model' => 'Old']);
        $this->sales();
        $values = $this->getJson(self::BOOKINGS."/{$booking->id}/edit")->assertOk()->json('values');

        $this->patchJson(self::BOOKINGS."/{$booking->id}", [...$values, 'adminNote' => 'VIP'])->assertOk()->assertJsonPath('message', 'Saved');
        $this->assertNotNull($booking->fresh()->priced_at);

        $this->patchJson(self::BOOKINGS."/{$booking->id}", [...$values, 'vehicleModel' => 'New'])->assertOk();
        $this->assertNull($booking->fresh()->priced_at);

        $this->patchJson(self::BOOKINGS."/{$booking->id}", [...$values, 'name' => '', 'phone' => ''])->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone']);
    }

    public function test_cancel_declines_a_request_and_cancels_a_booking(): void
    {
        $booking = $this->booking(['email' => 'maria@example.com']);
        $request = $this->booking(['kind' => 'home', 'status' => 'requested', 'drop_off_on' => null]);
        $this->sales();

        $this->postJson(self::BOOKINGS."/{$booking->id}/cancel", ['reason' => 'Power cut'])->assertOk()->assertJsonPath('status', 'Cancelled');
        $this->assertSame(['cancelled', 'staff', 'Power cut'], array_values($booking->fresh()->only('status', 'cancelled_by', 'cancel_reason')));
        Mail::assertSent(CustomerBookingUpdate::class);

        $this->postJson(self::BOOKINGS."/{$request->id}/cancel")->assertOk()->assertJsonPath('status', 'Declined');
        $this->postJson(self::BOOKINGS."/{$booking->id}/cancel")->assertUnprocessable();
        $this->postJson(self::BOOKINGS."/{$booking->id}/cancel", ['reason' => str_repeat('a', 251)])->assertUnprocessable();
    }

    public function test_no_show_only_after_the_day(): void
    {
        $tomorrow = $this->booking();
        $past = $this->booking(['drop_off_on' => '2026-11-13']);
        $this->sales();

        $this->postJson(self::BOOKINGS."/{$tomorrow->id}/no-show")->assertUnprocessable();
        $this->postJson(self::BOOKINGS."/{$past->id}/no-show")->assertOk()->assertJsonPath('status', 'No-show');
    }

    public function test_move_checks_the_day(): void
    {
        $booking = $this->booking(['total_days' => 1]);
        $this->booking(['drop_off_on' => '2026-11-19']);
        $this->booking(['drop_off_on' => '2026-11-19']);
        $this->sales();

        $this->assertContains('2026-11-19', $this->getJson(self::BOOKINGS."/{$booking->id}/dates")->assertOk()->json('blocked'));
        $this->postJson(self::BOOKINGS."/{$booking->id}/reschedule", ['dropOffOn' => '2026-11-19'])->assertUnprocessable()->assertJsonValidationErrors(['dropOffOn']);
        $this->postJson(self::BOOKINGS."/{$booking->id}/reschedule", [])->assertUnprocessable()->assertJsonValidationErrors(['dropOffOn']);
        $this->postJson(self::BOOKINGS."/{$booking->id}/reschedule", ['dropOffOn' => '2026-11-20'])->assertOk()->assertJsonPath('when', 'Fri, Nov 20 · ready around Fri, Nov 20');
    }

    public function test_a_home_visit_is_scheduled_with_the_fee_when_asked(): void
    {
        PaymentSetting::instance()->update(['fee_enabled' => true, 'fee_amount' => 500]);
        $request = $this->booking(['kind' => 'home', 'status' => 'requested', 'drop_off_on' => null, 'preferred_date' => '2026-11-20', 'total_days' => 1]);
        $this->sales();

        $this->postJson(self::BOOKINGS."/{$request->id}/schedule", ['dropOffOn' => '2026-11-20', 'readyOn' => '2026-11-19'])->assertUnprocessable()->assertJsonValidationErrors(['readyOn']);
        $this->postJson(self::BOOKINGS."/{$request->id}/schedule", ['dropOffOn' => '2026-11-20', 'askFee' => true])->assertOk();

        $request->refresh();
        $this->assertSame(PaymentSetting::instance()->feeEnabled() ? 'awaiting_payment' : 'confirmed', $request->status);
        $this->assertSame('2026-11-20', $request->drop_off_on->toDateString());
    }

    public function test_the_shared_staff_actions_work_with_the_session(): void
    {
        $booking = $this->booking(['status' => 'payment_review']);
        $this->sales();

        $this->postJson(self::BOOKINGS."/{$booking->id}/payment/approve", ['note' => 'GC123'])->assertOk();
        $this->assertSame(['confirmed', 'GC123'], array_values($booking->fresh()->only('status', 'payment_note')));
        $this->postJson(self::BOOKINGS."/{$booking->id}/size", ['vehicle_size_id' => $this->medium->id])->assertOk()->assertJsonPath('missingPrices', []);
        $this->assertSame('Medium', $booking->fresh()->vehicle_size_name);
    }

    public function test_only_a_super_admin_deletes_one_or_many(): void
    {
        $one = $this->booking();
        $two = $this->booking();
        $three = $this->booking();
        $this->sales();
        $this->postJson(self::BOOKINGS.'/delete', ['ids' => [$two->id]])->assertForbidden();
        $this->postJson(self::BOOKINGS.'/mark', ['ids' => [$two->id, $three->id], 'read' => true])->assertOk()->assertJsonPath('count', 2);
        $this->postJson(self::BOOKINGS.'/mark', ['ids' => [$two->id], 'read' => false])->assertOk();
        $this->assertNull($two->fresh()->read_at);
        $this->postJson(self::BOOKINGS.'/mark', ['ids' => [], 'read' => true])->assertUnprocessable();

        $this->actingAs(User::factory()->owner()->create());
        $this->deleteJson(self::BOOKINGS."/{$one->id}")->assertNoContent();
        $this->postJson(self::BOOKINGS.'/delete', ['ids' => [$two->id, $three->id]])->assertOk()->assertJsonPath('count', 2);
        $this->assertSame(0, Booking::count());
    }
}
