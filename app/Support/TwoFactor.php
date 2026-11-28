<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use PragmaRX\Google2FAQRCode\Google2FA as Google2FAQRCode;

/**
 * The authenticator app and its recovery codes, in the format the panels
 * have always stored: the secret and the list of hashed codes in the user's
 * encrypted columns. The codes themselves are pragmarx/google2fa's. No
 * Filament class is needed to read any of it (those two packages and
 * chillerlan/php-qrcode are installed through Filament: when it goes, they
 * become composer.json's own).
 */
class TwoFactor
{
    /** Steps of 30 seconds either side that a code still counts, as the panels allow */
    private const WINDOW = 8;

    private const RECOVERY_CODES = 8;

    /**
     * A 6-digit code from the app, or a recovery code (which is used up).
     */
    public static function passes(User $user, string $code): bool
    {
        if (blank($user->app_authentication_secret)) {
            return false;
        }

        $digits = preg_replace('/\s+/', '', $code);

        return preg_match('/^\d{6}$/', $digits)
            ? self::codeMatches($user->app_authentication_secret, $digits)
            : self::useRecoveryCode($user, trim($code));
    }

    /**
     * A code works once: neither it nor an older one is taken again. The cache
     * key is the panels' own, so a code used on their pages is used here too.
     */
    public static function codeMatches(string $secret, string $code): bool
    {
        $digits = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{6}$/', $digits)) {
            return false;
        }

        $key = 'filament:auth:app_authentication:code:'.hash('sha256', $secret);

        return Cache::lock("{$key}:lock", 60)->block(10, function () use ($key, $secret, $digits): bool {
            $google2fa = app(Google2FA::class);
            $last = Cache::get($key) ?? $google2fa->getTimestamp() - self::WINDOW - 1;
            $step = $google2fa->verifyKeyNewer($secret, $digits, (int) $last, self::WINDOW);

            if ($step === false) {
                return false;
            }

            Cache::forever($key, $step);

            return true;
        });
    }

    public static function newSecret(): string
    {
        return app(Google2FA::class)->generateSecretKey(16);
    }

    /**
     * The QR code an authenticator app scans, as a data: address.
     */
    public static function qrCode(string $issuer, string $holder, string $secret): string
    {
        return (new Google2FAQRCode)->getQRCodeInline($issuer, $holder, $secret);
    }

    /**
     * @return list<string>
     */
    public static function newRecoveryCodes(): array
    {
        return Collection::times(self::RECOVERY_CODES, fn (): string => Str::random(10).'-'.Str::random(10))->all();
    }

    /**
     * Stored hashed; the plain ones are shown once, by the caller.
     *
     * @param  list<string>|null  $codes
     */
    public static function saveRecoveryCodes(User $user, ?array $codes): void
    {
        $user->saveAppAuthenticationRecoveryCodes($codes === null ? null : array_map(fn (string $code): string => Hash::make($code), $codes));
    }

    private static function useRecoveryCode(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            // Locked: two requests with the same code must not both pass
            $locked = User::query()->lockForUpdate()->find($user->getKey());
            $codes = $locked?->app_authentication_recovery_codes ?? [];
            $left = array_values(array_filter($codes, fn (string $hash): bool => ! Hash::check($code, $hash)));

            if (count($left) === count($codes)) {
                return false;
            }

            $locked->saveAppAuthenticationRecoveryCodes($left);

            return true;
        });
    }
}
