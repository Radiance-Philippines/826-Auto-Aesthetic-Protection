<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

/**
 * Staff with the reviews area moderate their own branches' reviews; only a super admin deletes.
 */
class ReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessArea('reviews');
    }

    public function view(User $user, Review $review): bool
    {
        return $user->canAccessArea('reviews') && $user->canManageBranch($review->branch_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Review $review): bool
    {
        return $user->canAccessArea('reviews') && $user->canManageBranch($review->branch_id);
    }

    public function delete(User $user, Review $review): bool
    {
        return $user->isOwner();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isOwner();
    }
}
