<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

/**
 * Needs the shop area (a super admin has every area).
 */
class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessArea('shop');
    }

    public function view(User $user, Service $record): bool
    {
        return $user->canAccessArea('shop');
    }

    public function create(User $user): bool
    {
        return $user->canAccessArea('shop');
    }

    public function update(User $user, Service $record): bool
    {
        return $user->canAccessArea('shop');
    }

    public function delete(User $user, Service $record): bool
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
