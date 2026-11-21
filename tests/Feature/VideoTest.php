<?php

namespace Tests\Feature;

use App\Filament\Resources\Videos\Pages\ManageVideos;
use App\Models\User;
use App\Models\Video;
use App\Support\YouTube;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_id_is_read_from_any_youtube_link(): void
    {
        foreach (['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ?t=5', 'https://www.youtube.com/shorts/dQw4w9WgXcQ', 'https://m.youtube.com/watch?feature=share&v=dQw4w9WgXcQ'] as $url) {
            $this->assertSame('dQw4w9WgXcQ', YouTube::videoId($url), $url);
        }

        foreach (['http://youtube.com/watch?v=dQw4w9WgXcQ', 'https://evil.example/watch?v=dQw4w9WgXcQ', 'https://vimeo.com/123', 'nope', null] as $url) {
            $this->assertNull(YouTube::videoId($url), (string) $url);
        }
    }

    public function test_only_published_videos_reach_the_site_with_a_linked_thumbnail(): void
    {
        $shown = Video::factory()->create(['title' => 'Coating day', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ', 'sort_order' => 2]);
        Video::factory()->create(['is_published' => false]);
        $first = Video::factory()->create(['sort_order' => 1]);

        $videos = $this->withoutVite()->get('/gallery')->assertOk()->viewData('app')['videos'];

        $videos = $videos->all();
        $this->assertSame([$first->id, $shown->id], array_column($videos, 'id'));
        $this->assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $videos[1]['thumbnail']);
        $this->assertSame('dQw4w9WgXcQ', $videos[1]['youtubeId']);
    }

    public function test_the_owner_adds_a_video_by_link_and_a_bad_link_is_refused(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ManageVideos::class)
            ->callAction('create', data: ['youtube_url' => 'https://vimeo.com/123', 'title' => 'x'])
            ->assertHasActionErrors(['youtube_url']);

        Livewire::test(ManageVideos::class)
            ->callAction('create', data: ['youtube_url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => 'Coating day', 'is_published' => true])
            ->assertHasNoActionErrors();

        $this->assertSame('dQw4w9WgXcQ', Video::sole()->youtube_id);
    }

    public function test_branch_staff_cannot_open_the_videos_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/videos')->assertForbidden();
        $this->actingAs(User::factory()->owner()->create())->get('/admin/videos')->assertOk();
    }
}
