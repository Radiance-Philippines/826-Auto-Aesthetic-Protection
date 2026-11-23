<?php

namespace Tests\Feature;

use App\Mail\CustomerBookingUpdate;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\Branch;
use App\Models\MailSetting;
use App\Models\PaymentSetting;
use App\Models\Vehicle;
use App\Models\VehicleSize;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsShop;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The staff app's Today tab and the sales actions: size, payment check.
 */
class StaffSalesTest extends TestCase
{
    use BuildsShop, RefreshDatabase, StaffApi;

    private Branch $sanMateo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->buildShop();
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);
    }

    private function booking(array $state = [], ?Branch $branch = null): Booking
    {
        $booking = Booking::factory()->create([
            'branch_id' => ($branch ?? $this->branch)->id,
            'name' => 'Maria Santos',
            'drop_off_on' => '2026-11-16',
            'ready_on' => '2026-11-17',
            ...$state,
        ]);
        $booking->items()->create(['service_id' => $this->detail->id, 'service_name' => 'Exterior detailing', 'category_name' => 'Detailing', 'price' => null, 'duration_days' => 1, 'sort_order' => 0]);

        return $booking;
    }

    public function test_today_lists_the_four_things_for_sales(): void
    {
        $sales = $this->staffUser('sales_admin', $this->branch);
        $dropOff = $this->booking(['status' => 'confirmed']);
        $tomorrow = $this->booking(['status' => 'confirmed', 'drop_off_on' => '2026-11-17']);
        $review = $this->booking(['status' => 'payment_review', 'drop_off_on' => '2026-11-18', 'payment_method' => 'gcash', 'amount_due' => 500, 'payment_proof' => 'payment-proofs/1/x.webp', 'payment_submitted_at' => now()]);
        $home = $this->booking(['kind' => 'home', 'status' => 'requested', 'drop_off_on' => null, 'ready_on' => null, 'preferred_date' => '2026-11-20', 'preferred_window' => 'morning', 'address' => '12 Rizal St', 'area' => 'Cainta']);
        $other = $this->booking(['status' => 'payment_review', 'name' => 'Far Away'], $this->sanMateo);

        $response = $this->example('today', 'GET /api/staff/today?branch=<id>', $this->getJson("/api/staff/today?branch={$this->branch->id}", $this->bearer($sales)))->assertOk();

        $response->assertJsonPath('branch.name', 'Cainta')
            ->assertJsonPath('date', '2026-11-16')
            ->assertJsonCount(1, 'dropOffsToday')
            ->assertJsonPath('dropOffsToday.0.id', $dropOff->id)
            ->assertJsonPath('paymentsToCheck.0.id', $review->id)
            ->assertJsonPath('paymentsToCheck.0.payment.method', 'gcash')
            ->assertJsonPath('paymentsToCheck.0.payment.amountDue', 500)
            ->assertJsonPath('paymentsToCheck.0.payment.proofUrl', url("/api/staff/bookings/{$review->id}/proof"))
            ->assertJsonPath('homeRequests.0.id', $home->id)
            ->assertJsonPath('homeRequests.0.address', '12 Rizal St')
            ->assertJsonPath('homeRequests.0.preferredWindow', 'morning');

        // Unpriced open units, from this branch only
        $sizeIds = collect($response->json('needsSizeReview'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$dropOff->id, $tomorrow->id, $review->id, $home->id], $sizeIds);
        $this->assertNotContains($other->id, $sizeIds);
        $this->assertStringNotContainsString('Far Away', $response->getContent());
    }

    public function test_today_without_a_branch_covers_all_the_users_branches_and_no_more(): void
    {
        $manager = $this->staffUser('branch_manager', $this->branch);
        $manager->branches()->attach($this->sanMateo);
        $third = Branch::factory()->create();
        $mine = $this->booking(['status' => 'confirmed']);
        $second = $this->booking(['status' => 'confirmed'], $this->sanMateo);
        $hidden = $this->booking(['status' => 'confirmed'], $third);

        $ids = collect($this->getJson('/api/staff/today', $this->bearer($manager))->assertOk()->json('dropOffsToday'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$mine->id, $second->id], $ids);
        $this->assertNotContains($hidden->id, $ids);
        $this->getJson("/api/staff/today?branch={$third->id}", $this->bearer($manager))->assertNotFound();
    }

    public function test_the_detailer_and_other_roles_without_sales_are_refused(): void
    {
        $booking = $this->booking(['status' => 'payment_review', 'payment_proof' => 'payment-proofs/1/x.webp']);
        $operations = $this->bearer($this->staffUser('operations', $this->branch));
        $size = VehicleSize::factory()->create();

        $this->getJson('/api/staff/today', $operations)->assertForbidden();
        $this->getJson('/api/staff/vehicle-sizes', $operations)->assertForbidden();
        $this->getJson("/api/staff/bookings/{$booking->id}/proof", $operations)->assertForbidden();
        $this->postJson("/api/staff/bookings/{$booking->id}/size", ['vehicle_size_id' => $size->id], $operations)->assertForbidden();
        $this->postJson("/api/staff/bookings/{$booking->id}/payment/approve", [], $operations)->assertForbidden();
        $this->postJson("/api/staff/bookings/{$booking->id}/payment/reject", ['reason' => 'x'], $operations)->assertForbidden();
        $this->assertSame('payment_review', $booking->fresh()->status);
    }

    public function test_the_size_classes_list(): void
    {
        VehicleSize::factory()->create(['name' => 'Large', 'sort_order' => 3, 'examples' => 'Fortuner']);
        VehicleSize::factory()->create(['name' => 'Hidden', 'sort_order' => 4, 'is_active' => false]);

        $response = $this->example('vehicle-sizes', 'GET /api/staff/vehicle-sizes', $this->getJson('/api/staff/vehicle-sizes', $this->bearer($this->staffUser('sales_admin', $this->branch))))->assertOk();

        $this->assertSame(['Medium', 'Large'], collect($response->json())->pluck('name')->all());
        $this->assertSame(['id', 'name', 'examples'], array_keys($response->json('1')));
    }

    public function test_setting_the_size_prices_the_unit_like_the_admin_and_tells_the_customer(): void
    {
        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);
        $sales = $this->staffUser('sales_admin', $this->branch);
        $unit = Vehicle::factory()->create();
        $booking = $this->booking(['status' => 'confirmed', 'vehicle_id' => $unit->id, 'email' => 'maria@example.com']);

        $response = $this->example('booking-size', 'POST /api/staff/bookings/{id}/size {vehicle_size_id}', $this->postJson("/api/staff/bookings/{$booking->id}/size", ['vehicle_size_id' => $this->medium->id], $this->bearer($sales)))->assertOk();

        $response->assertJsonPath('money.total', 2500)->assertJsonPath('money.priced', true)->assertJsonPath('needsSize', false)->assertJsonPath('vehicle.size', 'Medium')->assertJsonPath('missingPrices', []);
        $this->assertSame('Medium', $booking->fresh()->vehicle_size_name);
        $this->assertSame($this->medium->id, $unit->fresh()->vehicle_size_id);
        Mail::assertSent(CustomerBookingUpdate::class, fn ($mail) => $mail->kind === 'priced' && $mail->hasTo('maria@example.com'));
        $this->assertSame($sales->id, BookingEvent::where('booking_id', $booking->id)->where('kind', 'size')->sole()->user_id);
    }

    public function test_a_size_with_no_price_for_a_service_says_which_and_stays_in_the_review_list(): void
    {
        Mail::fake();
        $large = VehicleSize::factory()->create(['name' => 'Large']);
        $booking = $this->booking(['status' => 'confirmed']);

        $response = $this->postJson("/api/staff/bookings/{$booking->id}/size", ['vehicle_size_id' => $large->id], $this->bearer($this->staffUser('sales_admin', $this->branch)))->assertOk();

        $response->assertJsonPath('missingPrices', ['Exterior detailing'])->assertJsonPath('needsSize', true)->assertJsonPath('money.priced', false);
        Mail::assertNotSent(CustomerBookingUpdate::class);
    }

    public function test_the_size_is_checked_and_scoped(): void
    {
        $headers = $this->bearer($this->staffUser('sales_admin', $this->branch));
        $booking = $this->booking(['status' => 'confirmed']);
        $finished = $this->booking(['status' => 'exited']);
        $far = $this->booking(['status' => 'confirmed'], $this->sanMateo);
        $inactive = VehicleSize::factory()->create(['is_active' => false]);

        $this->postJson("/api/staff/bookings/{$booking->id}/size", [], $headers)->assertUnprocessable();
        $this->postJson("/api/staff/bookings/{$booking->id}/size", ['vehicle_size_id' => $inactive->id], $headers)->assertUnprocessable()->assertJsonPath('message', 'Pick one of the size classes.');
        $this->postJson("/api/staff/bookings/{$finished->id}/size", ['vehicle_size_id' => $this->medium->id], $headers)->assertUnprocessable();
        $this->postJson("/api/staff/bookings/{$far->id}/size", ['vehicle_size_id' => $this->medium->id], $headers)->assertNotFound();
        $this->assertNull($far->fresh()->vehicle_size_name);
    }

    public function test_approving_a_payment_confirms_the_booking_and_tells_the_customer(): void
    {
        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);
        $booking = $this->booking(['status' => 'payment_review', 'email' => 'maria@example.com', 'amount_due' => 500, 'hold_expires_at' => now()->addHour()]);

        $response = $this->example('payment-approve', 'POST /api/staff/bookings/{id}/payment/approve {note?}', $this->postJson("/api/staff/bookings/{$booking->id}/payment/approve", ['note' => 'GCash ref 99'], $this->bearer($this->staffUser('sales_admin', $this->branch))))->assertOk();

        $response->assertJsonPath('status', 'confirmed')->assertJsonPath('money.paidAt', fn ($at) => $at !== null);
        $booking->refresh();
        $this->assertSame(['confirmed', 'GCash ref 99', null], [$booking->status, $booking->payment_note, $booking->hold_expires_at]);
        Mail::assertSent(CustomerBookingUpdate::class, fn ($mail) => $mail->kind === 'paid');

        // A confirmed booking has nothing left to approve
        $this->postJson("/api/staff/bookings/{$booking->id}/payment/approve", [], $this->bearer($this->staffUser('sales_admin', $this->branch)))->assertUnprocessable();
    }

    public function test_rejecting_a_payment_deletes_the_screenshot_and_gives_the_customer_a_fresh_hold(): void
    {
        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);
        PaymentSetting::instance()->update(['hold_minutes' => 30]);
        Storage::fake(Booking::PROOF_DISK);
        Storage::disk(Booking::PROOF_DISK)->put('payment-proofs/1/x.webp', 'png-bytes');
        $booking = $this->booking(['status' => 'payment_review', 'email' => 'maria@example.com', 'payment_proof' => 'payment-proofs/1/x.webp', 'payment_submitted_at' => now()]);
        $headers = $this->bearer($this->staffUser('sales_admin', $this->branch));

        $this->getJson("/api/staff/bookings/{$booking->id}/proof", $headers)->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson("/api/staff/bookings/{$booking->id}/payment/reject", [], $headers)->assertUnprocessable();

        $response = $this->example('payment-reject', 'POST /api/staff/bookings/{id}/payment/reject {reason}', $this->postJson("/api/staff/bookings/{$booking->id}/payment/reject", ['reason' => 'The amount doesn’t match'], $headers))->assertOk();

        $response->assertJsonPath('status', 'awaiting_payment')->assertJsonPath('money.rejectedReason', 'The amount doesn’t match')->assertJsonPath('money.proofUrl', null);
        $booking->refresh();
        $this->assertNull($booking->payment_proof);
        $this->assertNotNull($booking->hold_expires_at);
        Storage::disk(Booking::PROOF_DISK)->assertMissing('payment-proofs/1/x.webp');
        Mail::assertSent(CustomerBookingUpdate::class, fn ($mail) => $mail->kind === 'rejected');
        $this->getJson("/api/staff/bookings/{$booking->id}/proof", $headers)->assertNotFound();
    }

    public function test_one_branch_s_sales_cannot_touch_another_branch_s_payment(): void
    {
        $far = $this->booking(['status' => 'payment_review', 'payment_proof' => 'payment-proofs/1/x.webp'], $this->sanMateo);
        $headers = $this->bearer($this->staffUser('sales_admin', $this->branch));

        $this->postJson("/api/staff/bookings/{$far->id}/payment/approve", [], $headers)->assertNotFound();
        $this->postJson("/api/staff/bookings/{$far->id}/payment/reject", ['reason' => 'no'], $headers)->assertNotFound();
        $this->getJson("/api/staff/bookings/{$far->id}/proof", $headers)->assertNotFound();
        $this->assertSame('payment_review', $far->fresh()->status);
    }
}
