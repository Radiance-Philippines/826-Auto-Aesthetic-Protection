<?php

namespace App\Policies;

use App\Models\BranchBlock;
use App\Models\User;

/**
 * Staff with the closures area close days or mark them fully booked at their own
 * branches; closures for every branch need the shop area.
 */
class BranchBlockPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAnyArea('closures', 'shop');
    }

    public function view(User $user, BranchBlock $block): bool
    {
        return $this->viewAny($user) && ($block->branch_id === null || $user->canManageBranch($block->branch_id));
    }

    public function create(User $user): bool
    {
        return $user->canAccessArea('shop') || ($user->canAccessArea('closures') && $user->branchIds() !== []);
    }

    public function update(User $user, BranchBlock $block): bool
    {
        return $user->canAccessArea('shop') || ($block->branch_id !== null && $user->canAccessArea('closures') && $user->canManageBranch($block->branch_id));
    }

    public function delete(User $user, BranchBlock $block): bool
    {
        return $this->update($user, $block);
    }

    public function deleteAny(User $user): bool
    {
        return $user->canAccessArea('shop');
    }
}
