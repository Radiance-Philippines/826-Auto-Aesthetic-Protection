<?php

namespace App\Policies;

use App\Models\Guest;
use App\Models\User;

/**
 * Staff work with the customers who booked at their branches (or whose home
 * branch is theirs); a super admin with all. Seeing needs customers.view,
 * adding and editing customers.write. Only a super admin deletes.
 */
class GuestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessArea('customers.view');
    }

    public function view(User $user, Guest $guest): bool
    {
        return $user->canAccessArea('customers.view') && ($user->isOwner() || Guest::query()->visibleTo($user)->whereKey($guest->getKey())->exists());
    }

    public function create(User $user): bool
    {
        return $user->canAccessArea('customers.write') && ($user->isOwner() || $user->branchIds() !== []);
    }

    public function update(User $user, Guest $guest): bool
    {
        return $user->canAccessArea('customers.write') && $this->view($user, $guest);
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
