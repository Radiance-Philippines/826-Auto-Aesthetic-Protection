<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Support\GoogleSignIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The React admin's Google sign-in, like the Filament page (settings area,
 * closed while viewing as someone). The client secret is write-only: it is
 * never sent back, and a blank box keeps it.
 */
class ConsoleGoogleSignInController extends Controller
{
    private const IDS = ['clientId' => 'google_client_id', 'androidClientId' => 'google_android_client_id', 'iosClientId' => 'google_ios_client_id', 'appleClientId' => 'apple_client_id'];

    public function show(): JsonResponse
    {
        return $this->record();
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(
            array_fill_keys([...array_keys(self::IDS), 'clientSecret'], ['nullable', 'string', 'max:255']),
            [],
            ['clientId' => 'client ID', 'clientSecret' => 'client secret', 'androidClientId' => 'Android client ID', 'iosClientId' => 'iOS client ID', 'appleClientId' => 'Apple client ID'],
        );
        $fields = [];

        foreach (self::IDS as $name => $column) {
            if (array_key_exists($name, $data)) {
                $fields[$column] = $data[$name];
            }
        }

        if (filled($data['clientSecret'] ?? null)) {
            $fields['google_client_secret'] = $data['clientSecret'];
        }

        SiteSetting::instance()->update($fields);
        $on = GoogleSignIn::enabled();

        return $this->record([
            'title' => $on ? 'Google sign-in is on' : 'Saved',
            'body' => $on ? 'Customers now sign in with Google to reserve.' : 'Add both the client ID and the secret to turn it on.',
        ]);
    }

    public function turnOff(): JsonResponse
    {
        abort_unless(GoogleSignIn::enabled(), 403, 'Google sign-in is already off.');

        GoogleSignIn::turnOff();

        return $this->record(['title' => 'Google sign-in is off', 'body' => null]);
    }

    /**
     * @param  array{title: string, body: string|null}|null  $said
     */
    private function record(?array $said = null): JsonResponse
    {
        $settings = SiteSetting::instance();

        return response()->json([
            ...collect(self::IDS)->map(fn (string $column) => $settings->{$column})->all(),
            'hasSecret' => filled($settings->google_client_secret),
            'enabled' => GoogleSignIn::enabled(),
            'appEnabled' => GoogleSignIn::appEnabled() || GoogleSignIn::appleEnabled(),
            'redirectUrl' => GoogleSignIn::redirectUrl(),
            'said' => $said,
        ])->header('Cache-Control', 'no-store');
    }
}
