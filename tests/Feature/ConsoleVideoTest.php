<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Site → Videos endpoints, under VideoPolicy (content).
 */
class ConsoleVideoTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const VIDEOS = '/console/api/owner/videos';

    private Video $first;

    private Video $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->first = Video::factory()->create(['title' => 'First', 'youtube_url' => 'https://youtu.be/aaaaaaaaaaa', 'sort_order' => 1]);
        $this->second = Video::factory()->create(['title' => 'Second', 'sort_order' => 2]);
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
            'shown' => ['patchJson', '/{id}/published'],
            'delete' => ['deleteJson', '/{id}'],
            'reorder' => ['postJson', '/reorder'],
        ];
    }

    private function callEndpoint(string $method, string $url): TestResponse
    {
        $url = str_replace('{id}', (string) $this->first->id, $url);
        $body = ['youtubeUrl' => 'https://youtu.be/bbbbbbbbbbb', 'title' => 'New', 'isPublished' => false, 'ids' => [$this->second->id, $this->first->id]];

        return $method === 'getJson' ? $this->getJson($url) : $this->{$method}($url, $body);
    }

    private function assertUntouched(): void
    {
        $this->assertSame(['First', 'Second'], Video::query()->ordered()->pluck('title')->all());
        $this->assertTrue($this->first->fresh()->is_published);
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->callEndpoint($method, self::VIDEOS.$path)->assertUnauthorized();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_role_without_the_owner_panel_is_403(string $method, string $path): void
    {
        $this->actingAs($this->staffUser('branch_manager', Branch::factory()->create(), ['permissions' => ['content']]));

        $this->callEndpoint($method, self::VIDEOS.$path)->assertForbidden();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_panel_that_does_not_show_them_is_404(string $method, string $path): void
    {
        $this->owner();

        $this->callEndpoint($method, str_replace('owner', 'system', self::VIDEOS).$path)->assertNotFound();
        $this->assertUntouched();
    }

    public function test_a_video_is_added_changed_hidden_ordered_and_deleted(): void
    {
        $this->owner();

        $this->getJson(self::VIDEOS)->assertOk()->assertJsonPath('data.0.watchUrl', 'https://www.youtube.com/watch?v=aaaaaaaaaaa');

        $id = $this->postJson(self::VIDEOS, ['youtubeUrl' => 'https://www.youtube.com/shorts/ccccccccccc', 'title' => 'Short', 'caption' => '', 'isPublished' => true])
            ->assertCreated()
            ->assertJsonPath('caption', null)
            ->json('id');
        $this->assertSame('ccccccccccc', Video::query()->findOrFail($id)->youtube_id);

        $this->patchJson(self::VIDEOS."/{$id}", ['youtubeUrl' => 'https://youtu.be/ddddddddddd', 'title' => 'Long', 'caption' => 'Better', 'isPublished' => true])->assertOk();
        $this->assertSame('ddddddddddd', Video::query()->findOrFail($id)->youtube_id);
        $this->patchJson(self::VIDEOS."/{$id}/published", ['isPublished' => false])->assertOk()->assertJsonPath('isPublished', false);
        $this->deleteJson(self::VIDEOS."/{$id}")->assertNoContent();

        $this->postJson(self::VIDEOS.'/reorder', ['ids' => [$this->second->id, $this->first->id]])->assertNoContent();
        $this->assertSame(['Second', 'First'], Video::query()->ordered()->pluck('title')->all());
    }

    public function test_what_the_form_refuses(): void
    {
        $this->owner();
        $message = 'Paste a YouTube link (youtube.com or youtu.be, starting with https://).';

        $this->postJson(self::VIDEOS, [])->assertUnprocessable()->assertJsonValidationErrors(['youtubeUrl', 'title', 'isPublished']);
        foreach (['https://vimeo.com/1', 'http://youtu.be/aaaaaaaaaaa'] as $url) {
            $this->postJson(self::VIDEOS, ['youtubeUrl' => $url, 'title' => 'No', 'isPublished' => true])->assertUnprocessable()->assertJsonValidationErrors(['youtubeUrl' => $message]);
        }
        $this->postJson(self::VIDEOS, ['youtubeUrl' => ['x'], 'title' => str_repeat('a', 121), 'caption' => str_repeat('b', 201), 'isPublished' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['youtubeUrl', 'title', 'caption']);
        $this->patchJson(self::VIDEOS."/{$this->first->id}/published", [])->assertUnprocessable();
        $this->assertUntouched();
    }
}
