<?php

namespace Tests\Feature;

use App\Filament\Pages\BookingSettings;
use App\Filament\Pages\PaymentSettings;
use App\Filament\Pages\SiteSettings;
use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\BranchBlocks\Pages\ManageBranchBlocks;
use App\Filament\Resources\Branches\Pages\CreateBranch;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Mail\CustomerBookingUpdate;
use App\Models\Booking;
use App\Models\BookingSetting;
use App\Models\Branch;
use App\Models\BranchBlock;
use App\Models\MailSetting;
use App\Models\PaymentSetting;
use App\Models\Photo;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleSize;
use App\Support\Reservations;
use App\Support\Schedule\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsShop;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->buildShop();
        $this->owner = User::factory()->owner()->create();
    }

    public function test_visitors_are_sent_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get($this->inPanel('owner'))->assertRedirect('/owner/login');
        $this->get('/admin/login')->assertOk();
    }

    public function test_admin_pages_render(): void
    {
        $booking = Booking::factory()->create(['branch_id' => $this->branch->id]);
        $home = Booking::factory()->home()->create(['branch_id' => $this->branch->id]);
        $photo = Photo::factory()->create();
        BranchBlock::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->owner);

        foreach ([
            $this->panel(),
            $this->panel('/queue'),
            $this->panel("/queue?branch={$this->branch->id}&date=2026-11-17"),
            $this->panel('/bookings'),
            $this->panel('/bookings?activeTab=action'),
            $this->panel('/bookings?activeTab=size'),
            $this->panel('/bookings/create'),
            $this->panel("/bookings/{$booking->id}"),
            $this->panel("/bookings/{$booking->id}/edit"),
            $this->panel("/bookings/{$home->id}"),
            $this->panel('/branch-blocks'),
            $this->panel('/branches'),
            $this->panel('/branches/create'),
            $this->panel("/branches/{$this->branch->id}/edit"),
            $this->panel('/services'),
            $this->panel('/services/create'),
            $this->panel("/services/{$this->detail->id}/edit"),
            $this->panel('/service-categories'),
            $this->panel('/vehicle-sizes'),
            $this->panel('/photos'),
            $this->panel("/photos/{$photo->id}/edit"),
            $this->panel('/site-settings'),
            $this->panel('/booking-rules'),
            $this->panel('/payment-settings'),
            $this->panel('/email-settings'),
            $this->panel('/google-sign-in'),
            $this->panel('/users'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_opening_a_booking_marks_it_read(): void
    {
        $booking = Booking::factory()->create(['branch_id' => $this->branch->id]);
        $this->assertNull($booking->read_at);

        $this->actingAs($this->owner)->get($this->panel("/bookings/{$booking->id}"))->assertOk();

        $this->assertNotNull($booking->refresh()->read_at);
    }

    public function test_a_screenshot_is_checked_and_the_booking_confirmed(): void
    {
        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);
        $booking = Booking::factory()->create(['branch_id' => $this->branch->id, 'status' => 'payment_review', 'amount_due' => 500, 'hold_expires_at' => now()->addMinutes(5)]);

        $this->actingAs($this->owner);

        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction('markPaid', data: ['payment_note' => 'GCash 1234'])
            ->assertHasNoActionErrors();

        $booking->refresh();
        $this->assertSame(['confirmed', 'GCash 1234'], [$booking->status, $booking->payment_note]);
        $this->assertNotNull($booking->paid_at);
        $this->assertNull($booking->hold_expires_at);
        Mail::assertSent(CustomerBookingUpdate::class, fn (CustomerBookingUpdate $mail) => $mail->kind === 'paid');
    }

    public function test_a_rejected_screenshot_gives_a_fresh_hold(): void
    {
        PaymentSetting::instance()->update(['hold_minutes' => 40]);
        $booking = Booking::factory()->create(['branch_id' => $this->branch->id, 'status' => 'payment_review', 'amount_due' => 500, 'payment_proof' => 'payment-proofs/1/x.webp']);

        $this->actingAs($this->owner);

        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction('rejectProof', data: ['reason' => 'The amount doesn’t match'])
            ->assertHasNoActionErrors();

        $booking->refresh();
        $this->assertSame(['awaiting_payment', 'The amount doesn’t match', null], [$booking->status, $booking->payment_rejected_reason, $booking->payment_proof]);
        $this->assertSame('07:40', $booking->hold_expires_at->format('H:i'));
    }

    public function test_a_unit_moves_through_the_queue_with_its_arrival_and_exit_times_recorded(): void
    {
        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);
        $booking = Booking::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->owner);

        $page = Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()]);
        $this->assertNull($booking->arrived_at);

        $page->callAction('markArrived')->assertHasNoActionErrors();
        $booking->refresh();
        $this->assertSame('arrived', $booking->status);
        $this->assertTrue($booking->arrived_at->equalTo(now()));
        $this->assertNull($booking->exited_at);
        Mail::assertSent(CustomerBookingUpdate::class, fn (CustomerBookingUpdate $mail) => $mail->kind === 'arrived');

        $page->callAction('startWork')->assertHasNoActionErrors();
        $this->assertSame('in_progress', $booking->refresh()->status);

        $page->callAction('qualityCheck')->assertHasNoActionErrors();
        $this->assertSame('quality_check', $booking->refresh()->status);

        $page->callAction('markReady')->assertHasNoActionErrors();
        $this->assertSame('ready', $booking->refresh()->status);
        Mail::assertSent(CustomerBookingUpdate::class, fn (CustomerBookingUpdate $mail) => $mail->kind === 'ready');

        $this->travel(2)->days();
        $page->callAction('markExited')->assertHasNoActionErrors();
        $booking->refresh();
        $this->assertSame('exited', $booking->status);
        $this->assertTrue($booking->exited_at->equalTo(now()));
        $this->assertTrue($booking->arrived_at->lessThan($booking->exited_at));
        $this->assertTrue($booking->countsTowardCap());
        $this->assertNull($booking->nextStatus());
    }

    public function test_a_step_is_offered_only_at_its_own_status(): void
    {
        $booking = Booking::factory()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($this->owner);

        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->assertActionVisible('markArrived')
            ->assertActionHidden('startWork')
            ->assertActionHidden('markReady')
            ->assertActionHidden('markExited');
    }

    public function test_the_team_books_a_walk_in_for_a_date_and_can_give_the_size_up_front(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CreateBooking::class)
            ->fillForm([
                'kind' => 'onsite',
                'source' => 'walk_in',
                'branch_id' => $this->branch->id,
                'services' => [$this->detail->id],
                // Inside the notice the site asks for: staff aren't held to it
                'drop_off_on' => '2026-11-16',
                'name' => 'Walk In',
                'phone' => '0917 222 3333',
                'vehicle_year' => 2018,
                'vehicle_model' => 'Honda City',
                'vehicle_type' => 'sedan',
                'plate_number' => 'XYZ 987',
                'vehicle_size_id' => $this->medium->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $booking = Booking::sole();
        $this->assertSame(['confirmed', 'walk_in', '2026-11-16', 2500, 'Medium'], [$booking->status, $booking->source, $booking->drop_off_on->toDateString(), $booking->total_price, $booking->vehicle_size_name]);
        $this->assertNotNull($booking->read_at);
        $this->assertTrue($booking->vehicle->isReviewed());
    }

    public function test_a_full_day_needs_book_anyway(): void
    {
        Booking::factory()->count(2)->on('2026-11-17')->create(['branch_id' => $this->branch->id]);
        $this->actingAs($this->owner);

        $form = [
            'kind' => 'onsite',
            'source' => 'phone',
            'branch_id' => $this->branch->id,
            'services' => [$this->detail->id],
            'drop_off_on' => '2026-11-17',
            'name' => 'Caller',
            'phone' => '0917 222 3333',
            'vehicle_year' => 2020,
            'vehicle_model' => 'Toyota Vios',
            'vehicle_type' => 'sedan',
            'plate_number' => 'CAL 123',
        ];

        Livewire::test(CreateBooking::class)->fillForm($form)->call('create')->assertHasFormErrors(['drop_off_on']);
        $this->assertSame(2, Booking::count());

        Livewire::test(CreateBooking::class)->fillForm([...$form, 'force' => true])->call('create')->assertHasNoFormErrors();
        $this->assertSame(3, Booking::count());
        $this->assertNull(Booking::latest('id')->first()->total_price);
    }

    public function test_moving_a_booking_from_the_admin_rechecks_the_daily_cap(): void
    {
        $this->branch->update(['daily_cap' => 1]);
        $booking = Booking::factory()->on('2026-11-17')->create(['branch_id' => $this->branch->id]);
        Booking::factory()->on('2026-11-18')->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->owner);

        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction('reschedule', data: ['drop_off_on' => '2026-11-18'])
            ->assertNotified('Not moved');
        $this->assertSame('2026-11-17', $booking->refresh()->drop_off_on->toDateString());

        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction('reschedule', data: ['drop_off_on' => '2026-11-19'])
            ->assertHasNoActionErrors();
        $this->assertSame('2026-11-19', $booking->refresh()->drop_off_on->toDateString());

        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction('reschedule', data: ['drop_off_on' => '2026-11-18', 'force' => true])
            ->assertHasNoActionErrors();
        $this->assertSame('2026-11-18', $booking->refresh()->drop_off_on->toDateString());
    }

    public function test_a_day_marked_full_or_closed_takes_no_booking_even_when_forced(): void
    {
        BranchBlock::factory()->full()->between('2026-11-17 00:00', '2026-11-18 00:00')->create(['branch_id' => $this->branch->id]);
        $booking = Booking::factory()->on('2026-11-19')->create(['branch_id' => $this->branch->id]);
        $this->actingAs($this->owner);
        $quote = Reservations::quote($this->branch, [$this->detail->id]);
        $details = ['name' => 'Caller', 'phone' => '0917 222 3333', 'vehicle_year' => 2020, 'vehicle_model' => 'Toyota Vios', 'plate_number' => 'CAL 123'];

        foreach (['2026-11-17' => 'That day is marked fully booked. Pick another date.', '2026-11-22' => 'The branch is closed that day. Pick another date.'] as $day => $message) {
            foreach ([
                fn () => Reservations::reserve($this->branch, null, $quote, CarbonImmutable::parse($day), $details, source: 'phone', force: true),
                fn () => Reservations::reschedule($booking, CarbonImmutable::parse($day), byCustomer: false, force: true),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail("{$day} was booked over");
                } catch (ValidationException $e) {
                    $this->assertSame($message, $e->errors()['date'][0]);
                }
            }

            $this->assertContains($day, Availability::blockedForStaff($this->branch, $this->owner));
        }

        $this->assertSame(1, Booking::count());
        $this->assertSame('2026-11-19', $booking->refresh()->drop_off_on->toDateString());
    }

    public function test_only_a_manager_or_super_admin_books_over_the_daily_cap(): void
    {
        Booking::factory()->count(2)->on('2026-11-17')->create(['branch_id' => $this->branch->id]);
        $day = CarbonImmutable::parse('2026-11-17');
        $sales = User::factory()->salesAdmin()->create();
        $manager = User::factory()->branchManager()->create();
        $sales->branches()->sync([$this->branch->id]);
        $manager->branches()->sync([$this->branch->id]);

        $this->assertFalse(Availability::canOverbook($this->branch, $day, $sales));
        $this->assertContains('2026-11-17', Availability::blockedForStaff($this->branch, $sales));
        $this->assertTrue(Availability::canOverbook($this->branch, $day, $manager));
        $this->assertNotContains('2026-11-17', Availability::blockedForStaff($this->branch, $manager));

        $this->actingAs($sales);
        $this->expectException(ValidationException::class);
        Reservations::reserve($this->branch, null, Reservations::quote($this->branch, [$this->detail->id]), $day, ['name' => 'Caller', 'phone' => '0917 222 3333', 'vehicle_year' => 2020, 'vehicle_model' => 'Toyota Vios', 'plate_number' => 'CAL 123'], source: 'phone', force: true);
    }

    public function test_the_ready_date_can_be_changed_for_a_car_that_runs_late(): void
    {
        $booking = Booking::factory()->on('2026-11-17')->create(['branch_id' => $this->branch->id, 'status' => 'in_progress']);
        $this->actingAs($this->owner);

        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction('changeReadyDate', data: ['ready_on' => '2026-11-20'])
            ->assertHasNoActionErrors();

        $this->assertSame('2026-11-20', $booking->refresh()->ready_on->toDateString());
    }

    public function test_a_home_request_is_scheduled_with_the_fee_when_on(): void
    {
        PaymentSetting::instance()->update(['reservation_fee_enabled' => true, 'amount_value' => 300]);
        $booking = Booking::factory()->home()->create(['branch_id' => $this->branch->id, 'total_days' => 1]);

        $this->actingAs($this->owner);

        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction('scheduleHome', data: ['drop_off_on' => '2026-11-20', 'ask_fee' => true])
            ->assertHasNoActionErrors();

        $booking->refresh();
        $this->assertSame(['awaiting_payment', 300, null, '2026-11-20', '2026-11-20'], [$booking->status, $booking->amount_due, $booking->hold_expires_at, $booking->drop_off_on->toDateString(), $booking->ready_on->toDateString()]);
        $this->assertSame('awaiting_payment', $booking->stage());
    }

    public function test_the_size_review_prices_the_booking_and_tells_the_customer(): void
    {
        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);
        $vehicle = Vehicle::factory()->create();
        $booking = Booking::factory()->create(['branch_id' => $this->branch->id, 'vehicle_id' => $vehicle->id, 'total_days' => 4]);
        $booking->items()->createMany([
            ['service_id' => $this->detail->id, 'service_name' => 'Exterior detailing', 'duration_days' => 1],
            ['service_id' => $this->coating->id, 'service_name' => 'Graphene coating', 'duration_days' => 3],
        ]);
        $this->assertTrue($booking->needsSizeReview());

        $this->actingAs($this->owner);

        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->assertActionVisible('setSize')
            ->callAction('setSize', data: ['vehicle_size_id' => $this->medium->id])
            ->assertHasNoActionErrors();

        $booking->refresh();
        $this->assertSame([20500, 'Medium', false], [$booking->total_price, $booking->vehicle_size_name, $booking->needsSizeReview()]);
        $this->assertNotNull($booking->size_reviewed_at);
        $this->assertNotNull($booking->priced_at);
        $this->assertSame([2500, 18000], $booking->items->pluck('price')->all());
        $this->assertSame(['set', 20500], [$booking->priceForCustomer()['status'], $booking->priceForCustomer()['total']]);
        $this->assertTrue($vehicle->refresh()->isReviewed());
        Mail::assertSent(CustomerBookingUpdate::class, fn (CustomerBookingUpdate $mail) => $mail->kind === 'priced' && str_contains($mail->render(), '20,500'));
    }

    public function test_a_size_class_with_a_missing_rate_leaves_the_booking_unpriced_and_in_review(): void
    {
        Mail::fake();
        $large = VehicleSize::factory()->create(['name' => 'Large']);
        // Only the detail has a Large price
        ServiceRate::factory()->create(['service_id' => $this->detail->id, 'vehicle_size_id' => $large->id, 'price' => 3000]);
        $booking = Booking::factory()->create(['branch_id' => $this->branch->id, 'total_days' => 4]);
        $booking->items()->createMany([
            ['service_id' => $this->detail->id, 'service_name' => 'Exterior detailing', 'duration_days' => 1],
            ['service_id' => $this->coating->id, 'service_name' => 'Graphene coating', 'duration_days' => 3],
        ]);

        $this->actingAs($this->owner);

        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction('setSize', data: ['vehicle_size_id' => $large->id])
            ->assertNotified('No price for Large yet');

        $booking->refresh();
        $this->assertNull($booking->total_price);
        $this->assertNull($booking->priced_at);
        $this->assertSame('Large', $booking->vehicle_size_name);
        $this->assertTrue($booking->needsSizeReview());
        $this->assertSame('reviewing', $booking->priceForCustomer()['status']);
        Mail::assertNotSent(CustomerBookingUpdate::class);

        // Once the rate is there, reviewing again prices it
        ServiceRate::factory()->create(['service_id' => $this->coating->id, 'vehicle_size_id' => $large->id, 'price' => 21000]);

        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction('setSize', data: ['vehicle_size_id' => $large->id])
            ->assertHasNoActionErrors();

        $this->assertSame(24000, $booking->refresh()->total_price);
    }

    public function test_closures_cover_whole_days(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ManageBranchBlocks::class)
            ->callAction('create', data: ['branch_id' => null, 'kind' => 'closed', 'title' => 'Bonifacio Day', 'first_day' => '2026-11-30', 'last_day' => '2026-11-30'])
            ->assertHasNoActionErrors();

        $block = BranchBlock::sole();
        $this->assertNull($block->branch_id);
        $this->assertSame(['2026-11-30 00:00', '2026-12-01 00:00'], [$block->starts_at->format('Y-m-d H:i'), $block->ends_at->format('Y-m-d H:i')]);
        $this->assertSame($this->owner->id, $block->created_by);
    }

    public function test_a_branch_is_added_as_a_draft_and_its_days_saved(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CreateBranch::class)
            ->fillForm(['name' => 'Antipolo', 'slug' => 'antipolo', 'status' => 'draft', 'daily_cap' => 0, 'lead_days' => 1, 'months_ahead' => 3])
            ->call('create')
            ->assertHasNoFormErrors();

        $branch = Branch::where('slug', 'antipolo')->sole();
        $this->assertSame('draft', $branch->status);
        $this->assertFalse($branch->hasHours());

        Livewire::test(EditBranch::class, ['record' => $branch->getRouteKey()])
            ->fillForm([
                'weekly_hours' => [
                    1 => ['open_today' => true, 'open' => '09:00', 'close' => '17:00'],
                    7 => ['open_today' => false, 'open' => '09:00', 'close' => '17:00'],
                ],
                'daily_cap' => 3,
                'lead_days' => 2,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $branch->refresh();
        $this->assertSame(['open' => '09:00', 'close' => '17:00'], $branch->weeklyHours()[1]);
        $this->assertNull($branch->weeklyHours()[7]);
        $this->assertSame([3, 2], [$branch->dailyCap(), $branch->leadDays()]);
    }

    public function test_a_service_has_one_estimate_and_a_price_per_size_class(): void
    {
        $large = VehicleSize::factory()->create(['name' => 'Large']);
        $this->actingAs($this->owner);

        Livewire::test(EditService::class, ['record' => $this->coating->getRouteKey()])
            ->assertFormSet(['estimate_days' => 3, "rates.{$this->medium->id}.price" => 18000])
            ->fillForm([
                'estimate_days' => 4,
                "rates.{$this->medium->id}.price" => 19000,
                "rates.{$large->id}.price" => 25000,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(4, $this->coating->refresh()->estimate_days);
        $this->assertSame(19000, ServiceRate::where('service_id', $this->coating->id)->where('vehicle_size_id', $this->medium->id)->value('price'));
        $this->assertSame(25000, ServiceRate::where('service_id', $this->coating->id)->where('vehicle_size_id', $large->id)->value('price'));

        // A blank price removes the class
        Livewire::test(EditService::class, ['record' => $this->coating->getRouteKey()])
            ->fillForm(["rates.{$large->id}.price" => null])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse(ServiceRate::where('service_id', $this->coating->id)->where('vehicle_size_id', $large->id)->exists());
    }

    public function test_a_new_service_starts_without_an_estimate_and_cant_be_booked_yet(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CreateService::class)
            ->fillForm([
                'name' => 'Headlight restoration',
                'slug' => 'headlight-restoration',
                'service_category_id' => $this->detail->service_category_id,
                "rates.{$this->medium->id}.price" => 1500,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::where('slug', 'headlight-restoration')->sole();
        $this->assertNull($service->estimate_days);
        $this->assertSame(1500, $service->rates()->sole()->price);
        $this->assertFalse($service->isBookable());
        $this->assertFalse(Service::bookable()->whereKey($service->id)->exists());
    }

    public function test_settings_pages_save(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(SiteSettings::class)
            ->fillForm(['tagline' => 'Protect what you drive', 'phone' => '0948-804-2284', 'facebook_url' => 'https://www.facebook.com/826AutoAesthetic'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('0948-804-2284', SiteSetting::current()->phone);

        Livewire::test(SiteSettings::class)->fillForm(['instagram_url' => 'http://insecure.example'])->call('save')->assertHasFormErrors(['instagram_url']);

        Livewire::test(BookingSettings::class)
            ->fillForm(['cancel_cutoff_hours' => 12, 'reschedule_enabled' => true, 'reschedule_cutoff_hours' => 6, 'max_reschedules' => 2, 'max_unpaid_holds_per_guest' => 1, 'max_upcoming_per_guest' => 5])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame([12, 5], [BookingSetting::current()->cancel_cutoff_hours, BookingSetting::current()->max_upcoming_per_guest]);

        Livewire::test(PaymentSettings::class)
            ->fillForm(['reservation_fee_enabled' => true, 'amount_value' => 500, 'hold_minutes' => 60])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame([true, 500, 60, '₱500'], [PaymentSetting::instance()->feeEnabled(), PaymentSetting::instance()->amount_value, PaymentSetting::instance()->holdMinutes(), PaymentSetting::instance()->feeLabel()]);
    }

    public function test_the_branch_lists_what_stops_online_booking(): void
    {
        $branch = Branch::factory()->cap(0)->create(['weekly_hours' => null]);
        PaymentSetting::instance()->update(['reservation_fee_enabled' => true]);

        $missing = $branch->missing();

        $this->assertCount(5, $missing);
        $this->assertStringContainsString('daily cap', $missing[0]);
        $this->assertFalse($branch->isOnsiteBookable());

        $this->assertSame([
            'The reservation fee is on: set its amount in Payments.',
            'The reservation fee is on: add a GCash or Maya account here or in Payments.',
        ], $this->branch->missing());

        PaymentSetting::instance()->update(['amount_value' => 500, 'gcash_enabled' => true, 'gcash_number' => '0917 000 0000']);
        $this->assertTrue($this->branch->refresh()->isOnsiteBookable());

        // A service without a day estimate doesn't count
        Service::query()->update(['estimate_days' => null]);
        $this->assertStringContainsString('day estimate', $this->branch->refresh()->missing()[0]);
    }

    public function test_closures_can_mark_a_day_fully_booked(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ManageBranchBlocks::class)
            ->callAction('create', data: ['branch_id' => $this->branch->id, 'kind' => 'full', 'title' => 'Big job', 'first_day' => '2026-11-24', 'last_day' => '2026-11-25'])
            ->assertHasNoActionErrors();

        $block = BranchBlock::sole();
        $this->assertSame(['full', '2026-11-24', '2026-11-25'], [$block->kind, $block->firstDay()->toDateString(), $block->lastDay()->toDateString()]);
        $this->assertSame('full', Availability::check($this->branch, CarbonImmutable::parse('2026-11-25')));
    }
}
