<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\ReferralSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsShop;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Manual transaction: bookings.sales at a branch, like the
 * Filament page.
 */
class ConsoleManualTransactionTest extends TestCase
{
    use BuildsShop, RefreshDatabase, StaffApi;

    private const MANUAL = '/console/api/sales/manual-transaction';

    private Branch $sanMateo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'Asia/Manila'));
        $this->buildShop();
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);
        Mail::fake();
    }

    private function sales(): User
    {
        $user = $this->staffUser('sales_admin', $this->branch);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $over = []): array
    {
        return [
            'branchId' => $this->branch->id,
            'newCustomer' => true,
            'name' => 'Walk In',
            'phone' => '0917 222 3333',
            'serviceDate' => '2026-11-15',
            'status' => 'exited',
            'vehicleYear' => 2020,
            'vehicleModel' => 'Honda City',
            'vehicleType' => 'sedan',
            'plateNumber' => 'def 456',
            'vehicleSizeId' => $this->medium->id,
            'services' => [$this->detail->id],
            'prices' => [$this->detail->id => 2400],
            ...$over,
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return ['form' => ['getJson', ''], 'rates' => ['getJson', '/rates'], 'customers' => ['getJson', '/customers'], 'save' => ['postJson', '']];
    }

    private function callEndpoint(string $method, string $path): TestResponse
    {
        return $method === 'getJson' ? $this->getJson(self::MANUAL.$path) : $this->postJson(self::MANUAL.$path, $this->form());
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->callEndpoint($method, $path)->assertUnauthorized();
        $this->assertSame(0, Booking::count());
    }

    #[DataProvider('endpoints')]
    public function test_without_sales_or_without_a_branch_is_403(string $method, string $path): void
    {
        $this->actingAs($this->staffUser('operations', $this->branch));
        $this->{$method}(str_replace('sales', 'operations', self::MANUAL).$path)->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'sales_admin']));
        $this->callEndpoint($method, $path)->assertForbidden();
        $this->assertSame(0, Booking::count());
    }

    public function test_the_form_offers_their_branch_its_services_and_the_rates(): void
    {
        $this->sales();
        Guest::factory()->walkIn()->create(['name' => 'Ana Mine', 'branch_id' => $this->branch->id]);
        Guest::factory()->walkIn()->create(['name' => 'Ana Theirs', 'branch_id' => $this->sanMateo->id]);

        $this->getJson(self::MANUAL."?branch={$this->branch->id}")
            ->assertOk()
            ->assertJsonPath('branches.*.label', ['Cainta'])
            ->assertJsonCount(2, 'services')
            ->assertJsonPath('statuses.0', ['value' => 'confirmed', 'label' => 'Booked'])
            ->assertJsonPath('referralHint', null);
        $this->getJson(self::MANUAL."?branch={$this->sanMateo->id}")->assertJsonPath('services', []);
        $this->getJson(self::MANUAL."/rates?size={$this->medium->id}&services[]={$this->detail->id}&services[]={$this->coating->id}")
            ->assertJsonPath('data.*.price', [2500, 18000]);
        $this->getJson(self::MANUAL.'/customers?search=ana')->assertJsonCount(1, 'data')->assertJsonPath('data.0.label', fn (string $label) => str_starts_with($label, 'Ana Mine'));
    }

    public function test_a_job_is_saved_as_a_manual_booking(): void
    {
        $this->sales();

        $id = $this->postJson(self::MANUAL, $this->form(['discount' => 400, 'amountPaid' => 2000, 'paymentMethod' => 'cash', 'paymentReference' => 'OR-1']))
            ->assertCreated()
            ->assertJsonPath('message', fn (string $message) => str_ends_with($message, '₱2,000'))
            ->json('id');

        $booking = Booking::findOrFail($id);
        $this->assertSame(['manual', 'exited', 2000, 2000, 'cash', 'DEF456'], [$booking->source, $booking->status, $booking->total_price, $booking->amount_paid, $booking->payment_method, $booking->plate_number]);
        Mail::assertNothingSent();

        $this->postJson(self::MANUAL, $this->form(['newCustomer' => false, 'guestId' => $booking->guest_id, 'name' => 'ignored']))->assertCreated();
        $this->assertSame(1, Guest::count());
    }

    public function test_what_the_form_refuses(): void
    {
        $theirs = Guest::factory()->walkIn()->create(['branch_id' => $this->sanMateo->id]);
        $this->sales();

        $this->postJson(self::MANUAL, ['newCustomer' => true])->assertUnprocessable()->assertJsonValidationErrors(['branchId', 'name', 'phone', 'serviceDate', 'status', 'vehicleYear', 'vehicleModel', 'vehicleType', 'plateNumber', 'vehicleSizeId', 'services']);
        $this->postJson(self::MANUAL, $this->form(['newCustomer' => false]))->assertUnprocessable()->assertJsonValidationErrors(['guestId']);
        $this->postJson(self::MANUAL, $this->form(['newCustomer' => false, 'guestId' => $theirs->id]))->assertUnprocessable()->assertJsonValidationErrors(['guestId']);
        $this->postJson(self::MANUAL, $this->form(['branchId' => $this->sanMateo->id]))->assertUnprocessable()->assertJsonValidationErrors(['branchId']);
        $this->postJson(self::MANUAL, $this->form(['prices' => []]))->assertUnprocessable()->assertJsonValidationErrors(["prices.{$this->detail->id}"]);
        $this->postJson(self::MANUAL, $this->form(['prices' => [$this->detail->id => -1], 'amountPaid' => 100, 'discount' => 10_000_001]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(["prices.{$this->detail->id}", 'paymentMethod', 'discount']);
        $this->assertSame(0, Booking::count());
    }

    public function test_a_bad_referral_code_is_on_its_field(): void
    {
        ReferralSetting::instance()->update(['enabled' => true, 'discount_type' => 'amount', 'discount_value' => 100, 'owner_points' => 5]);
        $this->assertTrue(ReferralSetting::current()->isActive());
        $this->sales();

        $this->postJson(self::MANUAL, $this->form(['referralCode' => 'NOPE-0000']))->assertUnprocessable()->assertJsonValidationErrors(['referralCode']);
        $this->assertSame(0, Booking::count());
    }
}
