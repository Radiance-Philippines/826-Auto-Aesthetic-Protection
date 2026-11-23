<?php

namespace Tests\Feature;

use App\Filament\Pages\Queue;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Mail\CustomerBookingUpdate;
use App\Mail\Invitation;
use App\Mail\TeamBookingAlert;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\Branch;
use App\Models\BranchBlock;
use App\Models\Guest;
use App\Models\MailSetting;
use App\Models\Photo;
use App\Models\ReferralRedemption;
use App\Models\ReferralSetting;
use App\Models\Review;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Support\Access;
use App\Support\Panels;
use App\Support\Rewards;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue as QueueFake;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Roles (super admin, branch manager, operations, sales admin), the areas
 * each has, per-account overrides, the ops heads-up to sales, the booking
 * history and two-factor sign-in.
 */
class RolesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $cainta;

    private Branch $sanMateo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);
    }

    private function staff(string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->state(['role' => $role])->create();
        $user->branches()->attach($branch ?? $this->cainta);

        return $user;
    }

    private function booking(array $state = []): Booking
    {
        return Booking::factory()->create(['branch_id' => $this->cainta->id, ...$state]);
    }

    // Roles and areas

    public function test_each_role_has_its_default_areas_and_a_super_admin_has_all(): void
    {
        $this->assertSame(array_keys(Access::AREAS), User::factory()->owner()->make()->areas());
        $this->assertTrue(User::factory()->owner()->make()->isOwner());

        $manager = User::factory()->branchManager()->make();
        $this->assertTrue($manager->canAccessArea('bookings.sales') && $manager->canAccessArea('queue.update') && $manager->canAccessArea('closures'));
        $this->assertFalse($manager->canAccessArea('shop') || $manager->canAccessArea('settings') || $manager->canAccessArea('content') || $manager->canAccessArea('users'));

        $operations = User::factory()->operations()->make();
        $this->assertTrue($operations->canAccessArea('queue.update') && $operations->canAccessArea('bookings.view') && $operations->canAccessArea('warranty'));
        $this->assertFalse($operations->canAccessArea('bookings.sales') || $operations->canAccessArea('customers.write') || $operations->canAccessArea('reviews'));

        $sales = User::factory()->salesAdmin()->make();
        $this->assertTrue($sales->canAccessArea('bookings.sales') && $sales->canAccessArea('customers.write') && $sales->canAccessArea('inbox'));
        $this->assertFalse($sales->canAccessArea('queue.update'));
    }

    public function test_the_owner_flag_follows_the_role_and_existing_users_default_to_branch_manager(): void
    {
        $user = User::factory()->create();
        $this->assertSame('branch_manager', $user->fresh()->role);
        $this->assertFalse((bool) $user->fresh()->is_owner);

        $user->update(['role' => 'super_admin']);
        $this->assertTrue((bool) $user->fresh()->is_owner);
    }

    public function test_an_account_can_have_its_own_access_list(): void
    {
        $operations = $this->staff('operations');
        $this->assertFalse($operations->canAccessArea('reviews'));

        $operations->update(['permissions' => ['bookings.view', 'reviews']]);

        $this->assertTrue($operations->fresh()->canAccessArea('reviews'));
        $this->assertFalse($operations->fresh()->canAccessArea('queue.update'));
    }

    public function test_pages_per_role(): void
    {
        $matrix = [
            'branch_manager' => ['allowed' => ['', '/queue', '/bookings', '/customers', '/warranty', '/reviews', '/manual-transaction', '/branch-blocks'], 'denied' => ['/branches', '/services', '/photos', '/videos', '/vehicle-sizes', '/backjob-types', '/users', '/booking-rules', '/security']],
            'operations' => ['allowed' => ['', '/queue', '/bookings', '/customers', '/warranty'], 'denied' => ['/bookings/create', '/customers/create', '/reviews', '/manual-transaction', '/branch-blocks', '/branches', '/photos', '/users', '/security']],
            'sales_admin' => ['allowed' => ['', '/bookings', '/bookings/create', '/customers', '/customers/create', '/warranty', '/reviews', '/manual-transaction'], 'denied' => ['/queue', '/branch-blocks', '/branches', '/photos', '/users', '/security', '/referral']],
            'super_admin' => ['allowed' => ['', '/queue', '/branches', '/services', '/photos', '/videos', '/users', '/security', '/referral', '/branch-blocks', '/vehicle-sizes'], 'denied' => []],
        ];

        foreach ($matrix as $role => $urls) {
            $user = $this->staff($role);
            $this->actingAs($user);

            foreach ($urls['allowed'] as $path) {
                // The operations home is a redirect to the queue; its dashboard is a page of its own
                $url = $path === '' ? $this->dashboard($user) : $this->panel($path, $user);
                $this->get($url)->assertOk("{$role} should open {$url}");
            }

            foreach ($urls['denied'] as $path) {
                $url = $this->panel($path, $user);
                $this->get($url)->assertForbidden("{$role} should not open {$url}");
            }
        }
    }

    public function test_policies_per_role_and_area_group(): void
    {
        $booking = $this->booking();
        $other = Booking::factory()->create(['branch_id' => $this->sanMateo->id]);
        $guest = Guest::factory()->create(['branch_id' => $this->cainta->id]);
        $review = Review::factory()->create(['branch_id' => $this->cainta->id]);
        $photo = Photo::factory()->create();
        $globalBlock = BranchBlock::factory()->create(['branch_id' => null]);
        $ownBlock = BranchBlock::factory()->create(['branch_id' => $this->cainta->id]);

        $manager = $this->staff('branch_manager');
        $operations = $this->staff('operations');
        $sales = $this->staff('sales_admin');
        $owner = User::factory()->owner()->create();

        // Bookings: view for all of them at their branch; sales or queue can edit; only a super admin deletes
        foreach ([$manager, $operations, $sales] as $user) {
            $this->assertTrue(Gate::forUser($user)->allows('view', $booking));
            $this->assertFalse(Gate::forUser($user)->allows('view', $other));
            $this->assertTrue(Gate::forUser($user)->allows('update', $booking));
            $this->assertFalse(Gate::forUser($user)->allows('delete', $booking));
        }

        $this->assertFalse(Gate::forUser($operations)->allows('create', Booking::class));
        $this->assertTrue(Gate::forUser($sales)->allows('create', Booking::class));
        $this->assertTrue(Gate::forUser($owner)->allows('delete', $booking));

        // Customers
        $this->assertTrue(Gate::forUser($operations)->allows('view', $guest));
        $this->assertFalse(Gate::forUser($operations)->allows('update', $guest));
        $this->assertFalse(Gate::forUser($operations)->allows('create', Guest::class));
        $this->assertTrue(Gate::forUser($sales)->allows('update', $guest));
        $this->assertFalse(Gate::forUser($sales)->allows('delete', $guest));

        // Reviews
        $this->assertFalse(Gate::forUser($operations)->allows('viewAny', Review::class));
        $this->assertTrue(Gate::forUser($sales)->allows('update', $review));
        $this->assertFalse(Gate::forUser($sales)->allows('delete', $review));

        // Content and shop
        foreach ([$manager, $operations, $sales] as $user) {
            $this->assertFalse(Gate::forUser($user)->allows('viewAny', Photo::class));
            $this->assertFalse(Gate::forUser($user)->allows('update', $photo));
            $this->assertFalse(Gate::forUser($user)->allows('viewAny', Branch::class));
        }

        $this->assertTrue(Gate::forUser($owner)->allows('update', $photo));

        // Closures: own branches for a manager, every branch needs the shop area
        $this->assertTrue(Gate::forUser($manager)->allows('update', $ownBlock));
        $this->assertFalse(Gate::forUser($manager)->allows('update', $globalBlock));
        $this->assertFalse(Gate::forUser($sales)->allows('create', BranchBlock::class));
        $this->assertTrue(Gate::forUser($owner)->allows('update', $globalBlock));
    }

    // Booking actions are enforced on the server

    public function test_operations_cannot_run_sales_actions_but_moves_units_through_the_queue(): void
    {
        $booking = $this->booking(['status' => 'payment_review']);

        $this->actingAs($this->staff('operations'));
        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->assertActionHidden('markPaid')
            ->assertActionHidden('rejectProof')
            ->assertActionHidden('setSize')
            ->assertActionHidden('cancelBooking')
            ->assertActionHidden('reschedule')
            ->call('mountAction', 'markPaid')
            ->call('callMountedAction');
        $this->assertSame('payment_review', $booking->fresh()->status);

        $booking->update(['status' => 'confirmed']);
        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->assertActionVisible('markArrived')
            ->callAction('markArrived');
        $this->assertSame('arrived', $booking->fresh()->status);
    }

    public function test_sales_confirm_payments_but_cannot_move_units_through_the_queue(): void
    {
        $booking = $this->booking(['status' => 'payment_review']);

        $this->actingAs($this->staff('sales_admin'));
        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->assertActionVisible('markPaid')
            ->callAction('markPaid');
        $this->assertSame('confirmed', $booking->fresh()->status);

        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->assertActionHidden('markArrived')
            ->call('mountAction', 'markArrived')
            ->call('callMountedAction');
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_sales_cannot_advance_a_card_on_the_queue(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->staff('sales_admin'));
        Livewire::test(Queue::class)->call('advance', $booking->id)->assertForbidden();
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_operations_advance_a_card_on_the_queue(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->staff('operations'));
        Livewire::test(Queue::class)->call('advance', $booking->id)->assertOk();
        $this->assertSame('arrived', $booking->fresh()->status);
    }

    public function test_operations_cannot_edit_the_customer_part_of_a_booking(): void
    {
        $booking = $this->booking(['name' => 'Maria Santos', 'vehicle_model' => 'Toyota Vios']);

        $this->actingAs($this->staff('operations'));
        Livewire::test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->fillForm(['name' => 'Someone Else', 'vehicle_model' => 'Honda Civic'])
            ->call('save');

        $this->assertSame('Maria Santos', $booking->fresh()->name);
        $this->assertSame('Honda Civic', $booking->fresh()->vehicle_model);
    }

    public function test_the_payment_screenshot_is_for_the_sales_side_only(): void
    {
        $booking = $this->booking(['payment_proof' => 'payment-proofs/1/x.webp']);

        $this->actingAs($this->staff('operations'))->get("/admin-files/bookings/{$booking->id}/proof")->assertForbidden();
    }

    // History and heads-up

    public function test_the_booking_history_records_moves_and_shows_on_the_view_page(): void
    {
        $booking = $this->booking();
        $operations = $this->staff('operations');

        $this->actingAs($operations);
        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])->callAction('markArrived');

        $event = BookingEvent::sole();
        $this->assertSame([$booking->id, $operations->id, 'status', 'Booked', 'Arrived'], [$event->booking_id, $event->user_id, $event->kind, $event->from, $event->to]);

        $this->get($this->panel("/bookings/{$booking->id}"))->assertOk()->assertSee('History')->assertSee("{$operations->name}: Status: Booked → Arrived");
    }

    public function test_changing_the_ready_date_tells_sales_by_bell_and_one_email_and_not_the_customer(): void
    {
        Mail::fake();
        // The bell is written at once: nothing here runs a queue worker
        QueueFake::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);
        $this->cainta->update(['alert_email' => 'cainta@826auto.test']);

        $booking = $this->booking(['ready_on' => '2026-11-20', 'drop_off_on' => '2026-11-19']);
        $operations = $this->staff('operations');
        $sales = $this->staff('sales_admin');
        $faraway = $this->staff('sales_admin', $this->sanMateo);
        $noSales = $this->staff('operations');

        $this->actingAs($operations);
        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction('changeReadyDate', data: ['ready_on' => '2026-11-24']);

        $this->assertSame('2026-11-24', $booking->fresh()->ready_on->toDateString());
        $this->assertSame(1, $sales->notifications()->count());
        $this->assertStringContainsString('Fri, Nov 20 → Tue, Nov 24', $sales->notifications()->first()->data['body']);
        $this->assertSame(0, $faraway->notifications()->count());
        $this->assertSame(0, $noSales->notifications()->count());
        $this->assertSame(0, $operations->notifications()->count());

        Mail::assertSent(TeamBookingAlert::class, 1);
        Mail::assertSent(TeamBookingAlert::class, fn (TeamBookingAlert $mail) => $mail->kind === 'heads_up' && $mail->hasTo('cainta@826auto.test'));
        Mail::assertNotSent(CustomerBookingUpdate::class);
        $this->assertSame('ready_on', BookingEvent::where('booking_id', $booking->id)->latest('id')->first()->kind);
    }

    public function test_a_ready_date_change_by_sales_says_nothing(): void
    {
        Mail::fake();
        $booking = $this->booking(['ready_on' => '2026-11-20', 'drop_off_on' => '2026-11-19']);
        $other = $this->staff('sales_admin');

        $this->actingAs($this->staff('branch_manager'))->assertTrue(true);
        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])->callAction('changeReadyDate', data: ['ready_on' => '2026-11-24']);

        $this->assertSame(0, $other->notifications()->count());
        Mail::assertNothingSent();
    }

    public function test_changing_the_unit_reopens_the_size_review_and_tells_sales(): void
    {
        Mail::fake();
        $booking = $this->booking(['vehicle_model' => 'Toyota Vios', 'plate_number' => 'ABC1234'])->forceFill(['priced_at' => now(), 'total_price' => 3000, 'vehicle_size_name' => 'Medium']);
        $booking->save();
        $sales = $this->staff('sales_admin');

        $this->actingAs($this->staff('branch_manager'));
        Livewire::test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->fillForm(['vehicle_model' => 'Toyota Fortuner', 'vehicle_type' => 'suv'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($booking->fresh()->priced_at);
        $this->assertSame(1, $sales->notifications()->count());
        $this->assertStringContainsString('Fortuner', $sales->notifications()->first()->data['body']);
        $this->assertSame('unit', BookingEvent::where('kind', 'unit')->sole()->kind);
        Mail::assertNotSent(CustomerBookingUpdate::class);
    }

    // Users

    public function test_the_users_page_is_for_super_admins_and_saves_an_override_only_when_it_differs(): void
    {
        $this->actingAs($this->staff('operations'))->get($this->panel('/users'))->assertForbidden();

        $owner = User::factory()->owner()->create();
        $member = $this->staff('operations');
        $this->actingAs($owner);

        $defaults = Access::defaults('operations');

        Livewire::test(EditUser::class, ['record' => $member->getRouteKey()])
            ->assertFormSet(['role' => 'operations', 'access' => $defaults])
            ->fillForm(['access' => $defaults])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($member->fresh()->permissions);

        Livewire::test(EditUser::class, ['record' => $member->getRouteKey()])
            ->fillForm(['access' => [...$defaults, 'reviews']])
            ->call('save');
        $this->assertTrue($member->fresh()->canAccessArea('reviews'));
        $this->assertContains('reviews', $member->fresh()->permissions);

        Livewire::test(EditUser::class, ['record' => $member->getRouteKey()])
            ->fillForm(['role' => 'sales_admin', 'access' => Access::defaults('sales_admin')])
            ->call('save');
        $this->assertSame('sales_admin', $member->fresh()->role);
        $this->assertNull($member->fresh()->permissions);
    }

    public function test_the_last_super_admin_cannot_be_demoted_or_deleted(): void
    {
        $owner = User::factory()->owner()->create();
        $second = User::factory()->owner()->create();
        $this->actingAs($owner);

        $this->assertTrue(UserResource::canDelete($second));

        $second->update(['role' => 'branch_manager']);
        $this->assertFalse(UserResource::canDelete($owner));

        Livewire::test(EditUser::class, ['record' => $owner->getRouteKey()])
            ->assertFormFieldDisabled('role');
    }

    public function test_an_invite_takes_a_role(): void
    {
        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'mailbox-secret']);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ListUsers::class)
            ->callAction('invite', data: ['name' => 'Detailer', 'email' => 'detailer@example.com', 'role' => 'operations', 'branches' => [$this->cainta->id]])
            ->assertHasNoActionErrors();

        $member = User::where('email', 'detailer@example.com')->sole();
        $this->assertSame('operations', $member->role);
        $this->assertFalse($member->canAccessArea('bookings.sales'));
        Mail::assertSent(Invitation::class);
    }

    // Two-factor

    public function test_two_factor_is_only_required_when_the_setting_is_on(): void
    {
        $user = $this->staff('branch_manager');
        $this->actingAs($user);

        $this->get($this->panel('/bookings'))->assertOk();

        SecuritySetting::instance()->update(['require_two_factor' => true]);

        $this->get($this->panel('/bookings'))->assertRedirect(Filament::getPanel(Panels::homeId($user))->getSetUpRequiredMultiFactorAuthenticationUrl());

        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->get($this->panel('/bookings'))->assertOk();
    }

    public function test_a_super_admin_resets_someones_two_factor(): void
    {
        $member = $this->staff('operations');
        $member->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $member->saveAppAuthenticationRecoveryCodes(['code-1']);
        $member->toggleEmailAuthentication(true);
        $this->assertTrue($member->fresh()->hasTwoFactor());

        $this->actingAs($this->staff('sales_admin'));
        $this->get($this->panel('/users'))->assertForbidden();

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(ListUsers::class)->callTableAction('resetTwoFactor', $member);

        $member = $member->fresh();
        $this->assertFalse($member->hasTwoFactor());
        $this->assertNull($member->app_authentication_recovery_codes);
        $this->assertFalse($member->has_email_authentication);
    }

    public function test_the_two_factor_secret_is_stored_encrypted(): void
    {
        $member = $this->staff('operations');
        $member->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->assertNotSame('JBSWY3DPEHPK3PXP', $member->getRawOriginal('app_authentication_secret'));
        $this->assertSame('JBSWY3DPEHPK3PXP', $member->fresh()->getAppAuthenticationSecret());
        $this->assertArrayNotHasKey('app_authentication_secret', $member->toArray());
    }

    public function test_only_the_settings_area_opens_security(): void
    {
        $this->actingAs($this->staff('branch_manager'))->get($this->panel('/security'))->assertForbidden();
        $this->actingAs(User::factory()->owner()->create())->get($this->panel('/security'))->assertOk()->assertSee('Require two-factor sign-in for everyone');
    }

    // Points

    private function guestWithPoints(int $points): Guest
    {
        $guest = Guest::factory()->create(['branch_id' => $this->cainta->id]);
        $friend = Guest::factory()->create();
        $booking = Booking::factory()->create(['branch_id' => $this->cainta->id, 'guest_id' => $friend->id]);
        ReferralRedemption::create(['code_guest_id' => $guest->id, 'used_by_guest_id' => $friend->id, 'booking_id' => $booking->id, 'points_awarded' => $points, 'awarded_at' => now()]);

        return $guest;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function redeem(Guest $guest, array $data): void
    {
        Livewire::test(ViewCustomer::class, ['record' => $guest->getRouteKey()])->callAction('redeemPoints', data: $data);
    }

    public function test_points_are_redeemed_for_a_discount_but_never_overspent(): void
    {
        ReferralSetting::instance()->update(['peso_per_point' => 2]);
        $guest = $this->guestWithPoints(100);
        $booking = $this->booking(['guest_id' => $guest->id, 'total_price' => 1000, 'priced_at' => now(), 'vehicle_size_name' => 'Medium']);

        $this->actingAs($this->staff('sales_admin'));

        $this->redeem($guest, ['points' => 150, 'booking_id' => $booking->id]);
        $this->assertSame(1000, $booking->fresh()->total_price);
        $this->assertSame(100, $guest->pointsBalance());

        $this->redeem($guest, ['points' => 60, 'booking_id' => $booking->id, 'note' => null]);
        $this->assertSame(880, $booking->fresh()->total_price);
        $this->assertSame(120, $booking->fresh()->discount_amount);
        $this->assertSame(40, $guest->pointsBalance());

        $this->redeem($guest, ['points' => 41, 'note' => 'Counter']);
        $this->assertSame(40, $guest->pointsBalance());

        // More than the booking is worth: refused
        $this->redeem($guest, ['points' => 40, 'booking_id' => $this->booking(['guest_id' => $guest->id, 'total_price' => 50, 'priced_at' => now()])->id]);
        $this->assertSame(40, $guest->pointsBalance());

        $this->assertSame(1, $guest->rewardRedemptions()->count());
        $this->assertSame(120, $guest->rewardRedemptions()->first()->peso_value);
        $this->assertContains('discount', BookingEvent::where('booking_id', $booking->id)->pluck('kind')->all());
    }

    public function test_without_a_peso_value_points_only_adjust_with_a_note(): void
    {
        $guest = $this->guestWithPoints(50);
        $booking = $this->booking(['guest_id' => $guest->id, 'total_price' => 1000, 'priced_at' => now()]);
        $this->actingAs($this->staff('sales_admin'));

        $this->redeem($guest, ['points' => 10, 'booking_id' => $booking->id]);
        $this->redeem($guest, ['points' => 10]);
        $this->assertSame(50, $guest->pointsBalance());

        $this->redeem($guest, ['points' => 10, 'note' => 'Free wash at the counter']);
        $this->assertSame(40, $guest->pointsBalance());
        $this->assertSame(1000, $booking->fresh()->total_price);
        $this->assertNull($guest->rewardRedemptions()->sole()->peso_value);
    }

    public function test_operations_cannot_redeem_points(): void
    {
        $guest = $this->guestWithPoints(50);

        $this->actingAs($this->staff('operations'));
        Livewire::test(ViewCustomer::class, ['record' => $guest->getRouteKey()])
            ->assertActionHidden('redeemPoints')
            ->call('mountAction', 'redeemPoints', ['points' => 10, 'note' => 'x'])
            ->call('callMountedAction');

        $this->assertSame(50, $guest->pointsBalance());
    }

    public function test_redeeming_needs_both_customers_write_and_bookings_sales(): void
    {
        $guest = $this->guestWithPoints(50);
        $manager = $this->staff('branch_manager');
        $manager->update(['permissions' => ['bookings.view', 'customers.view', 'customers.write']]);

        $this->actingAs($manager);
        Livewire::test(ViewCustomer::class, ['record' => $guest->getRouteKey()])
            ->assertActionHidden('redeemPoints')
            ->call('mountAction', 'redeemPoints', ['points' => 10, 'note' => 'x'])
            ->call('callMountedAction');

        $this->assertSame(50, $guest->pointsBalance());
    }

    public function test_a_branch_manager_can_redeem_points(): void
    {
        $guest = $this->guestWithPoints(50);

        $this->actingAs($this->staff('branch_manager'));
        Livewire::test(ViewCustomer::class, ['record' => $guest->getRouteKey()])
            ->assertActionVisible('redeemPoints')
            ->callAction('redeemPoints', data: ['points' => 10, 'note' => 'Counter wash']);

        $this->assertSame(40, $guest->pointsBalance());
    }

    public function test_the_points_ledger_lists_earned_and_spent_lines(): void
    {
        $guest = $this->guestWithPoints(50);
        $this->actingAs($this->staff('sales_admin'));
        Livewire::test(ViewCustomer::class, ['record' => $guest->getRouteKey()])->callAction('redeemPoints', data: ['points' => 5, 'note' => 'Counter wash']);

        $this->get($this->panel("/customers/{$guest->id}"))->assertOk()->assertSee('Points balance');

        $ledger = Rewards::ledger($guest);
        $this->assertSame([-5, 50], $ledger->pluck('points')->sort()->values()->all());
    }
}
