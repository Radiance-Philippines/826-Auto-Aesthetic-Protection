<?php

namespace App\Policies;

use App\Models\Guest;
use App\Models\User;

/**
 * Branch staff work with the customers who booked at their branches (or
 * whose home branch is theirs); owners with all. Only owners delete.
 */
class GuestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Guest $guest): bool
    {
        return $user->isOwner() || Guest::query()->visibleTo($user)->whereKey($guest->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->isOwner() || $user->branchIds() !== [];
    }

    public function update(User $user, Guest $guest): bool
    {
        return $this->view($user, $guest);
    }

    public function delete(User $user, Guest $guest): bool
    {
        return $user->isOwner();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isOwner();
    }
}
