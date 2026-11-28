<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\User;
use App\Support\ConsoleUrl;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React dashboard: the same widgets per panel and role as Filament's, its
 * filters, links into the new screens and the CSV.
 */
class ConsoleDashboardTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private Branch $cainta;

    private Branch $sanMateo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);

        foreach ([[$this->cainta, 5000, 'Maria Santos'], [$this->sanMateo, 3000, 'Jun Reyes']] as [$branch, $price, $name]) {
            Booking::factory()->priced()->status('exited')->on('2026-11-10')->create([
                'branch_id' => $branch->id,
                'guest_id' => Guest::factory()->create(['name' => $name])->id,
                'exited_at' => '2026-11-10 15:00:00',
                'total_price' => $price,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function widgets(string $panel, string $query = ''): array
    {
        return array_column($this->getJson("/console/api/{$panel}/dashboard{$query}")->assertOk()->json('widgets'), 'key');
    }

    public function test_signed_out_is_401(): void
    {
        $this->getJson('/console/api/owner/dashboard')->assertUnauthorized();
        $this->getJson('/console/api/owner/dashboard/export')->assertUnauthorized();
    }

    public function test_without_the_dashboard_area_it_is_403(): void
    {
        $this->actingAs($this->staffUser('operations', $this->cainta, ['permissions' => ['bookings.view']]));

        $this->getJson('/console/api/operations/dashboard')->assertForbidden();
        $this->getJson('/console/api/operations/dashboard/export')->assertForbidden();
        // A panel the role does not have, and one with no dashboard
        $this->getJson('/console/api/owner/dashboard')->assertForbidden();
        $this->actingAs(User::factory()->owner()->create());
        $this->getJson('/console/api/system/dashboard')->assertNotFound();
    }

    public function test_each_panel_and_role_gets_its_own_widgets(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->assertSame(['today', 'attention', 'sales', 'chart', 'services', 'branches', 'operations', 'customers', 'staff'], $this->widgets('owner'));
        $this->assertSame(['today', 'attention', 'sales', 'chart', 'services', 'customers'], $this->widgets('sales'));

        $this->actingAs($this->staffUser('operations', $this->cainta));
        $this->assertSame(['today', 'attention', 'operations', 'customers'], $this->widgets('operations'));
        $this->getJson('/console/api/operations/dashboard')->assertJsonPath('canExport', false)->assertJsonPath('filters.branches', []);
        $this->getJson('/console/api/operations/dashboard/export')->assertForbidden();
    }

    public function test_the_numbers_follow_the_filters_and_the_viewers_branches(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $sales = fn (string $query) => collect($this->getJson("/console/api/owner/dashboard{$query}")->json('widgets'))->keyBy('key');

        $all = $sales('');
        $this->assertSame('₱8,000', $all['sales']['stats'][0]['value']);
        $this->assertSame(8000, array_sum($all['chart']['sales']));
        $this->assertSame('₱3,000', $sales("?branch={$this->sanMateo->id}")['sales']['stats'][0]['value']);
        $this->assertSame('No finished jobs in this period.', $sales('?period=today')['sales']['description']);
        $this->assertSame(0, $sales('?period=custom&from=2026-11-01&to=2026-11-05')['chart']['units']);
        // Not a filter the page offers: ignored
        $this->assertSame('₱8,000', $sales('?period[]=x&branch=abc')['sales']['stats'][0]['value']);

        $manager = $this->staffUser('branch_manager', $this->cainta);
        $this->actingAs($manager);
        $response = $this->getJson("/console/api/operations/dashboard?branch={$this->sanMateo->id}")->assertOk();
        $this->assertStringNotContainsString('Jun Reyes', $response->getContent());
        $this->assertStringNotContainsString('San Mateo', $response->getContent());
    }

    public function test_cards_lead_to_the_new_screens(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $widgets = collect($this->getJson('/console/api/sales/dashboard')->json('widgets'))->keyBy('key');

        $this->assertSame(url('console/sales/bookings').'?tab=action', $widgets['today']['stats'][2]['url']);
        $this->assertStringStartsWith(url('console/'), $widgets['attention']['stats'][0]['url']);

        $this->assertSame(url('console/sales/bookings/5'), ConsoleUrl::from(url('sales/bookings/5')));
        $this->assertSame(url('console/operations/inbox').'?conversation=3', ConsoleUrl::from(url('operations/inbox?conversation=3')));
        $this->assertSame('https://elsewhere.test/page', ConsoleUrl::from('https://elsewhere.test/page'));
        $this->assertNull(ConsoleUrl::from('javascript:alert(1)'));
        $this->assertNull(ConsoleUrl::from('//elsewhere.test'));
    }

    public function test_the_export_is_the_daily_sales_as_a_csv(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $response = $this->get("/console/api/owner/dashboard/export?period=custom&from=2026-11-10&to=2026-11-10&branch={$this->cainta->id}")
            ->assertOk()
            ->assertDownload('826-daily-sales-2026-11-10-to-2026-11-10-cainta.csv');

        $this->assertStringContainsString('2026-11-10,1,5000', $response->streamedContent());
    }
}
