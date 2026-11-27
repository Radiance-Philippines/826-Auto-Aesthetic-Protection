<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\User;
use App\Models\VehicleSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Shop → Vehicle sizes endpoints, under VehicleSizePolicy
 * (the shop area) in the one panel that shows them.
 */
class ConsoleVehicleSizeTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const SIZES = '/console/api/owner/vehicle-sizes';

    private VehicleSize $small;

    private VehicleSize $medium;

    protected function setUp(): void
    {
        parent::setUp();

        $this->small = VehicleSize::factory()->create(['name' => 'Small', 'sort_order' => 1]);
        $this->medium = VehicleSize::factory()->create(['name' => 'Medium', 'sort_order' => 2, 'examples' => 'Vios, City']);
    }

    private function owner(): static
    {
        return $this->actingAs(User::factory()->owner()->create());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return [
            'list' => ['getJson', ''],
            'create' => ['postJson', ''],
            'update' => ['patchJson', '/{id}'],
            'delete' => ['deleteJson', '/{id}'],
            'reorder' => ['postJson', '/reorder'],
        ];
    }

    private function callEndpoint(string $method, string $url): TestResponse
    {
        $url = str_replace('{id}', (string) $this->small->id, $url);
        $body = ['name' => 'Huge', 'isActive' => true, 'ids' => [$this->medium->id, $this->small->id]];

        return $method === 'getJson' ? $this->getJson($url) : $this->{$method}($url, $body);
    }

    private function assertUntouched(): void
    {
        $this->assertSame(['Small', 'Medium'], VehicleSize::query()->ordered()->pluck('name')->all());
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->callEndpoint($method, self::SIZES.$path)->assertUnauthorized();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_role_without_the_owner_panel_is_403(string $method, string $path): void
    {
        $this->actingAs($this->staffUser('branch_manager', Branch::factory()->create(), ['permissions' => ['shop']]));

        $this->callEndpoint($method, self::SIZES.$path)->assertForbidden();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_panel_that_does_not_show_them_is_404(string $method, string $path): void
    {
        $this->owner();

        $this->callEndpoint($method, str_replace('owner', 'operations', self::SIZES).$path)->assertNotFound();
        $this->assertUntouched();
    }

    public function test_the_list_is_in_order_with_prices_counted(): void
    {
        ServiceRate::factory()->create(['service_id' => Service::factory()->create()->id, 'vehicle_size_id' => $this->medium->id, 'price' => 2500]);
        $this->owner();

        $this->getJson(self::SIZES)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => [
                ['id' => $this->small->id, 'name' => 'Small', 'examples' => null, 'isActive' => true, 'pricesSet' => 0],
                ['id' => $this->medium->id, 'name' => 'Medium', 'examples' => 'Vios, City', 'isActive' => true, 'pricesSet' => 1],
            ]]);
    }

    public function test_a_size_is_added_changed_and_deleted_with_its_prices(): void
    {
        $this->owner();

        $id = $this->postJson(self::SIZES, ['name' => '  Large ', 'examples' => '', 'isActive' => false])
            ->assertCreated()
            ->assertJsonPath('name', 'Large')
            ->assertJsonPath('examples', null)
            ->assertJsonPath('isActive', false)
            ->json('id');

        $this->patchJson(self::SIZES."/{$id}", ['name' => 'XL', 'examples' => 'Hiace', 'isActive' => true])->assertOk()->assertJsonPath('examples', 'Hiace');
        $this->assertSame(['XL', 'Hiace', true], array_values(VehicleSize::query()->findOrFail($id)->only('name', 'examples', 'is_active')));

        ServiceRate::factory()->create(['service_id' => Service::factory()->create()->id, 'vehicle_size_id' => $id, 'price' => 3000]);
        $this->deleteJson(self::SIZES."/{$id}")->assertNoContent();
        $this->assertNull(VehicleSize::query()->find($id));
        $this->assertDatabaseMissing('service_rates', ['vehicle_size_id' => $id]);

        $this->deleteJson(self::SIZES."/{$id}")->assertNotFound();
    }

    public function test_what_the_form_refuses(): void
    {
        $this->owner();

        $this->postJson(self::SIZES, [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'isActive']);
        $this->postJson(self::SIZES, ['name' => str_repeat('a', 61), 'examples' => str_repeat('b', 161), 'isActive' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Keep the name under 60 characters.', 'examples' => 'Keep the examples under 160 characters.']);
        $this->patchJson(self::SIZES."/{$this->small->id}", ['name' => '', 'isActive' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'isActive']);
        $this->assertUntouched();
    }

    public function test_the_order_is_saved_from_the_whole_list_only(): void
    {
        $this->owner();

        $this->postJson(self::SIZES.'/reorder', ['ids' => [$this->medium->id, $this->small->id]])->assertNoContent();
        $this->assertSame(['Medium', 'Small'], VehicleSize::query()->ordered()->pluck('name')->all());
        $this->assertSame([1, 2], VehicleSize::query()->ordered()->pluck('sort_order')->all());

        foreach ([[$this->small->id], [$this->small->id, $this->small->id], [$this->small->id, 999999], ['a', 'b'], []] as $ids) {
            $this->postJson(self::SIZES.'/reorder', ['ids' => $ids])->assertUnprocessable();
        }

        $this->assertSame(['Medium', 'Small'], VehicleSize::query()->ordered()->pluck('name')->all());
    }
}
