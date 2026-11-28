<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Support\BranchScope;
use App\Support\ImagePipeline;
use App\Support\ReviewModeration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The React admin's Reviews, like the Filament resource: only the person's
 * branches' reviews (following the top bar), under ReviewPolicy. Nobody adds
 * one here, and only a super admin deletes.
 */
class ConsoleReviewController extends Controller
{
    private const PER_PAGE = 25;

    private const TABS = ['pending', 'approved', 'rejected', 'all'];

    /**
     * ?activeTab=&search=&sort=rating&desc=1&page=
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Review::class);

        $tab = in_array($request->query('activeTab'), self::TABS, true) ? $request->query('activeTab') : 'pending';
        $search = trim((string) $request->string('search'));
        $query = $this->scoped($request)
            ->with(['booking.items', 'branch'])
            ->when($tab !== 'all', fn (Builder $query) => $query->where('status', $tab))
            ->when($search !== '', fn (Builder $query) => $query->whereLike('display_name', '%'.addcslashes($search, '%_\\').'%'));

        $request->query('sort') === 'rating'
            ? $query->orderBy('rating', $request->boolean('desc') ? 'desc' : 'asc')->orderByDesc('id')
            : $query->orderByDesc('created_at')->orderByDesc('id');

        $page = $query->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (Review $review) => $this->row($review))->all(),
            'meta' => ['total' => $page->total(), 'perPage' => self::PER_PAGE],
            'pending' => $this->scoped($request)->pending()->count(),
            'canDeleteAny' => Gate::allows('deleteAny', Review::class),
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $review = $this->find($request, $id);
        Gate::authorize('view', $review);

        return $this->record($review);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $review = $this->find($request, $id);
        Gate::authorize('update', $review);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
            'displayName' => ['required', 'string', 'max:80'],
            'status' => ['required', Rule::in(array_keys(Review::STATUSES))],
        ], [], ['body' => '“What they wrote” text', 'displayName' => 'name']);

        $review->update(['body' => $data['body'], 'display_name' => $data['displayName'], 'status' => $data['status']]);

        return $this->record($review);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $review = $this->find($request, $id);
        Gate::authorize('update', $review);
        abort_if($review->status === 'approved', 422, 'This review is already approved.');

        ReviewModeration::approve($review);

        return $this->record($review);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $review = $this->find($request, $id);
        Gate::authorize('update', $review);
        abort_if($review->status === 'rejected', 422, 'This review is already not shown.');

        $data = $request->validate(['adminNote' => ['nullable', 'string', 'max:250']], [], ['adminNote' => 'reason']);

        ReviewModeration::reject($review, $data['adminNote'] ?? null);

        return $this->record($review);
    }

    public function feature(Request $request, int $id): JsonResponse
    {
        $review = $this->find($request, $id);
        Gate::authorize('update', $review);
        abort_unless($review->status === 'approved', 422, 'Only an approved review can be featured.');

        ReviewModeration::toggleFeatured($review);

        return $this->record($review);
    }

    public function destroy(Request $request, int $id): Response
    {
        $review = $this->find($request, $id);
        Gate::authorize('delete', $review);

        $review->delete();

        return response()->noContent();
    }

    /**
     * "Delete selected".
     */
    public function destroyMany(Request $request): Response
    {
        Gate::authorize('deleteAny', Review::class);

        $ids = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer', 'distinct']])['ids'];

        // One at a time, so each one's photo goes with it
        $this->scoped($request)->whereKey($ids)->get()->each->delete();

        return response()->noContent();
    }

    private function scoped(Request $request): Builder
    {
        return BranchScope::apply(Review::query(), $request->user());
    }

    /**
     * Another branch's review is not found.
     */
    private function find(Request $request, int $id): Review
    {
        return $this->scoped($request)->with(['booking.items', 'branch'])->findOrFail($id);
    }

    private function record(Review $review): JsonResponse
    {
        return response()->json([
            ...$this->row($review),
            'body' => $review->body,
            'customer' => $review->booking?->name,
            'adminNote' => $review->admin_note,
            'sentAt' => $review->created_at?->format('M j, Y g:i A'),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Review $review): array
    {
        return [
            'id' => $review->getKey(),
            'displayName' => $review->display_name,
            'reference' => $review->booking?->reference,
            'branch' => $review->branch?->name,
            'service' => $review->booking?->items->first()?->service_name,
            'rating' => $review->rating,
            'stars' => ReviewModeration::stars($review->rating),
            'excerpt' => str($review->body)->limit(90)->toString(),
            'photoUrl' => $review->photo ? Storage::disk('public')->url(ImagePipeline::thumbPathFor($review->photo)) : null,
            'status' => $review->status,
            'statusLabel' => Review::STATUSES[$review->status] ?? $review->status,
            'featured' => $review->is_featured,
            'canUpdate' => Gate::allows('update', $review),
            'canDelete' => Gate::allows('delete', $review),
        ];
    }
}
