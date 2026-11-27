<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReordersRecords;
use App\Models\Photo;
use App\Support\ImagePipeline;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The React admin's Site → Photos, under PhotoPolicy like the Filament
 * resource. The screen uploads a batch one picture per request (each is
 * converted to WebP on save), so a batch never meets PHP's file and size limits.
 */
class ConsolePhotoController extends Controller
{
    use ReordersRecords;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Photo::class);

        return response()->json([
            'data' => Photo::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (Photo $photo) => $this->row($photo))->all(),
            'categories' => $this->categories(),
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Photo $photo): JsonResponse
    {
        Gate::authorize('update', $photo);

        return response()->json([
            ...$this->row($photo),
            'imageUrl' => app(ImagePipeline::class)->url($photo->image, 'md'),
            'categories' => $this->categories(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * One uploaded photo, added at the end of the list.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Photo::class);

        $data = $this->validated($request, ['image' => ['required', ...UploadRules::image(UploadRules::PHOTO_MAX_KB)]]);

        $photo = Photo::query()->create([
            'image' => $this->stored($request),
            'category' => $data['category'],
            'is_featured' => (bool) $data['isFeatured'],
            'sort_order' => (int) Photo::query()->max('sort_order') + 1,
        ]);

        return response()->json($this->row($photo), 201);
    }

    public function update(Request $request, Photo $photo): JsonResponse
    {
        Gate::authorize('update', $photo);

        $data = $this->validated($request, ['image' => ['nullable', ...UploadRules::image(UploadRules::PHOTO_MAX_KB)], 'caption' => ['nullable', 'string', 'max:200']]);

        $photo->fill(['caption' => $data['caption'] ?? null, 'category' => $data['category'], 'is_featured' => (bool) $data['isFeatured']]);

        if ($request->file('image')) {
            $photo->image = $this->stored($request);
        }

        $photo->save();

        return response()->json($this->row($photo));
    }

    /**
     * The list's "Featured" switch.
     */
    public function feature(Request $request, Photo $photo): JsonResponse
    {
        Gate::authorize('update', $photo);

        $data = $request->validate(['isFeatured' => ['required', 'boolean']]);
        $photo->update(['is_featured' => (bool) $data['isFeatured']]);

        return response()->json($this->row($photo));
    }

    public function destroy(Photo $photo): Response
    {
        Gate::authorize('delete', $photo);

        $photo->delete();

        return response()->noContent();
    }

    /**
     * The list's bulk actions.
     */
    public function bulk(Request $request): Response
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['categorize', 'feature', 'unfeature', 'delete'])],
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
            'category' => ['required_if:action,categorize', Rule::in(array_keys(Photo::CATEGORIES))],
        ]);

        if ($data['action'] === 'delete') {
            Gate::authorize('deleteAny', Photo::class);
        }

        $photos = Photo::query()->whereKey($data['ids'])->get();

        if ($data['action'] !== 'delete') {
            $photos->each(fn (Photo $photo) => Gate::authorize('update', $photo));
        }

        $photos->each(fn (Photo $photo) => match ($data['action']) {
            'categorize' => $photo->update(['category' => $data['category']]),
            'feature' => $photo->update(['is_featured' => true]),
            'unfeature' => $photo->update(['is_featured' => false]),
            'delete' => $photo->delete(),
        });

        return response()->noContent();
    }

    public function reorder(Request $request): Response
    {
        return $this->saveOrder($request, Photo::class);
    }

    /**
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        return $request->validate(
            $rules + ['category' => ['required', Rule::in(array_keys(Photo::CATEGORIES))], 'isFeatured' => ['required', 'boolean']],
            UploadRules::messages('image', UploadRules::PHOTO_MAX_KB),
        );
    }

    private function stored(Request $request): string
    {
        return $request->file('image')->store('photos', ['disk' => 'public', 'visibility' => 'public']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Photo $photo): array
    {
        return [
            'id' => $photo->getKey(),
            'thumbUrl' => Storage::disk('public')->url(ImagePipeline::thumbPathFor($photo->image)),
            'caption' => $photo->caption,
            'category' => $photo->category,
            'categoryLabel' => Photo::CATEGORIES[$photo->category] ?? $photo->category,
            'isFeatured' => $photo->is_featured,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function categories(): array
    {
        return collect(Photo::CATEGORIES)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all();
    }
}
