<?php

namespace App\Support;

use App\Models\Guest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push messages to a customer's phones through Expo's push service. Dormant:
 * it does nothing until PUSH_ENABLED=true (and the Android FCM / iOS APNs
 * credentials are added to the Expo project), and nothing calls it yet.
 */
final class PushNotifier
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public static function enabled(): bool
    {
        return (bool) config('services.push.enabled');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return bool whether a message was handed to Expo
     */
    public static function toGuest(Guest $guest, string $title, string $body, array $data = []): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $tokens = $guest->deviceTokens()->pluck('token');

        if ($tokens->isEmpty()) {
            return false;
        }

        try {
            Http::timeout(5)
                ->when(filled(config('services.push.expo_access_token')), fn ($request) => $request->withToken(config('services.push.expo_access_token')))
                ->post(self::ENDPOINT, $tokens->map(fn (string $to) => ['to' => $to, 'title' => $title, 'body' => $body, 'data' => (object) $data])->values()->all())
                ->throw();

            return true;
        } catch (Throwable $e) {
            Log::warning("Could not send a push message to guest {$guest->id}: {$e->getMessage()}");

            return false;
        }
    }
}
