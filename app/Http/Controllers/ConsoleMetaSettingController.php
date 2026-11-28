<?php

namespace App\Http\Controllers;

use App\Models\MetaSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The React admin's Meta (Messenger) settings, like the Filament page
 * (settings area, closed while viewing as someone). The App secret is
 * write-only, and the webhook verify token is sent once, when it is first
 * looked at after being made.
 */
class ConsoleMetaSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->record(MetaSetting::instance());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'appId' => ['nullable', 'string', 'max:50', 'regex:/^[0-9]+$/'],
            'appSecret' => ['nullable', 'string', 'max:1000'],
            'enabled' => ['required', 'boolean'],
        ], ['appId.regex' => 'The App ID is digits only.'], ['appId' => 'App ID', 'appSecret' => 'app secret']);

        $settings = MetaSetting::instance();
        $settings->fill([
            'app_id' => $data['appId'] ?? null,
            'enabled' => $request->boolean('enabled'),
            ...(filled($data['appSecret'] ?? null) ? ['app_secret' => $data['appSecret']] : []),
        ])->save();

        return $this->record($settings);
    }

    public function revealToken(): JsonResponse
    {
        return response()->json(['verifyToken' => MetaSetting::instance()->revealVerifyTokenOnce()])->header('Cache-Control', 'no-store');
    }

    public function newToken(): JsonResponse
    {
        $settings = MetaSetting::instance();
        $token = $settings->regenerateVerifyToken();
        $settings->markVerifyTokenShown();

        return response()->json(['verifyToken' => $token])->header('Cache-Control', 'no-store');
    }

    private function record(MetaSetting $settings): JsonResponse
    {
        return response()->json([
            'appId' => $settings->app_id,
            'enabled' => (bool) $settings->enabled,
            'hasSecret' => filled($settings->app_secret),
            'callbackUrl' => route('webhooks.meta'),
        ])->header('Cache-Control', 'no-store');
    }
}
