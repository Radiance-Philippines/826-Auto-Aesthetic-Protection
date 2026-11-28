<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Photo;
use App\Models\User;
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
 * The React admin's Site → Photos endpoints: PhotoPolicy (content), uploads,
 * the bulk actions and the order.
 */
class ConsolePhotoTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const PHOTOS = '/console/api/owner/photos';

    private Photo $first;

    private Photo $second;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->first = Photo::factory()->create(['caption' => 'Hood', 'category' => 'ppf', 'sort_order' => 1]);
        $this->second = Photo::factory()->create(['caption' => null, 'sort_order' => 2]);
    }

    private function owner(): static
    {
        return $this->actingAs(User::factory()->owner()->create());
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
            'one' => ['getJson', '/{id}'],
            'upload' => ['postJson', ''],
            'update' => ['postJson', '/{id}'],
            'featured' => ['patchJson', '/{id}/featured'],
            'delete' => ['deleteJson', '/{id}'],
            'bulk' => ['postJson', '/bulk'],
            'reorder' => ['postJson', '/reorder'],
        ];
    }

    private function callEndpoint(string $method, string $url): TestResponse
    {
        $url = str_replace('{id}', (string) $this->first->id, $url);
        $body = ['category' => 'tint', 'isFeatured' => true, 'action' => 'delete', 'ids' => [$this->second->id, $this->first->id]];

        return $method === 'getJson' ? $this->getJson($url) : $this->{$method}($url, $body);
    }

    private function assertUntouched(): void
    {
        $this->assertSame([$this->first->id, $this->second->id], Photo::query()->ordered()->pluck('id')->all());
        $this->assertSame(['ppf', false], [$this->first->fresh()->category, $this->first->fresh()->is_featured]);
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->callEndpoint($method, self::PHOTOS.$path)->assertUnauthorized();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_role_without_the_owner_panel_is_403(string $method, string $path): void
    {
        $this->actingAs($this->staffUser('sales_admin', Branch::factory()->create(), ['permissions' => ['content']]));

        $this->callEndpoint($method, self::PHOTOS.$path)->assertForbidden();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_panel_that_does_not_show_them_is_404(string $method, string $path): void
    {
        $this->owner();

        $this->callEndpoint($method, str_replace('owner', 'operations', self::PHOTOS).$path)->assertNotFound();
        $this->assertUntouched();
    }

    public function test_an_upload_lands_at_the_end_as_a_webp_with_its_size(): void
    {
        $this->owner();

        $id = $this->send(self::PHOTOS, ['image' => UploadedFile::fake()->image('door.jpg', 800, 500), 'category' => 'coating', 'isFeatured' => '1'])
            ->assertCreated()
            ->assertJsonPath('categoryLabel', 'Coating')
            ->assertJsonPath('isFeatured', true)
            ->json('id');

        $photo = Photo::query()->findOrFail($id);
        $this->assertSame([3, 800, 500], [$photo->sort_order, $photo->width, $photo->height]);
        $this->assertStringStartsWith('photos/', $photo->image);
        $this->assertStringEndsWith('.webp', $photo->image);
        Storage::disk('public')->assertExists([$photo->image, ImagePipeline::renditionPath($photo->image, 'thumb')]);
        $this->getJson(self::PHOTOS)->assertJsonPath('data.2.thumbUrl', Storage::disk('public')->url(ImagePipeline::renditionPath($photo->image, 'thumb')));
    }

    #[DataProvider('badFiles')]
    public function test_an_upload_must_be_a_jpg_png_or_webp_up_to_20_mb(string $name, int $kb, string $mime, string $message): void
    {
        $this->owner();
        $files = Storage::disk('public')->allFiles();

        $this->send(self::PHOTOS, ['image' => UploadedFile::fake()->create($name, $kb, $mime), 'category' => 'coating', 'isFeatured' => '0'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image' => $message]);

        $this->assertSame($files, Storage::disk('public')->allFiles());
        $this->assertSame(2, Photo::query()->count());
    }

    /**
     * @return array<string, array{string, int, string, string}>
     */
    public static function badFiles(): array
    {
        return [
            'svg' => ['a.svg', 1, 'image/svg+xml', 'Use a JPG, PNG or WebP picture.'],
            'heic' => ['a.heic', 1, 'image/heic', 'Use a JPG, PNG or WebP picture.'],
            'over 20 MB' => ['a.jpg', UploadRules::PHOTO_MAX_KB + 1, 'image/jpeg', 'Keep the picture under 20 MB.'],
        ];
    }

    public function test_what_the_forms_refuse(): void
    {
        $this->owner();

        $this->postJson(self::PHOTOS, [])->assertUnprocessable()->assertJsonValidationErrors(['image' => 'Choose a picture.', 'category', 'isFeatured']);
        $this->postJson(self::PHOTOS."/{$this->first->id}", ['caption' => str_repeat('a', 201), 'category' => 'pool', 'isFeatured' => true])->assertUnprocessable()->assertJsonValidationErrors(['caption', 'category']);
        $this->postJson(self::PHOTOS.'/bulk', ['action' => 'categorize', 'ids' => [$this->first->id]])->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->postJson(self::PHOTOS.'/bulk', ['action' => 'burn', 'ids' => []])->assertUnprocessable()->assertJsonValidationErrors(['action', 'ids']);
        $this->assertUntouched();
    }

    public function test_a_photo_is_changed_replaced_and_deleted_with_its_files(): void
    {
        $this->owner();
        $url = self::PHOTOS."/{$this->first->id}";
        $old = $this->first->image;

        $this->getJson($url)->assertOk()->assertJsonPath('caption', 'Hood')->assertJsonPath('categories.0.value', 'coating');
        $this->postJson($url, ['caption' => 'Bonnet', 'category' => 'tint', 'isFeatured' => false])->assertOk()->assertJsonPath('category', 'tint');
        $this->assertSame($old, $this->first->fresh()->image);

        $this->send($url, ['caption' => '', 'category' => 'tint', 'isFeatured' => '1', 'image' => UploadedFile::fake()->image('new.png', 640, 480)])->assertOk()->assertJsonPath('caption', null);
        $new = $this->first->fresh()->image;
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing($old);

        $this->patchJson($url.'/featured', ['isFeatured' => false])->assertOk()->assertJsonPath('isFeatured', false);
        $this->deleteJson($url)->assertNoContent();
        Storage::disk('public')->assertMissing([$new, ImagePipeline::renditionPath($new, 'thumb')]);
        $this->deleteJson($url)->assertNotFound();
    }

    public function test_the_bulk_actions_and_the_order(): void
    {
        $this->owner();
        $ids = [$this->first->id, $this->second->id];

        $this->postJson(self::PHOTOS.'/bulk', ['action' => 'categorize', 'ids' => $ids, 'category' => 'shop'])->assertNoContent();
        $this->assertSame(['shop', 'shop'], Photo::query()->ordered()->pluck('category')->all());
        $this->postJson(self::PHOTOS.'/bulk', ['action' => 'feature', 'ids' => $ids])->assertNoContent();
        $this->assertSame(2, Photo::query()->where('is_featured', true)->count());
        $this->postJson(self::PHOTOS.'/bulk', ['action' => 'unfeature', 'ids' => [$this->first->id]])->assertNoContent();
        $this->assertSame([false, true], Photo::query()->ordered()->get()->map->is_featured->all());

        $this->postJson(self::PHOTOS.'/reorder', ['ids' => array_reverse($ids)])->assertNoContent();
        $this->assertSame(array_reverse($ids), Photo::query()->ordered()->pluck('id')->all());

        $this->postJson(self::PHOTOS.'/bulk', ['action' => 'delete', 'ids' => $ids])->assertNoContent();
        $this->assertSame(0, Photo::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles('photos'));
    }
}
