<?php

namespace App\Policies;

use App\Models\Photo;
use App\Models\User;

/**
 * Needs the content area (a super admin has every area).
 */
class PhotoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessArea('content');
    }

    public function view(User $user, Photo $record): bool
    {
        return $user->canAccessArea('content');
    }

    public function create(User $user): bool
    {
        return $user->canAccessArea('content');
    }

    public function update(User $user, Photo $record): bool
    {
        return $user->canAccessArea('content');
    }

    public function delete(User $user, Photo $record): bool
    {
        return $user->canAccessArea('content');
    }

    public function deleteAny(User $user): bool
    {
        return $user->canAccessArea('content');
    }

    public function reorder(User $user): bool
    {
        return $user->canAccessArea('content');
    }
}
