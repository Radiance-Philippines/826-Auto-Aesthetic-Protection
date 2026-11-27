<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Branch staff only ever query their own branches' records (owners see all),
 * narrowed to the branch chosen in the top bar. For records with a branch_id.
 */
final class BranchScope
{
    /**
     * @param  bool  $includeEveryBranch  also records for every branch (branch_id null)
     */
    public static function apply(Builder $query, ?User $user, bool $includeEveryBranch = false): Builder
    {
        $ids = $user ? $user->scopedBranchIds() : [];

        if ($ids === null) {
            return $query;
        }

        $column = $query->getModel()->qualifyColumn('branch_id');

        return $query->where(fn (Builder $scope) => $scope
            ->whereIn($column, $ids)
            ->when($includeEveryBranch, fn (Builder $everyBranch) => $everyBranch->orWhereNull($column)));
    }
}
