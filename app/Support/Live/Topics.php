<?php

namespace App\Support\Live;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\User;
use App\Support\InboxAccess;

/**
 * The Flow topics (826.<area>.<id>) and who may read each one. A ticket lists
 * exactly the topics its holder may read, so this is the whole access rule:
 * a team member gets the topics of the branches they work at (every branch
 * for a super admin) for the areas they have, plus their own notifications;
 * a customer gets only their own topic.
 */
final class Topics
{
    public static function queue(int $branchId): string
    {
        return "826.queue.branch.{$branchId}";
    }

    public static function bookings(int $branchId): string
    {
        return "826.bookings.branch.{$branchId}";
    }

    public static function inbox(int $branchId): string
    {
        return "826.inbox.branch.{$branchId}";
    }

    public static function notify(int $userId): string
    {
        return "826.notify.user.{$userId}";
    }

    public static function customer(int $guestId): string
    {
        return "826.customer.{$guestId}";
    }

    /**
     * @return list<string>
     */
    public static function forUser(User $user): array
    {
        $branchIds = $user->isOwner() ? Branch::query()->pluck('id')->map(fn ($id) => (int) $id)->all() : $user->branchIds();
        $topics = [self::notify($user->id)];

        foreach ($branchIds as $id) {
            if ($user->canAccessArea('bookings.view')) {
                $topics[] = self::queue($id);
                $topics[] = self::bookings($id);
            }

            if (InboxAccess::allows($user)) {
                $topics[] = self::inbox($id);
            }
        }

        return $topics;
    }

    /**
     * @return list<string>
     */
    public static function forGuest(Guest $guest): array
    {
        return [self::customer($guest->id)];
    }
}
