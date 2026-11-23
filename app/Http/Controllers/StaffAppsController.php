<?php

namespace App\Http\Controllers;

use App\Models\AppRelease;
use App\Support\AppLauncher;
use App\Support\Staff\TeamApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The team apps' app drawer: the tiles of AppLauncher for this person, the
 * app named by X-Team-App as `current` (the legacy app has none). A panel
 * app's `url` is its published APK download, left out until one is published.
 */
class StaffAppsController extends StaffController
{
    /**
     * GET /api/staff/apps
     */
    public function index(Request $request): JsonResponse
    {
        $apps = array_map(function (array $tile) {
            if ($tile['state'] === AppLauncher::SOON) {
                return ['key' => $tile['key'], 'label' => $tile['label'], 'icon' => $tile['glyph'], 'state' => $tile['state']];
            }

            $url = AppRelease::latestPublished($tile['key'])?->downloadUrl();

            return ['key' => $tile['key'], 'label' => TeamApps::label($tile['key']), 'icon' => $tile['glyph'], 'state' => $tile['state']] + ($url ? ['url' => $url] : []);
        }, AppLauncher::tiles($this->user(), $this->teamApp($request)));

        return response()->json(['apps' => $apps])->header('Cache-Control', 'no-store');
    }
}
