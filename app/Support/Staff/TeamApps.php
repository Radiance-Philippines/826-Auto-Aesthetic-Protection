<?php

namespace App\Support\Staff;

use App\Models\User;
use App\Support\Panels;
use Illuminate\Http\Request;

/**
 * The team apps in config/team_apps.php: the one a request is from
 * (`X-Team-App`; none = the legacy 826 Team app), its label and the app
 * that fits an account.
 */
final class TeamApps
{
    public const HEADER = 'X-Team-App';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(config('team_apps'));
    }

    public static function label(string $app): string
    {
        return config("team_apps.{$app}.label");
    }

    /**
     * The app a request is from: null without the header (legacy), 422 when it names no app.
     */
    public static function fromRequest(Request $request): ?string
    {
        $app = $request->header(self::HEADER);

        if ($app === null || $app === '') {
            return null;
        }

        abort_unless(in_array($app, self::keys(), true), 422, 'Unknown app.');

        return $app;
    }

    public static function allows(User $user, string $app): bool
    {
        return Panels::canOpen($user, config("team_apps.{$app}.panel"));
    }

    /**
     * The first app (in config order) this account may open.
     */
    public static function suggestFor(User $user): ?string
    {
        foreach (self::keys() as $app) {
            if (self::allows($user, $app)) {
                return $app;
            }
        }

        return null;
    }
}
