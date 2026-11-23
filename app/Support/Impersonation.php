<?php

namespace App\Support;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * A super admin opening the admin as someone else ("View as this user").
 * The real person's id lives in the session (impersonator_id) and the guard
 * holds the target, so every area, branch and policy check sees the target.
 * ValidateImpersonation re-checks the stored super admin on every request.
 * Web panels only: the staff app's tokens are not involved.
 */
final class Impersonation
{
    public const IMPERSONATOR = 'impersonator_id';

    public const TARGET = 'impersonating_id';

    public const PANEL = 'impersonator_panel';

    public static function active(): bool
    {
        return session()->has(self::IMPERSONATOR);
    }

    /**
     * The stored super admin, only while they still exist and still are one.
     */
    public static function impersonator(): ?User
    {
        $id = session()->get(self::IMPERSONATOR);
        $user = $id ? User::find($id) : null;

        return $user?->isOwner() ? $user : null;
    }

    /**
     * Why this super admin may not view as this person, or null when they may.
     */
    public static function refusal(?User $admin, User $target): ?string
    {
        return match (true) {
            ! $admin?->isOwner() => 'Only a super admin can view as someone else.',
            self::active() => 'Stop viewing as the current person first.',
            $target->is($admin) => 'That is you.',
            $target->isOwner() => 'You cannot view as another super admin.',
            ! $target->canAccessAnyPanel() => 'This person has no panel to open.',
            $target->isPendingInvite() => 'This person has not accepted their invite yet.',
            default => null,
        };
    }

    public static function start(User $admin, User $target, string $panelId): void
    {
        abort_if(self::refusal($admin, $target) !== null, 403);

        session()->put([self::IMPERSONATOR => $admin->getKey(), self::TARGET => $target->getKey(), self::PANEL => $panelId]);
        self::signIn($target);

        SecurityEvent::record(SecurityEvent::IMPERSONATION_STARTED, $target, null, $admin);
    }

    /**
     * Back to the real super admin. When they are no longer one (or gone),
     * the whole session ends instead. Returns the admin, or null in that case.
     */
    public static function stop(): ?User
    {
        $admin = self::impersonator();
        $target = User::find(session()->get(self::TARGET));

        session()->forget([self::IMPERSONATOR, self::TARGET, self::PANEL]);

        if (! $admin) {
            Auth::guard()->logout();
            session()->invalidate();
            session()->regenerateToken();

            return null;
        }

        self::signIn($admin);

        if ($target) {
            SecurityEvent::record(SecurityEvent::IMPERSONATION_STOPPED, $target, null, $admin);
        }

        return $admin;
    }

    /**
     * Same guard and session, so the bell and live updates keep working;
     * login() regenerates the session id and keeps its data.
     */
    private static function signIn(User $user): void
    {
        $guard = Auth::guard();
        $guard->login($user);

        // AuthenticateSession compares this with the signed-in account's password
        Session::put('password_hash_'.Auth::getDefaultDriver(), $guard->hashPasswordForCookie($user->getAuthPassword()));
    }
}
