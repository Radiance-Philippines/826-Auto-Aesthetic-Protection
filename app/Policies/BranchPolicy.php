<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

/**
 * Needs the shop area (a super admin has every area).
 */
class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessArea('shop');
    }

    public function view(User $user, Branch $record): bool
    {
        return $user->canAccessArea('shop');
    }

    public function create(User $user): bool
    {
        return $user->canAccessArea('shop');
    }

    public function update(User $user, Branch $record): bool
    {
        return $user->canAccessArea('shop');
    }

    /**
     * A branch with bookings stays (set it to Draft to hide it).
     */
    public function delete(User $user, Branch $record): bool
    {
        return $user->canAccessArea('shop') && ! $record->bookings()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return $user->canAccessArea('shop');
    }

    public function reorder(User $user): bool
    {
        return $user->canAccessArea('shop');
    }
}
