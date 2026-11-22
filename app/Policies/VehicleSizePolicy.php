<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleSize;

/**
 * Needs the shop area (a super admin has every area).
 */
class VehicleSizePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessArea('shop');
    }

    public function view(User $user, VehicleSize $record): bool
    {
        return $user->canAccessArea('shop');
    }

    public function create(User $user): bool
    {
        return $user->canAccessArea('shop');
    }

    public function update(User $user, VehicleSize $record): bool
    {
        return $user->canAccessArea('shop');
    }

    public function delete(User $user, VehicleSize $record): bool
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
