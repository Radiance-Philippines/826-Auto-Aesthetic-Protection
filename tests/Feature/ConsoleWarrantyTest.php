<?php

namespace Tests\Feature;

use App\Models\Backjob;
use App\Models\BackjobType;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsShop;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Warranty endpoints: the warranty area and the person's
 * branches, like the Filament page.
 */
class ConsoleWarrantyTest extends TestCase
{
    use BuildsShop, RefreshDatabase, StaffApi;

    private const WARRANTY = '/console/api/operations/warranty';

    private Booking $job;

    private BackjobType $covered;

    private Branch $sanMateo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'Asia/Manila'));
        $this->buildShop();
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);
        $this->covered = BackjobType::create(['name' => 'Water spots', 'is_active' => true]);
        $this->detail->backjobTypes()->sync([$this->covered->id]);
        $this->job = $this->finished($this->branch, ['name' => 'Maria Santos', 'plate_number' => 'ABC1234', 'phone' => '0917 123 4567']);
    }

    private function finished(Branch $branch, array $state = []): Booking
    {
        $job = Booking::factory()->on('2026-11-02')->create(['branch_id' => $branch->id, 'status' => 'exited', 'exited_at' => '2026-11-02 12:00', ...$state]);
        $job->items()->delete();
        $job->items()->create(['service_id' => $this->detail->id, 'service_name' => 'Exterior detailing', 'duration_days' => 1, 'warranty_months' => 3]);

        return $job;
    }

    private function crew(): User
    {
        $user = $this->staffUser('operations', $this->branch);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $over = []): array
    {
        return ['bookingId' => $this->job->id, 'bookingItemId' => $this->job->items()->value('id'), 'backjobTypeId' => (string) $this->covered->id, 'notedOn' => '2026-11-16', 'note' => 'Hood', ...$over];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return ['search' => ['getJson', '?q=ABC'], 'log' => ['postJson', '/backjobs'], 'done' => ['postJson', '/backjobs/1/done']];
    }

    private function callEndpoint(string $method, string $path): TestResponse
    {
        return $method === 'getJson' ? $this->getJson(self::WARRANTY.$path) : $this->postJson(self::WARRANTY.$path, $this->form());
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->callEndpoint($method, $path)->assertUnauthorized();
        $this->assertSame(0, Backjob::count());
    }

    #[DataProvider('endpoints')]
    public function test_without_the_warranty_area_is_403(string $method, string $path): void
    {
        $this->actingAs($this->staffUser('operations', $this->branch, ['permissions' => ['bookings.view']]));

        $this->callEndpoint($method, $path)->assertForbidden();
        $this->assertSame(0, Backjob::count());
    }

    public function test_search_by_plate_name_phone_or_reference_in_their_branches_only(): void
    {
        $this->finished($this->sanMateo, ['name' => 'Maria Elsewhere', 'plate_number' => 'ABC9999']);
        $this->crew();

        $this->getJson(self::WARRANTY)->assertOk()->assertJson(['searched' => false, 'data' => [], 'guest' => null]);

        foreach (['abc 1234', 'maria', '+63 917 123 4567', $this->job->reference] as $term) {
            $this->getJson(self::WARRANTY.'?q='.urlencode($term))->assertJsonPath('data.*.id', [$this->job->id]);
        }

        $this->getJson(self::WARRANTY.'?q=ABC')
            ->assertJsonPath('data.0.items.0.status', 'active')
            ->assertJsonPath('data.0.items.0.ends', 'Feb 2, 2027')
            ->assertJsonPath('data.0.items.0.covers', ['Water spots'])
            ->assertJsonPath('data.0.items.0.types', [['value' => (string) $this->covered->id, 'label' => 'Water spots'], ['value' => 'other', 'label' => 'Other']]);
    }

    public function test_one_customers_jobs_and_another_branchs_customer_is_404(): void
    {
        $mine = Guest::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Mine']);
        $theirs = Guest::factory()->create(['branch_id' => $this->sanMateo->id]);
        $this->job->update(['guest_id' => $mine->id]);
        $this->crew();

        $this->getJson(self::WARRANTY."?customer={$mine->id}")->assertJsonPath('guest.name', 'Mine')->assertJsonPath('data.*.id', [$this->job->id]);
        $this->getJson(self::WARRANTY."?customer={$theirs->id}")->assertNotFound();
        $this->getJson(self::WARRANTY.'?customer=abc')->assertNotFound();
    }

    public function test_a_backjob_is_logged_and_marked_done(): void
    {
        $user = $this->crew();

        $id = $this->postJson(self::WARRANTY.'/backjobs', $this->form())->assertCreated()->json('id');
        $backjob = Backjob::findOrFail($id);
        $this->assertSame([$this->covered->id, 'open', 'Hood', $user->id, $this->branch->id], [$backjob->backjob_type_id, $backjob->status, $backjob->note, $backjob->created_by, $backjob->branch_id]);

        $this->postJson(self::WARRANTY.'/backjobs', $this->form(['backjobTypeId' => 'other', 'note' => null]))->assertCreated();
        $this->assertNull(Backjob::query()->latest('id')->first()->backjob_type_id);

        $this->getJson(self::WARRANTY.'?q=ABC')->assertJsonPath('data.0.backjobs.1.open', true);
        $this->postJson(self::WARRANTY."/backjobs/{$id}/done")->assertNoContent();
        $this->assertSame('done', $backjob->fresh()->status);
    }

    public function test_what_the_log_refuses(): void
    {
        $uncovered = BackjobType::create(['name' => 'Peeling', 'is_active' => true]);
        $other = $this->finished($this->branch);
        $this->crew();

        $this->postJson(self::WARRANTY.'/backjobs', ['bookingId' => $this->job->id])->assertUnprocessable()->assertJsonValidationErrors(['bookingItemId', 'backjobTypeId', 'notedOn']);
        $this->postJson(self::WARRANTY.'/backjobs', $this->form(['bookingItemId' => $other->items()->value('id')]))->assertUnprocessable()->assertJsonValidationErrors(['bookingItemId']);
        $this->postJson(self::WARRANTY.'/backjobs', $this->form(['backjobTypeId' => (string) $uncovered->id]))->assertUnprocessable()->assertJsonValidationErrors(['backjobTypeId']);
        $this->postJson(self::WARRANTY.'/backjobs', $this->form(['notedOn' => '2026-11-17']))->assertUnprocessable()->assertJsonValidationErrors(['notedOn']);
        $this->postJson(self::WARRANTY.'/backjobs', $this->form(['note' => str_repeat('a', 2001)]))->assertUnprocessable();
        $this->assertSame(0, Backjob::count());
    }

    public function test_another_branchs_job_and_backjob_are_404(): void
    {
        $theirs = $this->finished($this->sanMateo);
        $backjob = Backjob::create(['booking_id' => $theirs->id, 'booking_item_id' => $theirs->items()->value('id'), 'branch_id' => $this->sanMateo->id, 'noted_on' => '2026-11-10', 'status' => 'open']);
        $open = Booking::factory()->on('2026-11-17')->create(['branch_id' => $this->branch->id]);
        $this->crew();

        $this->postJson(self::WARRANTY.'/backjobs', $this->form(['bookingId' => $theirs->id]))->assertNotFound();
        $this->postJson(self::WARRANTY.'/backjobs', $this->form(['bookingId' => $open->id]))->assertNotFound();
        $this->postJson(self::WARRANTY."/backjobs/{$backjob->id}/done")->assertNotFound();
        $this->assertSame('open', $backjob->fresh()->status);
    }
}
