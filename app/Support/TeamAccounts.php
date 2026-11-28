<?php

namespace App\Support;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * The rules for the team's accounts, shared by the Filament Users resource
 * and the React admin: which areas can be given to one account, when a
 * list is stored, and what must never happen to the last super admin.
 */
final class TeamAccounts
{
    /**
     * The areas that can be ticked for one account: all but Users, which
     * stays with super admins.
     *
     * @return array<string, string>
     */
    public static function areaOptions(): array
    {
        return Arr::except(Access::AREAS, ['users']);
    }

    /**
     * The extra-access ticks as stored: null while they match the role's default.
     *
     * @param  array<int, string>  $ticked
     * @return list<string>|null
     */
    public static function ownPermissions(string $role, array $ticked): ?array
    {
        $areas = array_keys(self::areaOptions());
        $access = array_values(array_intersect($areas, $ticked));
        $default = array_values(array_intersect($areas, Access::defaults($role)));
        sort($access);
        sort($default);

        return $role === Access::SUPER_ADMIN || $access === $default ? null : $access;
    }

    /**
     * Whether giving this account that role would leave nobody as super admin.
     */
    public static function wouldRemoveLastSuperAdmin(User $user, string $role): bool
    {
        return $user->isOwner() && $role !== Access::SUPER_ADMIN && self::isOnlySuperAdmin();
    }

    /**
     * Super admins only; not yourself, and never the last super admin.
     */
    public static function canDelete(?User $actor, User $record): bool
    {
        return (bool) $actor?->isOwner()
            && ! $record->is($actor)
            && ! ($record->isOwner() && self::isOnlySuperAdmin());
    }

    /**
     * For a lost or stolen device: removes every passkey of that person and
     * says so in the security activity.
     */
    public static function removePasskeys(User $user, User $actor): void
    {
        $count = $user->passkeys()->count();
        $user->passkeys()->delete();
        SecurityEvent::record(SecurityEvent::PASSKEYS_CLEARED, $user, $count === 1 ? '1 passkey' : "{$count} passkeys", $actor);
    }

    private static function isOnlySuperAdmin(): bool
    {
        return User::query()->where('role', Access::SUPER_ADMIN)->count() <= 1;
    }
}
