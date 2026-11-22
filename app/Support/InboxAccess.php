<?php

namespace App\Support;

use App\Models\User;

/**
 * Who may open the admin inbox: accounts with the inbox area (branch managers
 * and sales admins by default, and super admins) who work at a branch.
 */
final class InboxAccess
{
    public static function allows(User $user): bool
    {
        return $user->canAccessArea('inbox') && ($user->isOwner() || $user->branchIds() !== []);
    }
}
