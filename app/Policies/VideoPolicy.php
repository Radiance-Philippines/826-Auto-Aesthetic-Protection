<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Video;

/**
 * Needs the content area (a super admin has every area).
 */
class VideoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessArea('content');
    }

    public function view(User $user, Video $record): bool
    {
        return $user->canAccessArea('content');
    }

    public function create(User $user): bool
    {
        return $user->canAccessArea('content');
    }

    public function update(User $user, Video $record): bool
    {
        return $user->canAccessArea('content');
    }

    public function delete(User $user, Video $record): bool
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
