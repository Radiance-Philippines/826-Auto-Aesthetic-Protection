<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;

/**
 * The branch the admin is working in (#695): one branch, or null for "All
 * branches". It lives in the signed-in person's web session, so team apps,
 * jobs and notices to other people are never narrowed by it.
 */
class CurrentBranch
{
    public const SESSION = 'current_branch';

    public static function for(User $user): ?int
    {
        $chosen = session()->get(self::SESSION);

        if ($chosen === null || auth()->id() !== $user->getKey()) {
            return null;
        }

        // Checked on every read: a person can lose a branch after choosing it
        return $user->isOwner() || in_array((int) $chosen, $user->branchIds(), true) ? (int) $chosen : null;
    }

    /**
     * Null means all branches. A branch that is not theirs is refused.
     */
    public static function choose(User $user, ?int $branchId): void
    {
        if ($branchId === null) {
            session()->forget(self::SESSION);

            return;
        }

        abort_unless($user->canManageBranch($branchId) && Branch::query()->whereKey($branchId)->exists(), 403);

        session()->put(self::SESSION, $branchId);
    }
}
