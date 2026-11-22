<?php

namespace App\Policies;

use App\Models\BackjobType;
use App\Models\User;

/**
 * Warranty setup needs the shop area.
 */
class BackjobTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessArea('shop');
    }

    public function view(User $user, BackjobType $record): bool
    {
        return $user->canAccessArea('shop');
    }

    public function create(User $user): bool
    {
        return $user->canAccessArea('shop');
    }

    public function update(User $user, BackjobType $record): bool
    {
        return $user->canAccessArea('shop');
    }

    public function delete(User $user, BackjobType $record): bool
    {
        return $user->canAccessArea('shop');
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
