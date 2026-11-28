<?php

namespace App\Http\Controllers;

use App\Models\SecuritySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The React admin's Security settings, like the Filament page (settings area,
 * closed while viewing as someone).
 */
class ConsoleSecuritySettingController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->record();
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate(['requireTwoFactor' => ['required', 'boolean'], 'allowPasskeys' => ['required', 'boolean']]);

        SecuritySetting::instance()->update([
            'require_two_factor' => $request->boolean('requireTwoFactor'),
            'allow_passkeys' => $request->boolean('allowPasskeys'),
        ]);

        return $this->record();
    }

    private function record(): JsonResponse
    {
        $settings = SecuritySetting::instance();

        return response()->json([
            'requireTwoFactor' => (bool) $settings->require_two_factor,
            'allowPasskeys' => $settings->allow_passkeys ?? true,
        ])->header('Cache-Control', 'no-store');
    }
}
