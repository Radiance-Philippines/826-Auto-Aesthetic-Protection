<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

/**
 * Staff work with their own branches' bookings; a super admin with all.
 * Seeing needs bookings.view; adding and changing a booking's customer
 * needs bookings.sales; the unit's details can also be corrected by whoever
 * moves it through the queue (queue.update). Only a super admin deletes.
 */
class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessArea('bookings.view');
    }

    public function view(User $user, Booking $booking): bool
    {
        return $user->canAccessArea('bookings.view') && $user->canManageBranch($booking->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->canAccessArea('bookings.sales') && ($user->isOwner() || $user->branchIds() !== []);
    }

    public function update(User $user, Booking $booking): bool
    {
        return $user->canAccessAnyArea('bookings.sales', 'queue.update') && $user->canManageBranch($booking->branch_id);
    }

    public function delete(User $user, Booking $booking): bool
    {
        return $user->isOwner();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isOwner();
    }
}
