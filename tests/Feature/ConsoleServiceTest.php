<?php

namespace Tests\Feature;

use App\Models\BackjobType;
use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceRate;
use App\Models\User;
use App\Models\VehicleSize;
use App\Support\ImagePipeline;
use App\Support\UploadRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Shop → Services endpoints: ServicePolicy, the Filament
 * form's rules, the price grid and the picture.
 */
class ConsoleServiceTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const SERVICES = '/console/api/owner/services';

    private ServiceCategory $coating;

    private Service $graphene;

    private VehicleSize $small;

    private VehicleSize $large;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->coating = ServiceCategory::factory()->create(['name' => 'Coating']);
        $this->graphene = Service::factory()->create(['service_category_id' => $this->coating->id, 'name' => 'Graphene', 'slug' => 'graphene', 'sort_order' => 1, 'estimate_days' => 3]);
        $this->small = VehicleSize::factory()->create(['name' => 'Small', 'sort_order' => 1]);
        $this->large = VehicleSize::factory()->create(['name' => 'Large', 'sort_order' => 2, 'is_active' => false]);
        ServiceRate::factory()->create(['service_id' => $this->graphene->id, 'vehicle_size_id' => $this->small->id, 'price' => 2500]);
    }

    private function owner(): static
    {
        return $this->actingAs(User::factory()->owner()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return [
            'name' => 'Headlight restoration', 'serviceCategoryId' => $this->coating->id, 'slug' => 'headlight-restoration', 'summary' => 'Clear again',
            'estimateDays' => '', 'warrantyMonths' => 6, 'backjobTypes' => [], 'description' => '', 'inclusions' => ['Wet sanding', 'Sealant'],
            'isActive' => true, 'homeServiceOk' => false, 'sortOrder' => 0, 'rates' => [$this->small->id => 1500, $this->large->id => ''], 'branches' => [],
            ...$changes,
        ];
    }

    private function send(string $url, array $data): TestResponse
    {
        return $this->post($url, $data, ['Accept' => 'application/json']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return [
            'list' => ['getJson', ''],
            'blank form' => ['getJson', '/form'],
            'one' => ['getJson', '/{id}'],
            'create' => ['postJson', ''],
            'update' => ['postJson', '/{id}'],
            'delete' => ['deleteJson', '/{id}'],
            'reorder' => ['postJson', '/reorder'],
        ];
    }

    private function callEndpoint(string $method, string $url): TestResponse
    {
        $url = str_replace('{id}', (string) $this->graphene->id, $url);
        $body = [...$this->form(['name' => 'Changed']), 'ids' => [$this->graphene->id]];

        return $method === 'getJson' ? $this->getJson($url) : $this->{$method}($url, $body);
    }

    private function assertUntouched(): void
    {
        $this->assertSame(['Graphene'], Service::query()->pluck('name')->all());
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->callEndpoint($method, self::SERVICES.$path)->assertUnauthorized();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_role_without_the_owner_panel_is_403(string $method, string $path): void
    {
        $this->actingAs($this->staffUser('branch_manager', Branch::factory()->create(), ['permissions' => ['shop']]));

        $this->callEndpoint($method, self::SERVICES.$path)->assertForbidden();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_panel_that_does_not_show_them_is_404(string $method, string $path): void
    {
        $this->owner();

        $this->callEndpoint($method, str_replace('owner', 'sales', self::SERVICES).$path)->assertNotFound();
        $this->assertUntouched();
    }

    public function test_the_list_says_days_prices_and_branches(): void
    {
        $this->graphene->branches()->attach(Branch::factory()->create());
        $this->owner();

        $this->getJson(self::SERVICES)
            ->assertOk()
            ->assertJsonPath('data.0.category', 'Coating')
            ->assertJsonPath('data.0.takes', '3 days')
            ->assertJsonPath('data.0.prices', '₱2,500')
            ->assertJsonPath('data.0.priced', 1)
            ->assertJsonPath('data.0.sizes', 1)
            ->assertJsonPath('data.0.branches', 1)
            ->assertJsonPath('categories.0.label', 'Coating');

        $this->getJson(self::SERVICES."/{$this->graphene->id}")
            ->assertOk()
            ->assertJsonPath('values.rates', [$this->small->id => 2500, $this->large->id => null])
            ->assertJsonPath('options.sizes.1.label', 'Large (hidden)');
        $this->getJson(self::SERVICES.'/999999')->assertNotFound();
    }

    public function test_a_service_is_added_with_prices_lines_branches_and_a_webp_picture(): void
    {
        $branch = Branch::factory()->create();
        $peeling = BackjobType::query()->create(['name' => 'Peeling']);
        $this->owner();

        $id = $this->send(self::SERVICES, $this->form([
            'isActive' => '1', 'homeServiceOk' => '0', 'branches' => [$branch->id], 'backjobTypes' => [$peeling->id],
            'image' => UploadedFile::fake()->image('headlight.jpg', 900, 600),
        ]))->assertCreated()->json('id');

        $service = Service::query()->findOrFail($id);
        $this->assertNull($service->estimate_days);
        $this->assertSame(['Wet sanding', 'Sealant'], $service->inclusionList());
        $this->assertSame([1500], $service->rates()->pluck('price')->all());
        $this->assertSame([$branch->id], $service->branches()->pluck('branches.id')->all());
        $this->assertSame([$peeling->id], $service->backjobTypes()->pluck('warranty_backjob_types.id')->all());
        $this->assertStringStartsWith('services/', $service->image);
        $this->assertStringEndsWith('.webp', $service->image);
        Storage::disk('public')->assertExists([$service->image, ImagePipeline::renditionPath($service->image, 'thumb')]);
    }

    public function test_an_update_changes_prices_and_replaces_or_removes_the_picture(): void
    {
        $this->owner();
        $url = self::SERVICES."/{$this->graphene->id}";
        $form = $this->form(['name' => 'Graphene coating', 'slug' => 'graphene', 'rates' => [$this->small->id => '', $this->large->id => 4000]]);

        $this->send($url, [...$form, 'isActive' => '1', 'homeServiceOk' => '1', 'image' => UploadedFile::fake()->image('a.png', 300, 200)])->assertOk();
        $first = $this->graphene->fresh()->image;
        $this->assertSame([$this->large->id => 4000], $this->graphene->rates()->pluck('price', 'vehicle_size_id')->all());

        // Saving again with no new file keeps the picture
        $this->postJson($url, $form)->assertOk();
        $this->assertSame($first, $this->graphene->fresh()->image);

        $this->postJson($url, [...$form, 'removeImage' => true])->assertOk()->assertJsonPath('imageUrl', null);
        Storage::disk('public')->assertMissing([$first, ImagePipeline::renditionPath($first, 'thumb')]);
    }

    public function test_what_the_form_refuses(): void
    {
        $this->owner();

        $this->postJson(self::SERVICES, [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'slug', 'serviceCategoryId', 'isActive', 'homeServiceOk']);

        $this->postJson(self::SERVICES, $this->form([
            'serviceCategoryId' => 999999, 'slug' => 'graphene', 'summary' => str_repeat('a', 161), 'estimateDays' => 61, 'warrantyMonths' => 0,
            'inclusions' => ['ok', ''], 'rates' => [$this->small->id => -1], 'branches' => [999999], 'backjobTypes' => [999999],
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'serviceCategoryId', 'slug', 'summary', 'estimateDays', 'warrantyMonths', 'inclusions.1' => 'Write the line, or remove it.', "rates.{$this->small->id}", 'branches.0', 'backjobTypes.0',
        ]);

        $this->assertUntouched();
    }

    #[DataProvider('badFiles')]
    public function test_the_picture_must_be_a_jpg_png_or_webp_up_to_20_mb(string $name, int $kb, string $mime, string $message): void
    {
        $this->owner();

        $this->send(self::SERVICES, $this->form(['isActive' => '1', 'homeServiceOk' => '0', 'image' => UploadedFile::fake()->create($name, $kb, $mime)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image' => $message]);

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertUntouched();
    }

    /**
     * @return array<string, array{string, int, string, string}>
     */
    public static function badFiles(): array
    {
        return [
            'svg' => ['a.svg', 1, 'image/svg+xml', 'Use a JPG, PNG or WebP picture.'],
            'gif' => ['a.gif', 1, 'image/gif', 'Use a JPG, PNG or WebP picture.'],
            'over 20 MB' => ['a.jpg', UploadRules::PHOTO_MAX_KB + 1, 'image/jpeg', 'Keep the picture under 20 MB.'],
        ];
    }

    public function test_delete_and_order(): void
    {
        $other = Service::factory()->create(['name' => 'Tint', 'sort_order' => 2]);
        $this->owner();

        $this->postJson(self::SERVICES.'/reorder', ['ids' => [$other->id, $this->graphene->id]])->assertNoContent();
        $this->assertSame(['Tint', 'Graphene'], Service::query()->ordered()->pluck('name')->all());

        $this->deleteJson(self::SERVICES."/{$this->graphene->id}")->assertNoContent();
        $this->assertDatabaseMissing('service_rates', ['service_id' => $this->graphene->id]);
        $this->assertSame(['Tint'], Service::query()->pluck('name')->all());
    }
}
