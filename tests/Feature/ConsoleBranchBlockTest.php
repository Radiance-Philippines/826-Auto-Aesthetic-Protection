<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Closures: BranchBlockPolicy, the person's branches plus
 * the ones for every branch, and the Filament form's rules.
 */
class ConsoleBranchBlockTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const CLOSURES = '/console/api/operations/branch-blocks';

    private Branch $cainta;

    private Branch $sanMateo;

    private BranchBlock $mine;

    private BranchBlock $theirs;

    private BranchBlock $every;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-14 10:00');
        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);
        $this->mine = BranchBlock::factory()->between('2026-11-20', '2026-11-21')->create(['branch_id' => $this->cainta->id, 'title' => 'Brownout', 'note' => 'Meralco']);
        $this->theirs = BranchBlock::factory()->between('2026-11-22', '2026-11-23')->create(['branch_id' => $this->sanMateo->id, 'title' => 'Their day']);
        $this->every = BranchBlock::factory()->between('2026-12-24', '2026-12-27')->create(['title' => 'Christmas', 'note' => 'Owner note']);
    }

    private function manager(): static
    {
        return $this->actingAs($this->staffUser('branch_manager', $this->cainta));
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
        return ['branchId' => $this->cainta->id, 'kind' => 'full', 'title' => 'Big job', 'firstDay' => '2026-11-24', 'lastDay' => '2026-11-25', 'note' => '', ...$changes];
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
        ];
    }

    private function callEndpoint(string $method, string $url): TestResponse
    {
        $url = str_replace('{id}', (string) $this->mine->id, $url);

        return $method === 'getJson' ? $this->getJson($url) : $this->{$method}($url, $this->form());
    }

    private function assertUntouched(): void
    {
        $this->assertSame(['Brownout', 'Their day', 'Christmas'], BranchBlock::query()->orderBy('starts_at')->pluck('title')->all());
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->callEndpoint($method, self::CLOSURES.$path)->assertUnauthorized();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_without_the_closures_area_is_403(string $method, string $path): void
    {
        $this->actingAs($this->staffUser('operations', $this->cainta));

        $this->callEndpoint($method, self::CLOSURES.$path)->assertForbidden();
        $this->assertUntouched();
    }

    public function test_a_manager_sees_their_branch_and_every_branch_ones_and_may_change_only_their_own(): void
    {
        $this->manager();

        $this->getJson(self::CLOSURES)
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Brownout', 'Christmas'])
            ->assertJsonPath('data.0.when', 'Fri, Nov 20')
            ->assertJsonPath('data.0.values.note', 'Meralco')
            ->assertJsonPath('data.1.when', 'Dec 24 – Dec 26')
            ->assertJsonPath('data.1.values', null)
            ->assertJsonPath('everyBranch', false)
            ->assertJsonPath('branches', [['value' => (string) $this->cainta->id, 'label' => 'Cainta']]);

        $this->patchJson(self::CLOSURES."/{$this->every->id}", $this->form())->assertForbidden();
        $this->deleteJson(self::CLOSURES."/{$this->every->id}")->assertForbidden();
        $this->patchJson(self::CLOSURES."/{$this->theirs->id}", $this->form())->assertNotFound();
        $this->deleteJson(self::CLOSURES."/{$this->theirs->id}")->assertNotFound();
        $this->assertUntouched();
    }

    public function test_a_manager_closes_days_at_their_branch_only(): void
    {
        $this->manager();

        $this->postJson(self::CLOSURES, $this->form(['branchId' => null]))->assertUnprocessable()->assertJsonValidationErrors(['branchId' => 'Pick a branch.']);
        $this->postJson(self::CLOSURES, $this->form(['branchId' => $this->sanMateo->id]))->assertUnprocessable()->assertJsonValidationErrors('branchId');
        $this->patchJson(self::CLOSURES."/{$this->mine->id}", $this->form(['branchId' => $this->sanMateo->id]))->assertUnprocessable();

        $this->postJson(self::CLOSURES, $this->form())->assertCreated()->assertJsonPath('kindLabel', 'Fully booked')->assertJsonPath('when', 'Nov 24 – Nov 25');

        $block = BranchBlock::query()->where('title', 'Big job')->sole();
        $this->assertSame(['full', '2026-11-24 00:00:00', '2026-11-26 00:00:00'], [$block->kind, $block->starts_at->toDateTimeString(), $block->ends_at->toDateTimeString()]);
        $this->assertSame(auth()->id(), $block->created_by);

        $this->patchJson(self::CLOSURES."/{$block->id}", $this->form(['kind' => 'closed', 'lastDay' => '2026-11-24']))->assertOk()->assertJsonPath('when', 'Tue, Nov 24');
        $this->deleteJson(self::CLOSURES."/{$block->id}")->assertNoContent();
        $this->assertUntouched();
    }

    public function test_the_shop_area_closes_every_branch_and_the_top_bar_narrows_the_list(): void
    {
        $this->owner();

        $this->postJson('/console/api/owner/branch-blocks', $this->form(['branchId' => null, 'title' => 'Holy Week']))->assertCreated()->assertJsonPath('branch', null);

        $this->post(route('panel.branch'), ['branch' => $this->sanMateo->id]);
        $this->getJson(self::CLOSURES)->assertJsonPath('data.*.title', ['Their day', 'Holy Week', 'Christmas'])->assertJsonPath('defaultBranch', $this->sanMateo->id);
        $this->patchJson(self::CLOSURES."/{$this->mine->id}", $this->form())->assertNotFound();
    }

    public function test_upcoming_past_and_all(): void
    {
        BranchBlock::factory()->between('2026-11-01', '2026-11-02')->create(['branch_id' => $this->cainta->id, 'title' => 'Undas']);
        $this->manager();

        $this->getJson(self::CLOSURES)->assertJsonPath('data.*.title', ['Brownout', 'Christmas']);
        $this->getJson(self::CLOSURES.'?when=past')->assertJsonPath('data.*.title', ['Undas']);
        $this->getJson(self::CLOSURES.'?when=all')->assertJsonPath('data.*.title', ['Undas', 'Brownout', 'Christmas']);
    }

    public function test_what_the_form_refuses(): void
    {
        $this->owner();

        $this->postJson(self::CLOSURES, [])->assertUnprocessable()->assertJsonValidationErrors(['kind', 'title', 'firstDay', 'lastDay']);
        $this->postJson(self::CLOSURES, $this->form(['kind' => 'half', 'title' => str_repeat('a', 121), 'firstDay' => '24/11/2026', 'note' => str_repeat('b', 1001)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kind', 'title', 'firstDay', 'note']);
        $this->postJson(self::CLOSURES, $this->form(['lastDay' => '2026-11-23']))->assertUnprocessable()->assertJsonValidationErrors(['lastDay' => 'The last day can’t be before the first.']);
        $this->assertUntouched();
    }
}
