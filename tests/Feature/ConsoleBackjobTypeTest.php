<?php

namespace Tests\Feature;

use App\Models\BackjobType;
use App\Models\Branch;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Shop → Backjob types endpoints, under BackjobTypePolicy.
 */
class ConsoleBackjobTypeTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const TYPES = '/console/api/owner/backjob-types';

    private BackjobType $peeling;

    private BackjobType $bubbling;

    protected function setUp(): void
    {
        parent::setUp();

        $this->peeling = BackjobType::query()->create(['name' => 'Peeling', 'sort_order' => 1]);
        $this->bubbling = BackjobType::query()->create(['name' => 'Bubbling', 'sort_order' => 2]);
        $this->peeling->services()->attach(Service::factory()->create());
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
        $url = str_replace('{id}', (string) $this->peeling->id, $url);
        $body = ['name' => 'Scratch', 'isActive' => true, 'ids' => [$this->bubbling->id, $this->peeling->id]];

        return $method === 'getJson' ? $this->getJson($url) : $this->{$method}($url, $body);
    }

    private function assertUntouched(): void
    {
        $this->assertSame(['Peeling', 'Bubbling'], BackjobType::query()->ordered()->pluck('name')->all());
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->callEndpoint($method, self::TYPES.$path)->assertUnauthorized();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_role_without_the_owner_panel_is_403(string $method, string $path): void
    {
        $this->actingAs($this->staffUser('operations', Branch::factory()->create(), ['permissions' => ['shop']]));

        $this->callEndpoint($method, self::TYPES.$path)->assertForbidden();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_panel_that_does_not_show_them_is_404(string $method, string $path): void
    {
        $this->owner();

        $this->callEndpoint($method, str_replace('owner', 'sales', self::TYPES).$path)->assertNotFound();
        $this->assertUntouched();
    }

    public function test_a_type_is_listed_added_changed_ordered_and_deleted(): void
    {
        $this->owner();

        $this->getJson(self::TYPES)->assertOk()->assertJsonPath('data.0.services', 1)->assertJsonPath('data.1.name', 'Bubbling');
        $this->postJson(self::TYPES, [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'isActive']);
        $this->postJson(self::TYPES, ['name' => str_repeat('a', 121), 'isActive' => true])->assertUnprocessable()->assertJsonValidationErrors('name');

        $id = $this->postJson(self::TYPES, ['name' => 'Scratch', 'isActive' => false])->assertCreated()->assertJsonPath('isActive', false)->json('id');
        $this->patchJson(self::TYPES."/{$id}", ['name' => 'Scratches', 'isActive' => true])->assertOk()->assertJsonPath('name', 'Scratches');
        $this->deleteJson(self::TYPES."/{$id}")->assertNoContent();

        $this->postJson(self::TYPES.'/reorder', ['ids' => [$this->bubbling->id, $this->peeling->id]])->assertNoContent();
        $this->assertSame(['Bubbling', 'Peeling'], BackjobType::query()->ordered()->pluck('name')->all());

        $this->deleteJson(self::TYPES."/{$this->peeling->id}")->assertNoContent();
        $this->assertDatabaseMissing('service_backjob_type', ['backjob_type_id' => $this->peeling->id]);
    }
}
