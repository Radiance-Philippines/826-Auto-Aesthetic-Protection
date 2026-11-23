<?php

namespace App\Support;

use App\Models\User;

/**
 * The tiles of the app launcher (the grid in the web panels' top bar and
 * the drawer in the four team apps): the panels this person may open, then
 * the planned apps (config/apps.php "coming") for the super admin only. This
 * is the one place that decides who gets which tile, so changing the policy
 * (per department instead of per panel) is a change here.
 */
final class AppLauncher
{
    public const CURRENT = 'current';

    public const AVAILABLE = 'available';

    public const SOON = 'soon';

    /**
     * @param  string|null  $current  the panel (= team app key) being used, if any
     * @return list<array{key: string, label: string, icon: string, glyph: string, state: string}>
     */
    public static function tiles(User $user, ?string $current): array
    {
        $tiles = array_map(fn (string $id) => [
            'key' => $id,
            'label' => ucfirst($id),
            'icon' => config("apps.panels.{$id}.icon"),
            'glyph' => config("apps.panels.{$id}.glyph"),
            'state' => $id === $current ? self::CURRENT : self::AVAILABLE,
        ], Panels::idsFor($user));

        if ($user->isOwner()) {
            foreach (config('apps.coming', []) as $app) {
                $tiles[] = ['key' => $app['key'], 'label' => $app['label'], 'icon' => $app['icon'], 'glyph' => $app['glyph'], 'state' => self::SOON];
            }
        }

        return $tiles;
    }

    /**
     * Whether there is anything to switch to: more than the one tile.
     *
     * @param  list<array<string, string>>  $tiles
     */
    public static function worthShowing(array $tiles): bool
    {
        return count($tiles) > 1;
    }
}
