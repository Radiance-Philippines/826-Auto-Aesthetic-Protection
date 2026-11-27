<?php

namespace Tests\Feature;

use App\Mail\CustomerBookingUpdate;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\MailSetting;
use App\Models\User;
use App\Support\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Queue endpoints: the Filament board as JSON, under the
 * same panel, area and branch rules.
 */
class ConsoleQueueTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const QUEUE = '/console/api/operations/queue';

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
        return Booking::factory()->on('2026-11-16')->create(['branch_id' => ($branch ?? $this->cainta)->id, ...$state]);
    }

    private function crew(array $state = []): User
    {
        return $this->staffUser('operations', $this->cainta, $state);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return [
            'the board' => ['getJson', ''],
            'a step' => ['postJson', '/1/stage'],
            'assigned to' => ['postJson', '/1/assigned-to'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_a_signed_out_call_is_401(string $method, string $path): void
    {
        $this->unit();

        $this->{$method}(self::QUEUE.$path)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_a_panel_the_person_does_not_have_is_403(string $method, string $path): void
    {
        $booking = $this->unit();
        $this->actingAs($this->staffUser('sales_admin', $this->cainta));

        $this->{$method}(self::QUEUE.str_replace('1', (string) $booking->id, $path), ['to' => 'arrived', 'assignedTo' => 'Jun'])->assertForbidden();
        $this->assertSame(['confirmed', null], [$booking->refresh()->status, $booking->assigned_to]);
    }

    #[DataProvider('endpoints')]
    public function test_a_panel_without_the_queue_is_404(string $method, string $path): void
    {
        $booking = $this->unit();
        $this->actingAs(User::factory()->owner()->create());

        $this->{$method}(str_replace('operations', 'sales', self::QUEUE).str_replace('1', (string) $booking->id, $path), ['to' => 'arrived', 'assignedTo' => 'Jun'])->assertNotFound();
        $this->assertSame('confirmed', $booking->refresh()->status);
    }

    #[DataProvider('endpoints')]
    public function test_without_the_bookings_area_it_is_403(string $method, string $path): void
    {
        $booking = $this->unit();
        $this->actingAs($this->crew(['permissions' => ['queue.update', 'dashboard']]));

        $this->{$method}(self::QUEUE.str_replace('1', (string) $booking->id, $path), ['to' => 'arrived', 'assignedTo' => 'Jun'])->assertForbidden();
        $this->assertSame('confirmed', $booking->refresh()->status);
    }

    public function test_seeing_without_queue_update_cannot_move_or_assign(): void
    {
        $booking = $this->unit();
        $this->actingAs($this->crew(['permissions' => ['bookings.view']]));

        $this->getJson(self::QUEUE)->assertOk()
            ->assertJsonPath('columns.0.cards.0.canAdvance', false)
            ->assertJsonPath('columns.0.cards.0.canAssign', false)
            ->assertJsonPath('links.newBooking', null);
        $this->postJson(self::QUEUE."/{$booking->id}/stage", ['to' => 'arrived'])->assertForbidden();
        $this->postJson(self::QUEUE."/{$booking->id}/assigned-to", ['assignedTo' => 'Jun'])->assertForbidden();
        $this->assertSame(['confirmed', null], [$booking->refresh()->status, $booking->assigned_to]);
    }

    public function test_the_board_has_the_days_strip_the_columns_and_the_arrivals(): void
    {
        $booked = $this->unit(['name' => 'Maria Santos', 'vehicle_model' => 'Toyota Vios', 'assigned_to' => 'Jun']);
        $booked->items()->create(['service_name' => 'Exterior detailing', 'category_name' => 'Detailing', 'price' => 2500, 'duration_days' => 1, 'sort_order' => 0]);
        $working = $this->unit(['name' => 'Ana Cruz', 'status' => 'in_progress', 'drop_off_on' => '2026-11-14']);
        $this->unit(['name' => 'Gone', 'status' => 'cancelled']);
        $this->unit(['name' => 'Other Branch'], $this->sanMateo);
        $this->actingAs($this->crew());

        $response = $this->getJson(self::QUEUE."?branch={$this->cainta->id}&date=2026-11-16")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('branch', ['id' => $this->cainta->id, 'name' => 'Cainta'])
            ->assertJsonPath('branches', [['id' => $this->cainta->id, 'name' => 'Cainta']])
            ->assertJsonPath('heading', 'Monday, Nov 16, 2026')
            ->assertJsonPath('isToday', true)
            ->assertJsonPath('dropOffs', ['cap' => 3, 'units' => 1, 'state' => 'ok'])
            ->assertJsonPath('sizeReviews', 2)
            ->assertJsonPath('homeRequests', 0)
            ->assertJsonPath('columns.*.key', ['confirmed', 'arrived', 'in_progress', 'quality_check', 'ready'])
            ->assertJsonPath('columns.0.cards.0.name', 'Maria Santos')
            ->assertJsonPath('columns.0.cards.0.services', 'Exterior detailing')
            ->assertJsonPath('columns.0.cards.0.assignedTo', 'Jun')
            ->assertJsonPath('columns.0.cards.0.next', 'arrived')
            ->assertJsonPath('columns.0.cards.0.button', 'Mark arrived')
            ->assertJsonPath('columns.0.cards.0.url', url("/operations/bookings/{$booked->id}"))
            ->assertJsonPath('columns.2.cards.0.id', $working->id)
            ->assertJsonPath('arrivals.*.id', [$booked->id])
            // A detailer has no closures area
            ->assertJsonPath('links.closures', null);

        $this->assertStringNotContainsString('Gone', $response->content());
        $this->assertStringNotContainsString('Other Branch', $response->content());
    }

    public function test_a_manager_gets_the_header_links(): void
    {
        $this->actingAs($this->staffUser('branch_manager', $this->cainta));

        $this->getJson(self::QUEUE)
            ->assertJsonPath('links.newBooking', url('/operations/bookings/create'))
            ->assertJsonPath('links.closures', url('/operations/branch-blocks'));
    }

    public function test_another_day_and_a_bad_date_fall_back_as_the_page_does(): void
    {
        $this->actingAs($this->crew());

        $this->getJson(self::QUEUE.'?date=2026-11-22')->assertJsonPath('date', '2026-11-22')->assertJsonPath('isToday', false)->assertJsonPath('dropOffs.state', 'closed');
        $this->getJson(self::QUEUE.'?date=not-a-date')->assertJsonPath('date', '2026-11-16');
        $this->getJson(self::QUEUE.'?date[]=2026-11-22')->assertJsonPath('date', '2026-11-16');
    }

    public function test_another_branch_is_404_to_read_and_to_change(): void
    {
        $theirs = $this->unit(['name' => 'Other Branch'], $this->sanMateo);
        $this->actingAs($this->crew());

        $this->getJson(self::QUEUE."?branch={$this->sanMateo->id}")->assertNotFound();
        $this->postJson(self::QUEUE."/{$theirs->id}/stage", ['to' => 'arrived'])->assertNotFound();
        $this->postJson(self::QUEUE."/{$theirs->id}/assigned-to", ['assignedTo' => 'Jun'])->assertNotFound();
        $this->assertSame(['confirmed', null], [$theirs->refresh()->status, $theirs->assigned_to]);
    }

    public function test_the_top_bars_branch_wins_over_the_address_and_scopes_changes(): void
    {
        $mine = $this->unit();
        $this->unit(['name' => 'San Mateo Unit'], $this->sanMateo);
        $this->actingAs(User::factory()->owner()->create())->withSession([CurrentBranch::SESSION => $this->sanMateo->id]);

        $this->getJson(self::QUEUE."?branch={$this->cainta->id}")
            ->assertOk()
            ->assertJsonPath('branch.name', 'San Mateo')
            ->assertJsonPath('branches', [['id' => $this->sanMateo->id, 'name' => 'San Mateo']]);
        $this->postJson(self::QUEUE."/{$mine->id}/stage", ['to' => 'arrived'])->assertNotFound();
    }

    public function test_someone_without_a_branch_gets_an_empty_board(): void
    {
        $this->actingAs(User::factory()->operations()->create());

        $this->getJson(self::QUEUE)->assertOk()->assertJsonPath('branch', null)->assertJsonPath('branches', [])->assertJsonMissingPath('columns');
    }

    public function test_a_unit_moves_one_step_at_a_time_and_the_customer_hears(): void
    {
        MailSetting::instance()->update(['address' => 'shop@826auto.test', 'password' => 'secret']);
        Mail::fake();
        $booking = $this->unit(['email' => 'maria@example.com']);
        $url = self::QUEUE."/{$booking->id}/stage";
        $this->actingAs($this->crew());

        $this->postJson($url, ['to' => 'in_progress'])->assertUnprocessable()->assertJsonValidationErrorFor('to');
        $this->postJson($url, ['to' => 'cancelled'])->assertUnprocessable();
        $this->postJson($url)->assertUnprocessable();
        $this->assertSame('confirmed', $booking->refresh()->status);

        $this->postJson($url, ['to' => 'arrived'])->assertOk()->assertJsonPath('status', 'arrived');
        $this->assertNotNull($booking->refresh()->arrived_at);
        Mail::assertSent(CustomerBookingUpdate::class, 1);

        // The same button pressed on a board that is out of date does not skip a step
        $this->postJson($url, ['to' => 'arrived'])->assertUnprocessable();
        $this->assertSame('arrived', $booking->refresh()->status);
    }

    public function test_assigned_to_is_trimmed_cleared_and_limited(): void
    {
        $booking = $this->unit(['status' => 'arrived']);
        $url = self::QUEUE."/{$booking->id}/assigned-to";
        $this->actingAs($this->crew());

        $this->postJson($url, ['assignedTo' => '  Jun, Ben '])->assertOk()->assertJsonPath('assignedTo', 'Jun, Ben');
        $this->assertSame('Jun, Ben', $booking->refresh()->assigned_to);
        $this->postJson($url, ['assignedTo' => str_repeat('a', 121)])->assertUnprocessable()->assertJsonValidationErrorFor('assignedTo');
        $this->postJson($url, [])->assertUnprocessable();
        $this->postJson($url, ['assignedTo' => ''])->assertOk()->assertJsonPath('assignedTo', null);

        $booking->update(['status' => 'exited']);
        $this->postJson($url, ['assignedTo' => 'Jun'])->assertUnprocessable()->assertJsonPath('message', 'This unit is already closed.');
    }
}
