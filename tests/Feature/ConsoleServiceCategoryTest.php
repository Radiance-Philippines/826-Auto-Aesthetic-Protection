<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Shop → Categories endpoints, under ServiceCategoryPolicy.
 */
class ConsoleServiceCategoryTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const CATEGORIES = '/console/api/owner/service-categories';

    private ServiceCategory $coating;

    private ServiceCategory $tint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coating = ServiceCategory::factory()->create(['name' => 'Coating', 'slug' => 'coating', 'sort_order' => 1]);
        $this->tint = ServiceCategory::factory()->create(['name' => 'Tint', 'slug' => 'tint', 'sort_order' => 2]);
        Service::factory()->create(['service_category_id' => $this->coating->id]);
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
        $url = str_replace('{id}', (string) $this->tint->id, $url);
        $body = ['name' => 'Film', 'slug' => 'film', 'isActive' => true, 'ids' => [$this->tint->id, $this->coating->id]];

        return $method === 'getJson' ? $this->getJson($url) : $this->{$method}($url, $body);
    }

    private function assertUntouched(): void
    {
        $this->assertSame(['Coating', 'Tint'], ServiceCategory::query()->ordered()->pluck('name')->all());
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->callEndpoint($method, self::CATEGORIES.$path)->assertUnauthorized();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_role_without_the_owner_panel_is_403(string $method, string $path): void
    {
        $this->actingAs($this->staffUser('sales_admin', Branch::factory()->create(), ['permissions' => ['shop']]));

        $this->callEndpoint($method, self::CATEGORIES.$path)->assertForbidden();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_panel_that_does_not_show_them_is_404(string $method, string $path): void
    {
        $this->owner();

        $this->callEndpoint($method, str_replace('owner', 'operations', self::CATEGORIES).$path)->assertNotFound();
        $this->assertUntouched();
    }

    public function test_a_category_is_listed_added_changed_and_deleted(): void
    {
        $this->owner();

        $this->getJson(self::CATEGORIES)->assertOk()->assertJsonPath('data.0.services', 1)->assertJsonPath('data.1.name', 'Tint');

        $id = $this->postJson(self::CATEGORIES, ['name' => 'Film', 'slug' => 'film', 'description' => '', 'isActive' => false])
            ->assertCreated()
            ->assertJsonPath('description', null)
            ->assertJsonPath('isActive', false)
            ->json('id');
        $this->patchJson(self::CATEGORIES."/{$id}", ['name' => 'Films', 'slug' => 'film', 'description' => 'XPEL', 'isActive' => true])->assertOk()->assertJsonPath('name', 'Films');
        $this->deleteJson(self::CATEGORIES."/{$id}")->assertNoContent();
        $this->assertNull(ServiceCategory::query()->find($id));
    }

    public function test_a_category_with_services_stays(): void
    {
        $this->owner();

        $this->deleteJson(self::CATEGORIES."/{$this->coating->id}")->assertForbidden();
        $this->assertModelExists($this->coating);
    }

    public function test_what_the_form_refuses_and_the_order(): void
    {
        $this->owner();

        $this->postJson(self::CATEGORIES, [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'slug', 'isActive']);
        $this->postJson(self::CATEGORIES, ['name' => str_repeat('a', 81), 'slug' => 'coating', 'description' => str_repeat('b', 1001), 'isActive' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'slug', 'description']);
        $this->patchJson(self::CATEGORIES."/{$this->tint->id}", ['name' => 'Tint', 'slug' => 'two words', 'isActive' => true])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->assertUntouched();

        $this->postJson(self::CATEGORIES.'/reorder', ['ids' => [$this->tint->id, $this->coating->id]])->assertNoContent();
        $this->assertSame(['Tint', 'Coating'], ServiceCategory::query()->ordered()->pluck('name')->all());
        $this->postJson(self::CATEGORIES.'/reorder', ['ids' => [$this->tint->id]])->assertUnprocessable();
    }
}
