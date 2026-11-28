<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Reviews: ReviewPolicy and the person's branches, the
 * moderation actions and their rules; only a super admin deletes.
 */
class ConsoleReviewTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const REVIEWS = '/console/api/sales/reviews';

    private Branch $cainta;

    private Branch $sanMateo;

    private Review $mine;

    private Review $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);
        $this->mine = $this->review($this->cainta, 'Maria S.', ['rating' => 4, 'body' => 'Shiny and quick.']);
        $this->theirs = $this->review($this->sanMateo, 'Jose R.');
    }

    private function review(Branch $branch, string $name, array $state = []): Review
    {
        $guest = Guest::factory()->walkIn()->create(['name' => $name]);
        $booking = Booking::factory()->status('exited')->create(['branch_id' => $branch->id, 'guest_id' => $guest->id]);

        return Review::factory()->create(['booking_id' => $booking->id, 'guest_id' => $guest->id, 'display_name' => $name, ...$state]);
    }

    private function sales(): static
    {
        return $this->actingAs($this->staffUser('sales_admin', $this->cainta));
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
            'one' => ['getJson', '/{id}'],
            'edit' => ['patchJson', '/{id}'],
            'approve' => ['postJson', '/{id}/approve'],
            'reject' => ['postJson', '/{id}/reject'],
            'feature' => ['postJson', '/{id}/feature'],
            'delete' => ['deleteJson', '/{id}'],
            'delete selected' => ['postJson', '/delete'],
        ];
    }

    private function callEndpoint(string $method, string $url, Review $review): TestResponse
    {
        $url = str_replace('{id}', (string) $review->id, $url);
        $body = ['body' => 'Changed', 'displayName' => 'X', 'status' => 'approved', 'ids' => [$review->id]];

        return $method === 'getJson' ? $this->getJson($url) : $this->{$method}($url, $body);
    }

    private function assertUntouched(): void
    {
        $this->assertSame(['pending', 'pending'], [$this->mine->fresh()->status, $this->theirs->fresh()->status]);
        $this->assertSame('Shiny and quick.', $this->mine->fresh()->body);
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->callEndpoint($method, self::REVIEWS.$path, $this->mine)->assertUnauthorized();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_without_the_reviews_area_is_403(string $method, string $path): void
    {
        $this->actingAs($this->staffUser('operations', $this->cainta));

        $this->callEndpoint($method, str_replace('sales', 'operations', self::REVIEWS).$path, $this->mine)->assertForbidden();
        $this->assertUntouched();
    }

    public function test_another_branchs_review_is_not_found_for_every_action(): void
    {
        $this->sales();

        foreach (['one', 'edit', 'approve', 'reject', 'feature', 'delete'] as $name) {
            [$method, $path] = self::endpoints()[$name];
            $this->callEndpoint($method, self::REVIEWS.$path, $this->theirs)->assertNotFound();
        }

        $this->assertUntouched();
    }

    public function test_the_list_has_tabs_search_and_sort_within_the_persons_branches(): void
    {
        $this->review($this->cainta, 'Ana L.', ['rating' => 2, 'status' => 'approved']);
        $this->sales();

        $this->getJson(self::REVIEWS)
            ->assertOk()
            ->assertJsonPath('data.*.displayName', ['Maria S.'])
            ->assertJsonPath('data.0.stars', '★★★★☆')
            ->assertJsonPath('data.0.branch', 'Cainta')
            ->assertJsonPath('pending', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('canDeleteAny', false);
        $this->getJson(self::REVIEWS.'?activeTab=approved')->assertJsonPath('data.*.displayName', ['Ana L.']);
        $this->getJson(self::REVIEWS.'?activeTab=all&sort=rating')->assertJsonPath('data.*.rating', [2, 4]);
        $this->getJson(self::REVIEWS.'?activeTab=all&sort=rating&desc=1')->assertJsonPath('data.*.rating', [4, 2]);
        $this->getJson(self::REVIEWS.'?activeTab=all&search=ana')->assertJsonPath('data.*.displayName', ['Ana L.']);
        $this->getJson(self::REVIEWS.'?activeTab=all&search=%25')->assertJsonPath('meta.total', 0);

        $this->post(route('panel.branch'), ['branch' => $this->cainta->id]);
        $this->getJson(self::REVIEWS)->assertJsonPath('meta.total', 1);
    }

    public function test_approve_feature_and_reject(): void
    {
        $this->sales();
        $url = self::REVIEWS."/{$this->mine->id}";

        $this->postJson("{$url}/feature")->assertUnprocessable();
        $this->postJson("{$url}/approve")->assertOk()->assertJsonPath('status', 'approved');
        $this->assertNotNull($this->mine->fresh()->approved_at);
        $this->postJson("{$url}/approve")->assertUnprocessable();
        $this->postJson("{$url}/feature")->assertOk()->assertJsonPath('featured', true);
        $this->postJson("{$url}/reject", ['adminNote' => str_repeat('a', 251)])->assertUnprocessable()->assertJsonValidationErrors('adminNote');
        $this->postJson("{$url}/reject", ['adminNote' => 'Wrong car'])->assertOk()->assertJsonPath('adminNote', 'Wrong car')->assertJsonPath('featured', false);
        $this->postJson("{$url}/reject")->assertUnprocessable();
        $this->postJson("{$url}/approve")->assertOk()->assertJsonPath('adminNote', null);
    }

    public function test_typos_are_fixed_within_the_forms_rules(): void
    {
        $this->sales();
        $url = self::REVIEWS."/{$this->mine->id}";

        $this->getJson($url)->assertOk()->assertJsonPath('body', 'Shiny and quick.')->assertJsonPath('customer', $this->mine->booking->name);
        $this->patchJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['body', 'displayName', 'status']);
        $this->patchJson($url, ['body' => str_repeat('a', 1001), 'displayName' => str_repeat('b', 81), 'status' => 'gone'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body', 'displayName', 'status']);
        $this->patchJson($url, ['body' => 'Shiny, and quick.', 'displayName' => 'Maria S.', 'status' => 'pending'])->assertOk();
        $this->assertSame('Shiny, and quick.', $this->mine->fresh()->body);
    }

    public function test_only_a_super_admin_deletes_and_the_photo_goes_too(): void
    {
        $photo = UploadedFile::fake()->image('car.jpg', 600, 400)->store('reviews', 'public');
        $this->mine->update(['photo' => $photo]);
        $stored = $this->mine->fresh()->photo;

        $this->sales();
        $this->deleteJson(self::REVIEWS."/{$this->mine->id}")->assertForbidden();
        $this->postJson(self::REVIEWS.'/delete', ['ids' => [$this->mine->id]])->assertForbidden();
        $this->assertModelExists($this->mine);

        $this->owner();
        $owners = str_replace('sales', 'owner', self::REVIEWS);
        $this->deleteJson("{$owners}/{$this->theirs->id}")->assertNoContent();
        $this->postJson("{$owners}/delete", ['ids' => [$this->mine->id]])->assertNoContent();

        $this->assertSame(0, Review::query()->count());
        Storage::disk('public')->assertMissing($stored);
    }
}
