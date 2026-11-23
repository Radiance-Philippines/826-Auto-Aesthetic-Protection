<?php

namespace Tests\Feature;

use App\Mail\CustomerBookingUpdate;
use App\Mail\TeamBookingAlert;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\BookingPhoto;
use App\Models\Branch;
use App\Models\BranchBlock;
use App\Models\Guest;
use App\Models\MailSetting;
use App\Models\ReferralRedemption;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Support\Referrals;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The staff app's queue and unit pages: what each role sees, branch
 * scoping, and the next step, ready date, notes and photos.
 */
class StaffQueueTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private Branch $cainta;

    private Branch $sanMateo;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->cainta = Branch::factory()->cap(3)->create(['name' => 'Cainta']);
        $this->sanMateo = Branch::factory()->cap(3)->create(['name' => 'San Mateo']);
    }

    private function unit(array $state = [], ?Branch $branch = null): Booking
    {
        $booking = Booking::factory()->priced(2500)->create([
            'branch_id' => ($branch ?? $this->cainta)->id,
            'name' => 'Maria Santos',
            'phone' => '0917 123 4567',
            'vehicle_model' => 'Toyota Vios',
            'plate_number' => 'ABC1234',
            'total_days' => 3,
            'drop_off_on' => '2026-11-16',
            'ready_on' => '2026-11-18',
            ...$state,
        ]);
        $booking->items()->create(['service_name' => 'Exterior detailing', 'category_name' => 'Detailing', 'price' => 2500, 'duration_days' => 3, 'sort_order' => 0]);

        return $booking;
    }

    public function test_the_queue_has_the_websites_columns_and_cards(): void
    {
        $operations = $this->staffUser('operations', $this->cainta);
        $booked = $this->unit(['status' => 'confirmed', 'drop_off_on' => '2026-11-16']);
        $unpaid = $this->unit(['name' => 'Jun Reyes', 'status' => 'awaiting_payment', 'hold_expires_at' => now()->addHour(), 'amount_due' => 500, 'priced_at' => null, 'total_price' => null]);
        $working = $this->unit(['name' => 'Ana Cruz', 'status' => 'in_progress', 'arrived_at' => '2026-11-16 08:00', 'drop_off_on' => '2026-11-16']);
        $late = $this->unit(['name' => 'Lito Late', 'status' => 'ready', 'arrived_at' => '2026-11-12 08:00', 'drop_off_on' => '2026-11-12', 'ready_on' => '2026-11-14']);
        $this->unit(['name' => 'Gone', 'status' => 'cancelled']);
        $this->unit(['name' => 'Already Left', 'status' => 'exited', 'exited_at' => now()]);
        $this->unit(['name' => 'Other Branch', 'status' => 'confirmed'], $this->sanMateo);

        $response = $this->example('queue', 'GET /api/staff/queue?branch=<id>&date=2026-11-16', $this->getJson("/api/staff/queue?branch={$this->cainta->id}&date=2026-11-16", $this->bearer($operations)))->assertOk();

        $response->assertJsonPath('branch.name', 'Cainta')
            ->assertJsonPath('date', '2026-11-16')
            ->assertJsonPath('heading', 'Monday, Nov 16, 2026')
            ->assertJsonPath('isToday', true)
            ->assertJsonPath('dropOffs', ['cap' => 3, 'booked' => 4, 'state' => 'full'])
            ->assertJsonPath('columns.0.key', 'confirmed')
            ->assertJsonPath('columns.0.label', 'Booked')
            ->assertJsonPath('columns.1.key', 'arrived')
            ->assertJsonPath('columns.2.key', 'in_progress')
            ->assertJsonPath('columns.3.key', 'quality_check')
            ->assertJsonPath('columns.4.key', 'ready')
            ->assertJsonPath('counts', ['all' => 4, 'confirmed' => 2, 'arrived' => 0, 'in_progress' => 1, 'quality_check' => 0, 'ready' => 1])
            // The detailer doesn't see the sales counts
            ->assertJsonPath('needsSize', null)
            ->assertJsonPath('homeRequests', null);

        $cards = collect($response->json('columns'))->flatMap(fn ($column) => $column['cards'])->keyBy('id');
        $this->assertSame(4, $cards->count());
        $this->assertNull($cards->get($booked->id + 100));

        $card = $cards[$working->id];
        $this->assertSame([
            'id' => $working->id,
            'reference' => $working->reference,
            'kind' => 'onsite',
            'stage' => 'in_progress',
            'name' => 'Ana',
            'unit' => $working->vehicleLabel(),
            'vehicle' => ['id' => null, 'year' => 2021, 'model' => 'Toyota Vios', 'type' => 'sedan', 'typeLabel' => 'Sedan', 'plate' => 'ABC1234', 'size' => 'Medium'],
            'services' => ['Exterior detailing'],
            'servicesLabel' => 'Exterior detailing',
            'dropOffOn' => '2026-11-16',
            'readyOn' => '2026-11-18',
            'readyLabel' => 'Wed, Nov 18',
            'dayNumber' => 1,
            'totalDays' => 3,
            'dayLabel' => 'Day 1 of 3',
            'home' => false,
            'needsSize' => false,
            'late' => false,
            'note' => null,
            'next' => ['to' => 'quality_check', 'label' => 'Send to quality check', 'confirm' => false],
            'canAdvance' => true,
            'arrivedAt' => $working->fresh()->arrived_at->toIso8601String(),
        ], $card);

        // First name only; "Waiting for the fee" like the website; late flagged; day n of m capped at m
        $this->assertSame('Jun', $cards[$unpaid->id]['name']);
        $this->assertSame('Waiting for the fee', $cards[$unpaid->id]['note']);
        $this->assertTrue($cards[$unpaid->id]['needsSize']);
        $this->assertTrue($cards[$late->id]['late']);
        $this->assertSame('Day 3 of 3', $cards[$late->id]['dayLabel']);
        $this->assertSame(['to' => 'exited', 'label' => 'Mark as exited', 'confirm' => true], $cards[$late->id]['next']);
        $this->assertStringNotContainsString('0917', $response->getContent());
        $this->assertStringNotContainsString('Maria Santos', $response->getContent());
    }

    public function test_day_n_of_m_counts_open_days_only(): void
    {
        BranchBlock::factory()->between('2026-11-17 00:00', '2026-11-18 00:00')->create(['branch_id' => $this->cainta->id]);
        $unit = $this->unit(['status' => 'in_progress', 'arrived_at' => '2026-11-16 08:00', 'total_days' => 5]);

        $this->travelTo(CarbonImmutable::parse('2026-11-18 09:00', 'Asia/Manila'));

        // Mon open, Tue closed, Wed open: day 2
        $card = collect($this->getJson("/api/staff/queue?branch={$this->cainta->id}", $this->bearer($this->staffUser('operations', $this->cainta)))->json('columns.2.cards'))->firstWhere('id', $unit->id);

        $this->assertSame('Day 2 of 5', $card['dayLabel']);
    }

    public function test_the_default_branch_is_the_users_first_and_a_branch_without_one_gets_an_empty_board(): void
    {
        $user = $this->staffUser('operations', $this->sanMateo);
        $this->unit(['status' => 'in_progress'], $this->sanMateo);

        $this->getJson('/api/staff/queue', $this->bearer($user))->assertOk()->assertJsonPath('branch.name', 'San Mateo')->assertJsonPath('counts.all', 1);

        $nobody = User::factory()->operations()->create();
        $this->getJson('/api/staff/queue', $this->bearer($nobody))->assertOk()->assertJsonPath('branch', null)->assertJsonPath('columns', []);
    }

    public function test_one_branch_cannot_read_another_s_queue_or_units(): void
    {
        $manager = $this->staffUser('branch_manager', $this->cainta);
        $theirs = $this->unit(['status' => 'confirmed'], $this->sanMateo);
        $headers = $this->bearer($manager);

        $this->getJson("/api/staff/queue?branch={$this->sanMateo->id}", $headers)->assertNotFound();
        $this->getJson("/api/staff/bookings/{$theirs->id}", $headers)->assertNotFound();
        $this->postJson("/api/staff/bookings/{$theirs->id}/stage", ['to' => 'arrived'], $headers)->assertNotFound();
        $this->postJson("/api/staff/bookings/{$theirs->id}/ready-date", ['date' => '2026-11-20'], $headers)->assertNotFound();
        $this->postJson("/api/staff/bookings/{$theirs->id}/notes", ['body' => 'hi'], $headers)->assertNotFound();
        $this->postJson("/api/staff/bookings/{$theirs->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg')], $headers)->assertNotFound();
        $this->getJson("/api/staff/bookings/{$theirs->id}/proof", $headers)->assertNotFound();

        $this->assertSame('confirmed', $theirs->fresh()->status);

        // A super admin sees every branch
        $owner = User::factory()->owner()->create();
        $this->getJson("/api/staff/bookings/{$theirs->id}", $this->bearer($owner))->assertOk();
        $this->getJson("/api/staff/queue?branch={$this->sanMateo->id}", $this->bearer($owner))->assertOk()->assertJsonPath('branch.name', 'San Mateo');
    }

    public function test_a_role_without_the_area_is_a_403(): void
    {
        $unit = $this->unit(['status' => 'confirmed']);
        $nothing = $this->staffUser('operations', $this->cainta, ['permissions' => ['customers.view']]);

        $this->getJson('/api/staff/queue', $this->bearer($nothing))->assertForbidden();
        $this->getJson("/api/staff/bookings/{$unit->id}", $this->bearer($nothing))->assertForbidden();
        $this->postJson("/api/staff/bookings/{$unit->id}/stage", ['to' => 'arrived'], $this->bearer($nothing))->assertForbidden();
    }

    public function test_sales_admin_reads_the_queue_but_cannot_change_a_stage(): void
    {
        $sales = $this->staffUser('sales_admin', $this->cainta);
        $unit = $this->unit(['status' => 'confirmed']);
        $headers = $this->bearer($sales);

        $this->getJson("/api/staff/queue?branch={$this->cainta->id}", $headers)
            ->assertOk()
            ->assertJsonPath('columns.0.cards.0.canAdvance', false)
            ->assertJsonPath('needsSize', 0);
        $this->postJson("/api/staff/bookings/{$unit->id}/stage", ['to' => 'arrived'], $headers)->assertForbidden();
        $this->postJson("/api/staff/bookings/{$unit->id}/ready-date", ['date' => '2026-11-20'], $headers)->assertForbidden();
        $this->postJson("/api/staff/bookings/{$unit->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg')], $headers)->assertForbidden();

        $this->assertSame('confirmed', $unit->fresh()->status);
        // Sales may add a note (BookingPolicy::update)
        $this->postJson("/api/staff/bookings/{$unit->id}/notes", ['body' => 'Customer called'], $headers)->assertCreated();
    }

    public function test_the_unit_detail_for_the_detailer_has_no_money(): void
    {
        $operations = $this->staffUser('operations', $this->cainta);
        $unit = $this->unit(['status' => 'in_progress', 'arrived_at' => '2026-11-16 08:00', 'confirmed_at' => '2026-11-14 10:00', 'message' => 'Please check the left mirror', 'amount_due' => 500, 'paid_at' => now(), 'discount_amount' => 100]);
        $unit->events()->create(['kind' => 'size', 'from' => null, 'to' => 'Medium', 'note' => 'Price ₱2,500']);
        $unit->events()->create(['kind' => 'paid', 'note' => 'GCash ref 123']);
        $unit->events()->create(['kind' => 'payment', 'from' => null, 'to' => '₱500']);
        $unit->events()->create(['kind' => 'discount', 'to' => '₱100']);

        $response = $this->example('booking-detail-detailer', 'GET /api/staff/bookings/{id} (operations: no money)', $this->getJson("/api/staff/bookings/{$unit->id}", $this->bearer($operations)))->assertOk();

        $response->assertJsonPath('money', null)
            ->assertJsonPath('statusLabel', 'In progress')
            ->assertJsonPath('can.advance', true)
            ->assertJsonPath('can.changeReadyDate', true)
            ->assertJsonPath('can.addPhoto', true)
            ->assertJsonPath('can.setSize', false)
            ->assertJsonPath('customerMessage', 'Please check the left mirror')
            ->assertJsonPath('timeline.0', ['key' => 'confirmed', 'label' => 'Booked', 'at' => $unit->fresh()->confirmed_at->toIso8601String(), 'done' => true, 'current' => false])
            ->assertJsonPath('timeline.2.key', 'in_progress')
            ->assertJsonPath('timeline.2.current', true)
            ->assertJsonPath('timeline.1.at', $unit->fresh()->arrived_at->toIso8601String());

        // customers.view is one of the detailer's default areas, so contact shows (as on the website)
        $response->assertJsonPath('customer.phone', '0917 123 4567');

        $text = $response->getContent();
        foreach (['2,500', '2500', 'GCash ref', '"price"', '₱500', '₱100'] as $money) {
            $this->assertStringNotContainsString($money, $text, "money leaked: {$money}");
        }
        $this->assertSame(['size'], collect($response->json('history'))->pluck('kind')->all());
        $this->assertNull($response->json('history.0.note'));
    }

    public function test_the_detailer_without_customers_view_gets_no_contact_details(): void
    {
        $restricted = $this->staffUser('operations', $this->cainta, ['permissions' => ['bookings.view', 'queue.update']]);
        $unit = $this->unit(['status' => 'confirmed', 'email' => 'maria@example.com', 'guest_id' => Guest::factory()->create()->id]);

        $response = $this->getJson("/api/staff/bookings/{$unit->id}", $this->bearer($restricted))->assertOk();

        $response->assertJsonPath('customer', ['firstName' => 'Maria', 'name' => null, 'phone' => null, 'email' => null, 'guestId' => null, 'address' => null, 'area' => null]);
        $this->assertStringNotContainsString('maria@example.com', $response->getContent());
        $this->assertStringNotContainsString('0917', $response->getContent());
    }

    public function test_sales_sees_money_and_contact_on_the_unit(): void
    {
        $sales = $this->staffUser('sales_admin', $this->cainta);
        $unit = $this->unit(['status' => 'confirmed', 'amount_due' => 500, 'paid_at' => now(), 'discount_amount' => 100, 'email' => 'maria@example.com']);
        $unit->events()->create(['kind' => 'paid', 'note' => 'GCash ref 123']);

        $response = $this->example('booking-detail-sales', 'GET /api/staff/bookings/{id} (sales_admin/branch_manager: money and contact)', $this->getJson("/api/staff/bookings/{$unit->id}", $this->bearer($sales)))->assertOk();

        $response->assertJsonPath('customer.name', 'Maria Santos')
            ->assertJsonPath('customer.phone', '0917 123 4567')
            ->assertJsonPath('customer.email', 'maria@example.com')
            ->assertJsonPath('money.priced', true)
            ->assertJsonPath('money.total', 2500)
            ->assertJsonPath('money.amountDue', 500)
            ->assertJsonPath('money.items', [['name' => 'Exterior detailing', 'price' => 2500]])
            ->assertJsonPath('can.setSize', true)
            ->assertJsonPath('can.advance', false);
        $this->assertSame('paid', $response->json('history.0.kind'));
    }

    public function test_the_next_step_moves_the_unit_like_the_admin_queue(): void
    {
        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);
        $operations = $this->staffUser('operations', $this->cainta);
        $headers = $this->bearer($operations);
        $unit = $this->unit(['status' => 'confirmed', 'email' => 'maria@example.com']);

        $response = $this->example('booking-stage', 'POST /api/staff/bookings/{id}/stage {to}', $this->postJson("/api/staff/bookings/{$unit->id}/stage", ['to' => 'arrived'], $headers))->assertOk();
        $response->assertJsonPath('status', 'arrived')->assertJsonPath('next.to', 'in_progress');

        $unit->refresh();
        $this->assertNotNull($unit->arrived_at);
        $event = BookingEvent::where('booking_id', $unit->id)->where('kind', 'status')->sole();
        // The history says who moved it
        $this->assertSame([$operations->id, 'Booked', 'Arrived'], [$event->user_id, $event->from, $event->to]);
        Mail::assertSent(CustomerBookingUpdate::class, fn ($mail) => $mail->hasTo('maria@example.com'));

        foreach (['in_progress', 'quality_check', 'ready'] as $step) {
            $this->postJson("/api/staff/bookings/{$unit->id}/stage", ['to' => $step], $headers)->assertOk()->assertJsonPath('status', $step);
        }

        $this->assertNotNull($unit->fresh()->ready_at);
        Mail::assertSent(CustomerBookingUpdate::class, 2);

        $this->postJson("/api/staff/bookings/{$unit->id}/stage", ['to' => 'exited'], $headers)->assertOk()->assertJsonPath('status', 'exited')->assertJsonPath('next', null);
        $this->assertNotNull($unit->fresh()->exited_at);
        $this->assertSame(5, BookingEvent::where('booking_id', $unit->id)->where('kind', 'status')->count());
    }

    public function test_only_the_next_step_is_allowed_with_a_plain_message(): void
    {
        $operations = $this->staffUser('operations', $this->cainta);
        $headers = $this->bearer($operations);
        $unit = $this->unit(['status' => 'confirmed']);

        $skip = $this->postJson("/api/staff/bookings/{$unit->id}/stage", ['to' => 'ready'], $headers)->assertUnprocessable();
        $this->example('booking-stage-refused', 'POST /api/staff/bookings/{id}/stage (a skipped step)', $skip);
        $skip->assertJsonPath('message', 'This unit is “Booked”. Its next step is “Arrived”.');

        $this->postJson("/api/staff/bookings/{$unit->id}/stage", ['to' => 'nonsense'], $headers)->assertUnprocessable();

        $done = $this->unit(['status' => 'exited']);
        $this->postJson("/api/staff/bookings/{$done->id}/stage", ['to' => 'arrived'], $headers)->assertUnprocessable()->assertJsonPath('message', 'This unit can’t move on from “Exited”.');
        $waiting = $this->unit(['status' => 'payment_review']);
        $this->postJson("/api/staff/bookings/{$waiting->id}/stage", ['to' => 'arrived'], $headers)->assertUnprocessable();

        $this->assertSame('confirmed', $unit->fresh()->status);
        // Moving twice to the same step: the second is refused
        $this->postJson("/api/staff/bookings/{$unit->id}/stage", ['to' => 'arrived'], $headers)->assertOk();
        $this->postJson("/api/staff/bookings/{$unit->id}/stage", ['to' => 'arrived'], $headers)->assertUnprocessable();
    }

    public function test_a_home_visit_has_its_own_steps(): void
    {
        $operations = $this->staffUser('operations', $this->cainta);
        $headers = $this->bearer($operations);
        $home = $this->unit(['kind' => 'home', 'status' => 'confirmed', 'address' => '12 Rizal St', 'area' => 'Cainta']);

        $this->getJson("/api/staff/bookings/{$home->id}", $headers)->assertOk()->assertJsonPath('next.label', 'Start work')->assertJsonPath('timeline.1.key', 'in_progress');
        $this->postJson("/api/staff/bookings/{$home->id}/stage", ['to' => 'arrived'], $headers)->assertUnprocessable();
        $this->postJson("/api/staff/bookings/{$home->id}/stage", ['to' => 'in_progress'], $headers)->assertOk();
        $this->postJson("/api/staff/bookings/{$home->id}/stage", ['to' => 'exited'], $headers)->assertOk()->assertJsonPath('timeline.2.label', 'Done');
    }

    public function test_exiting_awards_the_referral_points_once(): void
    {
        ReferralSetting::instance()->update(['enabled' => true, 'discount_type' => 'fixed', 'discount_value' => 500, 'owner_points' => 100]);
        $owner = Guest::factory()->create(['referral_code' => 'MARIA-7K3Q']);
        $friend = Guest::factory()->create();
        $unit = $this->unit(['status' => 'ready', 'guest_id' => $friend->id]);
        Referrals::redeem($unit, $owner);

        $this->postJson("/api/staff/bookings/{$unit->id}/stage", ['to' => 'exited'], $this->bearer($this->staffUser('operations', $this->cainta)))->assertOk();

        $this->assertSame(100, ReferralRedemption::sole()->points_awarded);
    }

    public function test_changing_the_ready_date_tells_sales_like_the_website(): void
    {
        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);
        $this->cainta->update(['alert_email' => 'cainta@826auto.test']);
        $operations = $this->staffUser('operations', $this->cainta);
        $sales = $this->staffUser('sales_admin', $this->cainta);
        $unit = $this->unit(['status' => 'in_progress', 'drop_off_on' => '2026-11-16', 'ready_on' => '2026-11-18']);

        $response = $this->example('booking-ready-date', 'POST /api/staff/bookings/{id}/ready-date {date}', $this->postJson("/api/staff/bookings/{$unit->id}/ready-date", ['date' => '2026-11-24'], $this->bearer($operations)))->assertOk();
        $response->assertJsonPath('readyOn', '2026-11-24')->assertJsonPath('readyLabel', 'Tue, Nov 24');

        $this->assertSame('2026-11-24', $unit->fresh()->ready_on->toDateString());
        $this->assertSame(1, $sales->notifications()->count());
        $this->assertStringContainsString('Wed, Nov 18 → Tue, Nov 24', $sales->notifications()->first()->data['body']);
        Mail::assertSent(TeamBookingAlert::class, fn (TeamBookingAlert $mail) => $mail->kind === 'heads_up');
        Mail::assertNotSent(CustomerBookingUpdate::class);
        $this->assertSame('ready_on', BookingEvent::where('booking_id', $unit->id)->latest('id')->first()->kind);
        $this->assertSame($operations->id, BookingEvent::where('booking_id', $unit->id)->latest('id')->first()->user_id);
    }

    public function test_the_ready_date_is_checked(): void
    {
        $headers = $this->bearer($this->staffUser('operations', $this->cainta));
        $unit = $this->unit(['status' => 'in_progress', 'drop_off_on' => '2026-11-16']);

        $this->postJson("/api/staff/bookings/{$unit->id}/ready-date", ['date' => '2026-11-15'], $headers)->assertUnprocessable()->assertJsonPath('errors.date.0', 'The ready date can’t be before the drop-off day.');
        $this->postJson("/api/staff/bookings/{$unit->id}/ready-date", ['date' => 'tomorrow'], $headers)->assertUnprocessable();
        $this->postJson("/api/staff/bookings/{$unit->id}/ready-date", [], $headers)->assertUnprocessable();

        $done = $this->unit(['status' => 'exited']);
        $this->postJson("/api/staff/bookings/{$done->id}/ready-date", ['date' => '2026-11-30'], $headers)->assertUnprocessable()->assertJsonPath('message', 'The ready date can’t be changed for this unit.');
    }

    public function test_a_note_goes_into_the_history(): void
    {
        $operations = $this->staffUser('operations', $this->cainta);
        $unit = $this->unit(['status' => 'in_progress']);

        $response = $this->example('booking-note', 'POST /api/staff/bookings/{id}/notes {body}', $this->postJson("/api/staff/bookings/{$unit->id}/notes", ['body' => '  Scratch on the rear bumper, already there  '], $this->bearer($operations)))->assertCreated();

        $response->assertJsonPath('history.0.kind', 'note')
            ->assertJsonPath('history.0.note', 'Scratch on the rear bumper, already there')
            ->assertJsonPath('history.0.by', $operations->name);
        $this->assertStringContainsString("{$operations->name}: Note (Scratch on the rear bumper, already there)", $response->json('history.0.line'));

        // The website's history shows it too
        $this->assertSame('note', BookingEvent::where('booking_id', $unit->id)->latest('id')->value('kind'));
    }

    public function test_a_note_up_to_500_characters_is_kept_whole_and_longer_is_refused(): void
    {
        $headers = $this->bearer($this->staffUser('operations', $this->cainta));
        $unit = $this->unit(['status' => 'in_progress']);

        $this->postJson("/api/staff/bookings/{$unit->id}/notes", ['body' => str_repeat('a', 500)], $headers)->assertCreated();
        $this->assertSame(500, strlen(BookingEvent::where('kind', 'note')->sole()->note));

        $this->postJson("/api/staff/bookings/{$unit->id}/notes", ['body' => str_repeat('a', 501)], $headers)->assertUnprocessable()->assertJsonPath('errors.body.0', 'Keep the note under 500 characters.');
        $this->postJson("/api/staff/bookings/{$unit->id}/notes", ['body' => '   '], $headers)->assertUnprocessable();
    }

    public function test_a_photo_is_stored_through_the_image_pipeline_and_listed(): void
    {
        Storage::fake('public');
        $operations = $this->staffUser('operations', $this->cainta);
        $unit = $this->unit(['status' => 'in_progress']);

        $response = $this->example('booking-photo', 'POST /api/staff/bookings/{id}/photos (multipart: photo)', $this->post("/api/staff/bookings/{$unit->id}/photos", ['photo' => UploadedFile::fake()->image('front.jpg', 1200, 800)], $this->bearer($operations) + ['Accept' => 'application/json']))->assertCreated();

        $photo = BookingPhoto::sole();
        $this->assertSame([$unit->id, $operations->id], [$photo->booking_id, $photo->user_id]);
        $this->assertStringStartsWith("booking-photos/{$unit->id}/", $photo->path);
        $this->assertStringEndsWith('.webp', $photo->path);
        Storage::disk('public')->assertExists($photo->path);
        Storage::disk('public')->assertExists(str_replace('.webp', '-thumb.webp', $photo->path));
        // Only the clean master and renditions are kept, not the upload
        $this->assertSame([], collect(Storage::disk('public')->files("booking-photos/{$unit->id}"))->reject(fn ($file) => str_ends_with($file, '.webp') || str_ends_with($file, '-og.jpg'))->all());

        $response->assertJsonPath('photos.0.id', $photo->id)->assertJsonPath('photos.0.by', $operations->name);
        $this->assertStringStartsWith('http', $response->json('photos.0.url'));
        $this->assertStringStartsWith('http', $response->json('photos.0.thumb'));

        // It is in the detail for the branch, and a super admin
        $this->getJson("/api/staff/bookings/{$unit->id}", $this->bearer($operations))->assertJsonCount(1, 'photos');
    }

    public function test_photos_are_checked_and_go_with_the_booking(): void
    {
        Storage::fake('public');
        $headers = $this->bearer($this->staffUser('operations', $this->cainta)) + ['Accept' => 'application/json'];
        $unit = $this->unit(['status' => 'in_progress']);

        $this->post("/api/staff/bookings/{$unit->id}/photos", [], $headers)->assertUnprocessable();
        $this->post("/api/staff/bookings/{$unit->id}/photos", ['photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], $headers)->assertUnprocessable()->assertJsonPath('errors.photo.0', 'Use a JPG, PNG or WebP picture.');
        $this->post("/api/staff/bookings/{$unit->id}/photos", ['photo' => UploadedFile::fake()->image('huge.jpg')->size(9000)], $headers)->assertUnprocessable()->assertJsonPath('errors.photo.0', 'That picture is too big. Use one under 8 MB.');
        $this->assertSame(0, BookingPhoto::count());

        $this->post("/api/staff/bookings/{$unit->id}/photos", ['photo' => UploadedFile::fake()->image('ok.png', 400, 300)], $headers)->assertCreated();
        $path = BookingPhoto::sole()->path;
        Storage::disk('public')->assertExists($path);

        $unit->delete();

        $this->assertSame(0, BookingPhoto::count());
        Storage::disk('public')->assertMissing($path);
    }
}
