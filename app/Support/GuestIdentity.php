<?php

namespace App\Support;

use App\Models\Guest;

/**
 * Finds or makes the customer behind a Google or Apple sign-in. Shared by
 * the site's Google redirect and the app's ID-token sign-in, so a customer
 * is the same person on both.
 */
final class GuestIdentity
{
    /**
     * A customer the team added earlier (same email, no Google account yet)
     * keeps their history, but only when Google says the email is verified:
     * the team typed that email in, so it proves nothing by itself.
     */
    public static function fromGoogle(string $googleId, string $email, ?string $name, ?string $avatar, bool $emailVerified): Guest
    {
        if ($emailVerified && ! Guest::query()->where('google_id', $googleId)->exists()) {
            self::walkInWithEmail($email, 'google_id')?->update(['google_id' => $googleId]);
        }

        return Guest::updateOrCreate(
            ['google_id' => $googleId],
            [
                'name' => filled($name) ? $name : $email,
                'email' => $email,
                'avatar_url' => $avatar,
                'last_signed_in_at' => now(),
            ],
        );
    }

    /**
     * Apple shares the name only the first time and may hide the email
     * behind a private relay: a relay address never adopts a walk-in
     * customer (it proves nothing about who they are).
     */
    public static function fromApple(string $appleId, ?string $email, ?string $name, bool $emailVerified, bool $privateRelay): Guest
    {
        $existing = Guest::query()->where('apple_id', $appleId)->first();

        if (! $existing && $email && $emailVerified && ! $privateRelay) {
            self::walkInWithEmail($email, 'apple_id')?->update(['apple_id' => $appleId]);
            $existing = Guest::query()->where('apple_id', $appleId)->first();
        }

        if ($existing) {
            $existing->fill(array_filter([
                'name' => $existing->name ?: $name,
                'email' => $existing->email ?: $email,
            ]));
            $existing->last_signed_in_at = now();

            return tap($existing)->save();
        }

        return Guest::create([
            'apple_id' => $appleId,
            'name' => filled($name) ? $name : ($email ?: 'Apple customer'),
            'email' => $email,
            'source' => 'apple',
            'last_signed_in_at' => now(),
        ]);
    }

    /**
     * The oldest customer with this email who has no google_id (or apple_id) yet.
     */
    private static function walkInWithEmail(string $email, string $missingColumn): ?Guest
    {
        return Guest::query()
            ->whereNull($missingColumn)
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->oldest('id')
            ->first();
    }
}
