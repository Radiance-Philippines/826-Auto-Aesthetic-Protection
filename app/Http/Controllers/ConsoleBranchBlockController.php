<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchBlock;
use App\Models\User;
use App\Support\BranchScope;
use App\Support\Closures;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The React admin's Closures, like the Filament resource: the person's
 * branches' closures plus the ones for every branch (following the top bar),
 * under BranchBlockPolicy. Only the shop area closes every branch.
 */
class ConsoleBranchBlockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', BranchBlock::class);

        $user = $request->user();
        $when = $request->query('when', 'upcoming');
        $blocks = $this->scoped($user)
            ->with('branch')
            ->when($when === 'upcoming', fn (Builder $query) => $query->where('ends_at', '>=', now()))
            ->when($when === 'past', fn (Builder $query) => $query->where('ends_at', '<', now()))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
        $shop = $user->canAccessArea('shop');
        $chosen = CurrentBranch::for($user);

        return response()->json([
            'data' => $blocks->map(fn (BranchBlock $block) => $this->row($block))->all(),
            'branches' => $this->branches($user)->map(fn (Branch $branch) => ['value' => (string) $branch->id, 'label' => $branch->name])->all(),
            'everyBranch' => $shop,
            'defaultBranch' => $chosen,
            'canCreate' => Gate::allows('create', BranchBlock::class),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', BranchBlock::class);

        $user = $request->user();
        $block = BranchBlock::query()->create([...$this->validated($request, $user), 'created_by' => $user->getKey()]);

        return response()->json($this->row($block), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $block = $this->scoped($request->user())->findOrFail($id);
        Gate::authorize('update', $block);

        $block->update($this->validated($request, $request->user()));

        return response()->json($this->row($block));
    }

    public function destroy(Request $request, int $id): Response
    {
        $block = $this->scoped($request->user())->findOrFail($id);
        Gate::authorize('delete', $block);

        $block->delete();

        return response()->noContent();
    }

    private function scoped(User $user): Builder
    {
        return BranchScope::apply(BranchBlock::query(), $user, includeEveryBranch: true);
    }

    private function branches(User $user): Collection
    {
        return Branch::query()->visibleTo($user)->ordered()->get(['id', 'name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, User $user): array
    {
        $data = $request->validate([
            'branchId' => [$user->canAccessArea('shop') ? 'nullable' : 'required', 'integer', Rule::in($this->branches($user)->modelKeys())],
            'kind' => ['required', Rule::in(array_keys(BranchBlock::KINDS))],
            'title' => ['required', 'string', 'max:120'],
            'firstDay' => ['required', 'date_format:Y-m-d'],
            'lastDay' => ['required', 'date_format:Y-m-d', 'after_or_equal:firstDay'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'branchId.required' => 'Pick a branch.',
            'lastDay.after_or_equal' => 'The last day can’t be before the first.',
        ], ['branchId' => 'branch', 'kind' => '“What happens” choice', 'title' => '“Why”', 'firstDay' => '“From” date', 'lastDay' => '“To” date', 'note' => 'private note']);

        return [
            'branch_id' => $data['branchId'] ?? null,
            'kind' => $data['kind'],
            'title' => $data['title'],
            'note' => $data['note'] ?? null,
            ...Closures::span($data['firstDay'], $data['lastDay']),
        ];
    }

    /**
     * The form's values (with the private note) only for those who may change it.
     *
     * @return array<string, mixed>
     */
    private function row(BranchBlock $block): array
    {
        $editable = Gate::allows('update', $block);

        return [
            'id' => $block->getKey(),
            'when' => $block->dateLabel(),
            'startsOn' => $block->firstDay()->toDateString(),
            'title' => $block->title,
            'kindLabel' => $block->kind === 'full' ? 'Fully booked' : 'Closed',
            'branch' => $block->branch?->name,
            'values' => $editable ? [
                'branchId' => $block->branch_id,
                'kind' => $block->kind,
                'title' => $block->title,
                'firstDay' => $block->firstDay()->toDateString(),
                'lastDay' => $block->lastDay()->toDateString(),
                'note' => $block->note,
            ] : null,
        ];
    }
}
