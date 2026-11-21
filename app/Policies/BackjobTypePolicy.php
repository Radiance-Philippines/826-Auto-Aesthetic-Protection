<?php

namespace App\Policies;

use App\Models\BackjobType;
use App\Models\User;

/**
 * The warranty's setup is the owner's: branch staff can't change it.
 */
class BackjobTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, BackjobType $record): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, BackjobType $record): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, BackjobType $record): bool
    {
        return $user->isOwner();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function reorder(User $user): bool
    {
        return $user->isOwner();
    }
}
