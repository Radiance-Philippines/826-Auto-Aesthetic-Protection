<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The React admin's drag-to-order lists: every record's id, in the new order,
 * saved to sort_order under the policy's reorder, as Filament's tables do.
 */
trait ReordersRecords
{
    /**
     * @param  class-string<Model>  $model
     */
    private function saveOrder(Request $request, string $model): Response
    {
        Gate::authorize('reorder', $model);

        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'distinct']])['ids'];

        DB::transaction(function () use ($ids, $model) {
            if ($model::query()->count() !== count($ids) || $model::query()->whereKey($ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['ids' => 'The list changed. Reload and try again.']);
            }

            foreach (array_values($ids) as $index => $id) {
                $model::query()->whereKey($id)->update(['sort_order' => $index + 1]);
            }
        });

        return response()->noContent();
    }
}
