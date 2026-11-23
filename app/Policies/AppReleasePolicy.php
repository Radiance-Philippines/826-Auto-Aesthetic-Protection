<?php

namespace App\Policies;

use App\Models\AppRelease;
use App\Models\User;

/**
 * Super admin only.
 */
class AppReleasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, AppRelease $record): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, AppRelease $record): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, AppRelease $record): bool
    {
        return $user->isOwner();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isOwner();
    }
}
