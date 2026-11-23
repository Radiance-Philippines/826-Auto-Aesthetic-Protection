<?php

namespace App\Http\Controllers;

use App\Filament\Pages\SystemHealth;
use App\Models\AppRelease;
use App\Models\SecuritySetting;
use App\Support\Staff\TeamApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The 826 System app's one tab: what is switched on (yes or no), the live
 * app versions and the security switches. Booleans and versions only,
 * never a key, token or password. Only the `system` app, only the settings area.
 */
class StaffSystemController extends StaffController
{
    /**
     * GET /api/staff/system
     */
    public function show(Request $request): JsonResponse
    {
        abort_unless($this->teamApp($request) === 'system', 403, 'Your role doesn’t include this.');
        $this->requireArea('settings');

        $customer = AppRelease::latestPublished();
        $security = SecuritySetting::current();

        return response()->json([
            'health' => SystemHealth::health(),
            'customerApp' => $customer ? [
                'version' => $customer->version,
                'versionCode' => $customer->version_code,
                'publishedAt' => $customer->created_at?->toIso8601String(),
            ] : null,
            'teamApps' => array_map(fn (string $app) => [
                'app' => $app,
                'label' => TeamApps::label($app),
                'version' => AppRelease::latestPublished($app)?->version,
            ], TeamApps::keys()),
            'security' => [
                'requireTwoFactor' => (bool) $security->require_two_factor,
                'passkeysAllowed' => SecuritySetting::passkeysAllowed(),
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
