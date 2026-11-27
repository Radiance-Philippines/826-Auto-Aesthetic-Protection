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
 * The React admin's "Services" of a branch: the branch.services area, one's
 * own branches only, and hidden services keep their link.
 */
class ConsoleBranchServiceTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const OFFER = '/console/api/operations/branch-services';

    private Branch $mine;

    private Branch $other;

    private Service $wash;

    private Service $tint;

    private Service $hidden;

    protected function setUp(): void
    {
        parent::setUp();

        $category = ServiceCategory::factory()->create(['name' => 'Detailing']);
        [$this->wash, $this->tint, $this->hidden] = collect(['Wash', 'Tint', 'Old polish'])
            ->map(fn (string $name, int $order) => Service::factory()->create(['service_category_id' => $category->id, 'name' => $name, 'sort_order' => $order]))
            ->all();
        $this->hidden->update(['is_active' => false]);
        $this->mine = Branch::factory()->create(['name' => 'Cainta']);
        $this->other = Branch::factory()->create(['name' => 'San Mateo']);
        $this->mine->services()->sync([$this->wash->id, $this->hidden->id]);
    }

    private function manager(): static
    {
        return $this->actingAs($this->staffUser('branch_manager', $this->mine));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return ['list' => ['getJson', ''], 'save' => ['postJson', '/{id}']];
    }

    private function callEndpoint(string $method, string $url): TestResponse
    {
        $url = str_replace('{id}', (string) $this->mine->id, $url);

        return $method === 'getJson' ? $this->getJson($url) : $this->postJson($url, ['services' => []]);
    }

    private function assertUntouched(): void
    {
        $this->assertEqualsCanonicalizing([$this->wash->id, $this->hidden->id], $this->mine->services()->pluck('services.id')->all());
        $this->assertSame([], $this->other->services()->pluck('services.id')->all());
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->callEndpoint($method, self::OFFER.$path)->assertUnauthorized();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_without_the_area_is_403(string $method, string $path): void
    {
        $this->actingAs($this->staffUser('operations', $this->mine));

        $this->callEndpoint($method, self::OFFER.$path)->assertForbidden();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_panel_that_does_not_show_it_is_404(string $method, string $path): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->callEndpoint($method, str_replace('operations', 'owner', self::OFFER).$path)->assertNotFound();
        $this->assertUntouched();
    }

    public function test_a_manager_sees_and_saves_their_branch_and_hidden_services_stay(): void
    {
        $this->manager();

        $this->getJson(self::OFFER)
            ->assertOk()
            ->assertJsonCount(1, 'branches')
            ->assertJsonPath('branches.0.name', 'Cainta')
            ->assertJsonPath('groups.0.category', 'Detailing')
            ->assertJsonPath('groups.0.services', [['value' => (string) $this->wash->id, 'label' => 'Wash'], ['value' => (string) $this->tint->id, 'label' => 'Tint']]);

        $this->postJson(self::OFFER."/{$this->mine->id}", ['services' => [$this->tint->id]])->assertNoContent();
        $this->assertEqualsCanonicalizing([$this->tint->id, $this->hidden->id], $this->mine->services()->pluck('services.id')->all());
    }

    public function test_another_branch_is_not_found_and_a_hidden_service_cannot_be_ticked(): void
    {
        $this->manager();

        $this->postJson(self::OFFER."/{$this->other->id}", ['services' => [$this->tint->id]])->assertNotFound();
        $this->postJson(self::OFFER."/{$this->mine->id}", ['services' => [$this->hidden->id]])->assertUnprocessable();
        $this->postJson(self::OFFER."/{$this->mine->id}", [])->assertUnprocessable();
        $this->assertUntouched();
    }

    public function test_the_top_bar_choice_narrows_what_a_super_admin_sees_and_saves(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $this->post(route('panel.branch'), ['branch' => $this->other->id]);

        $this->getJson(self::OFFER)->assertJsonCount(1, 'branches')->assertJsonPath('branches.0.name', 'San Mateo');
        $this->postJson(self::OFFER."/{$this->mine->id}", ['services' => []])->assertNotFound();
        $this->assertUntouched();
    }
}
