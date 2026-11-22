<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Support\InboxAccess;

/**
 * A branch's Messenger chats are private to that branch: its staff see,
 * answer, assign and link them; staff of another branch never do. Only a
 * super admin works with every branch's chats.
 */
class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return InboxAccess::allows($user);
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return InboxAccess::allows($user) && $user->canManageBranch($conversation->branch_id);
    }

    /**
     * Reply, mark done or open, assign and link a customer.
     */
    public function update(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
