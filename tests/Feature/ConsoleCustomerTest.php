<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\Guest;
use App\Models\ReferralRedemption;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsShop;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Customers endpoints: GuestPolicy, the person's branches
 * and each action's own areas, like the Filament resource.
 */
class ConsoleCustomerTest extends TestCase
{
    use BuildsShop, RefreshDatabase, StaffApi;

    private const CUSTOMERS = '/console/api/sales/customers';

    private Branch $sanMateo;

    private Guest $maria;

    private Guest $elsewhere;

    private Vehicle $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'Asia/Manila'));
        $this->buildShop();
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);
        $this->maria = Guest::factory()->walkIn()->create(['name' => 'Maria Santos', 'phone' => '0917 123 4567', 'branch_id' => $this->branch->id]);
        $this->elsewhere = Guest::factory()->walkIn()->create(['name' => 'Far Away', 'branch_id' => $this->sanMateo->id]);
        $this->unit = Vehicle::factory()->create(['guest_id' => $this->maria->id, 'plate_number' => 'ABC1234']);
    }

    private function sales(): User
    {
        $user = $this->staffUser('sales_admin', $this->branch);
        $this->actingAs($user);

        return $user;
    }

    private function url(?Guest $guest = null, string $path = ''): string
    {
        return self::CUSTOMERS.'/'.($guest ?? $this->maria)->id.$path;
    }

    private function booking(array $state = []): Booking
    {
        return Booking::factory()->on('2026-11-20')->create(['branch_id' => $this->branch->id, 'guest_id' => $this->maria->id, 'vehicle_id' => $this->unit->id, ...$state]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return [
            'list' => ['getJson', ''],
            'form' => ['getJson', '/form'],
            'create' => ['postJson', ''],
            'view' => ['getJson', '/{id}'],
            'edit' => ['getJson', '/{id}/edit'],
            'update' => ['patchJson', '/{id}'],
            'delete' => ['deleteJson', '/{id}'],
            'redeem' => ['postJson', '/{id}/redeem'],
            'unit history' => ['getJson', '/{id}/units/{unit}'],
            'unit size' => ['postJson', '/{id}/units/{unit}/size'],
        ];
    }

    private function callEndpoint(string $method, string $path, Guest $guest): TestResponse
    {
        $url = self::CUSTOMERS.str_replace(['{id}', '{unit}'], [(string) $guest->id, (string) $this->unit->id], $path);

        return $method === 'getJson' ? $this->getJson($url) : $this->{$method}($url, ['name' => 'Changed', 'points' => 1, 'note' => 'x', 'vehicleSizeId' => $this->medium->id]);
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->callEndpoint($method, $path, $this->maria)->assertUnauthorized();
        $this->assertSame('Maria Santos', $this->maria->fresh()->name);
    }

    #[DataProvider('endpoints')]
    public function test_without_customers_view_is_403(string $method, string $path): void
    {
        $this->actingAs($this->staffUser('sales_admin', $this->branch, ['permissions' => ['bookings.view']]));

        $this->callEndpoint($method, $path, $this->maria)->assertForbidden();
        $this->assertSame(2, Guest::count());
        $this->assertSame('Maria Santos', $this->maria->fresh()->name);
    }

    public function test_another_branchs_customer_is_404(): void
    {
        $this->sales();

        foreach (self::endpoints() as [$method, $path]) {
            if (str_contains($path, '{id}')) {
                $this->callEndpoint($method, $path, $this->elsewhere)->assertNotFound();
            }
        }

        $this->getJson(self::CUSTOMERS)->assertJsonPath('data.*.name', ['Maria Santos']);
        $this->assertSame('Far Away', $this->elsewhere->fresh()->name);
    }

    public function test_the_list_searches_name_mobile_and_plate_and_filters(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->getJson(self::CUSTOMERS)
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Far Away', 'Maria Santos'])
            ->assertJsonPath('data.1.units', 1)
            ->assertJsonPath('data.1.bookings', 0)
            ->assertJsonPath('can.create', true)
            ->assertJsonCount(2, 'filters.branches');
        $this->getJson(self::CUSTOMERS.'?search=abc-1234')->assertJsonPath('data.*.name', ['Maria Santos']);
        $this->getJson(self::CUSTOMERS.'?search=santos+0917')->assertJsonPath('data.*.name', ['Maria Santos']);
        $this->getJson(self::CUSTOMERS."?branch={$this->sanMateo->id}")->assertJsonPath('data.*.name', ['Far Away']);
        $this->getJson(self::CUSTOMERS.'?source=google')->assertJsonPath('meta.total', 0);
        $this->getJson(self::CUSTOMERS.'?sort=name&desc=1')->assertJsonPath('data.0.name', 'Maria Santos');
    }

    public function test_view_gives_the_relation_lists_and_actions_by_area(): void
    {
        $booking = $this->booking();
        $friend = Guest::factory()->create(['name' => 'Friend']);
        ReferralRedemption::create(['code_guest_id' => $this->maria->id, 'used_by_guest_id' => $friend->id, 'booking_id' => $booking->id, 'points_awarded' => 5, 'awarded_at' => now()]);
        $this->sales();
        $chat = Conversation::create(['branch_id' => $this->branch->id, 'external_id' => 'x', 'guest_id' => $this->maria->id]);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('referral.points', 5)
            ->assertJsonPath('referral.timesUsed', 1)
            ->assertJsonPath('warranty', '0 services are covered now')
            ->assertJsonPath('can', ['edit' => true, 'delete' => false, 'warranty' => true])
            ->assertJsonPath('chatId', $chat->id)
            ->assertJsonPath('redeem.balance', 5)
            ->assertJsonPath('redeem.bookings', null)
            ->assertJsonPath('units.0.plate', 'ABC1234')
            ->assertJsonPath('units.0.jobs', 1)
            ->assertJsonPath('bookings.0.reference', $booking->reference)
            ->assertJsonPath('referrals.0.usedBy', 'Friend')
            ->assertJsonPath('referrals.0.bookingId', $booking->id)
            ->assertJsonPath('ledger.0.points', 5);

        // A detailer: looks, but no edit, points, chat or sizing
        $this->actingAs($this->staffUser('operations', $this->branch));
        $this->getJson(str_replace('sales', 'operations', $this->url()))
            ->assertOk()
            ->assertJsonPath('can.edit', false)
            ->assertJsonPath('chatId', null)
            ->assertJsonPath('redeem', null)
            ->assertJsonPath('setSize', null);

        foreach (['/redeem', "/units/{$this->unit->id}/size"] as $step) {
            $this->postJson(str_replace('sales', 'operations', $this->url(null, $step)), ['points' => 1, 'note' => 'x', 'vehicleSizeId' => $this->medium->id])->assertForbidden();
        }

        $this->patchJson(str_replace('sales', 'operations', $this->url()), ['name' => 'Nope'])->assertForbidden();
        $this->postJson(str_replace('sales', 'operations', self::CUSTOMERS), ['name' => 'Nope'])->assertForbidden();
    }

    public function test_a_walk_in_is_added_and_edited(): void
    {
        $this->sales();

        $this->getJson(self::CUSTOMERS.'/form')->assertJsonPath('branches.*.label', ['Cainta'])->assertJsonPath('can.create', true);
        $id = $this->postJson(self::CUSTOMERS, ['name' => 'Juan', 'phone' => '0918 000 1111', 'branchId' => $this->branch->id])->assertCreated()->json('id');
        $this->assertSame(['walk_in', $this->branch->id], array_values(Guest::findOrFail($id)->only('source', 'branch_id')));

        $this->patchJson(self::CUSTOMERS."/{$id}", ['name' => 'Juan Cruz', 'phone' => '', 'email' => 'juan@example.com', 'branchId' => $this->branch->id, 'notes' => 'VIP'])->assertOk();
        $this->assertSame(['Juan Cruz', null, 'juan@example.com', 'VIP'], array_values(Guest::findOrFail($id)->only('name', 'phone', 'email', 'notes')));

        $this->postJson(self::CUSTOMERS, ['phone' => 'abc', 'email' => 'no', 'branchId' => $this->sanMateo->id, 'salesRepId' => 999999, 'notes' => str_repeat('a', 3001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'phone', 'email', 'branchId', 'salesRepId', 'notes']);
    }

    public function test_a_home_branch_that_is_not_theirs_is_kept_but_cannot_be_picked(): void
    {
        $this->booking(['guest_id' => $this->elsewhere->id, 'vehicle_id' => null]);
        $this->sales();

        $this->patchJson($this->url($this->elsewhere), ['name' => 'Far Away', 'branchId' => $this->sanMateo->id])->assertOk();
        $this->patchJson($this->url(), ['name' => 'Maria Santos', 'branchId' => $this->sanMateo->id])->assertUnprocessable();
    }

    public function test_points_are_redeemed_within_the_balance(): void
    {
        ReferralSetting::instance()->update(['peso_per_point' => 10]);
        $booking = $this->booking(['priced_at' => now(), 'total_price' => 1000]);
        ReferralRedemption::create(['code_guest_id' => $this->maria->id, 'used_by_guest_id' => $this->elsewhere->id, 'booking_id' => $this->booking()->id, 'points_awarded' => 20, 'awarded_at' => now()]);
        $this->sales();

        $this->getJson($this->url())->assertJsonPath('redeem.bookings.0.value', (string) $booking->id);
        $this->postJson($this->url(null, '/redeem'), ['points' => 21])->assertUnprocessable()->assertJsonValidationErrors(['points']);
        $this->postJson($this->url(null, '/redeem'), ['points' => 5])->assertUnprocessable()->assertJsonValidationErrors(['note']);
        $this->postJson($this->url(null, '/redeem'), ['points' => 5, 'bookingId' => 999999])->assertUnprocessable()->assertJsonValidationErrors(['bookingId']);
        $this->postJson($this->url(null, '/redeem'), ['points' => 5, 'bookingId' => $booking->id])->assertCreated()->assertExactJson(['points' => 5, 'off' => '₱50 off the booking']);
        $this->assertSame(950, $booking->fresh()->total_price);
        $this->assertSame(15, $this->maria->fresh()->pointsBalance());
    }

    public function test_a_unit_is_sized_and_its_history_is_their_branches(): void
    {
        $here = $this->booking();
        $this->booking(['branch_id' => $this->sanMateo->id]);
        $other = Vehicle::factory()->create(['guest_id' => $this->elsewhere->id]);
        $this->sales();

        $this->getJson($this->url(null, "/units/{$this->unit->id}"))->assertOk()->assertJsonPath('data.*.reference', [$here->reference]);
        $this->getJson($this->url(null, "/units/{$other->id}"))->assertNotFound();
        $this->postJson($this->url(null, "/units/{$other->id}/size"), ['vehicleSizeId' => $this->medium->id])->assertNotFound();
        $this->postJson($this->url(null, "/units/{$this->unit->id}/size"), [])->assertUnprocessable()->assertJsonValidationErrors(['vehicleSizeId']);
        $this->postJson($this->url(null, "/units/{$this->unit->id}/size"), ['vehicleSizeId' => $this->medium->id])->assertOk()->assertJsonPath('size', 'Medium');
        $this->assertSame($this->medium->id, $this->unit->fresh()->vehicle_size_id);
    }

    public function test_only_a_super_admin_deletes_and_never_a_customer_with_bookings(): void
    {
        $this->booking();
        $this->sales();
        $this->deleteJson($this->url())->assertForbidden();

        $this->actingAs(User::factory()->owner()->create());
        $this->deleteJson($this->url())->assertUnprocessable()->assertJsonPath('errors.customer.0', 'This customer has bookings. Keep them, or move the bookings first.');
        $this->deleteJson($this->url($this->elsewhere))->assertNoContent();
        $this->assertNull(Guest::find($this->elsewhere->id));
        $this->assertNotNull(Guest::find($this->maria->id));
    }
}
