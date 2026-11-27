<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReordersRecords;
use App\Models\Video;
use App\Support\YouTube;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The React admin's Site → Videos, under VideoPolicy like the Filament
 * resource. Links only: nothing is uploaded.
 */
class ConsoleVideoController extends Controller
{
    use ReordersRecords;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Video::class);

        return response()
            ->json(['data' => Video::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (Video $video) => $this->row($video))->all()])
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Video::class);

        $video = Video::query()->create($this->validated($request));

        return response()->json($this->row($video), 201);
    }

    public function update(Request $request, Video $video): JsonResponse
    {
        Gate::authorize('update', $video);

        $video->update($this->validated($request));

        return response()->json($this->row($video));
    }

    /**
     * The list's "Shown" switch.
     */
    public function publish(Request $request, Video $video): JsonResponse
    {
        Gate::authorize('update', $video);

        $data = $request->validate(['isPublished' => ['required', 'boolean']]);
        $video->update(['is_published' => (bool) $data['isPublished']]);

        return response()->json($this->row($video));
    }

    public function destroy(Video $video): Response
    {
        Gate::authorize('delete', $video);

        $video->delete();

        return response()->noContent();
    }

    public function reorder(Request $request): Response
    {
        return $this->saveOrder($request, Video::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'youtubeUrl' => ['bail', 'required', 'string', 'max:255', YouTube::rule()],
            'title' => ['required', 'string', 'max:120'],
            'caption' => ['nullable', 'string', 'max:200'],
            'isPublished' => ['required', 'boolean'],
        ], [], ['youtubeUrl' => 'YouTube link']);

        return ['youtube_url' => $data['youtubeUrl'], 'title' => $data['title'], 'caption' => $data['caption'] ?? null, 'is_published' => (bool) $data['isPublished']];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Video $video): array
    {
        return [
            'id' => $video->getKey(),
            'title' => $video->title,
            'youtubeUrl' => $video->youtube_url,
            'watchUrl' => YouTube::watchUrl($video->youtube_id),
            'caption' => $video->caption,
            'isPublished' => $video->is_published,
        ];
    }
}
