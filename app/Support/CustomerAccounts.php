<?php

namespace App\Support;

use App\Mail\CustomerAccountMail;
use App\Models\Guest;
use App\Models\MailSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Customers' own accounts (email and password), for the site and the app.
 *
 * The rules that keep it safe:
 *  - Signing up never reveals whether an address already has an account, and
 *    never takes over an existing customer: the account is a NEW guest, and
 *    only when its emailed link is opened (the address is proven) does a
 *    walk-in customer with the same address join it (see markVerified).
 *  - The emailed verification link depends on the password, so signing up
 *    again with the same address replaces an unproven password and
 *    cancels older links.
 *  - Without a working mailbox, production refuses sign-up. The demo and a
 *    local machine verify the account at once and send nothing.
 */
final class CustomerAccounts
{
    /** The password broker in config/auth.php */
    public const BROKER = 'guests';

    /** A verification link works this long. */
    public const VERIFY_HOURS = 24;

    /** The same kind of email goes to the same address once a minute. */
    private const MAIL_COOLDOWN_SECONDS = 60;

    /**
     * The shop's mailbox is set up, so emails go out.
     */
    public static function mailReady(): bool
    {
        return MailSetting::instance()->isConfigured();
    }

    /**
     * No mailbox, but this is the demo or a local machine: accounts are
     * verified on the spot and nothing is emailed.
     */
    public static function autoVerify(): bool
    {
        return ! self::mailReady() && Guest::demoSignInAllowed();
    }

    /**
     * Sign-up by email works here (emails go out, or the demo auto-verifies).
     */
    public static function available(): bool
    {
        return self::mailReady() || self::autoVerify();
    }

    public static function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * Signs up. Returns "verified" (demo: the account is ready, signed in
     * by the caller), "pending" (the email goes out) or "exists" (demo
     * only: production never says so). The guest comes back with "verified".
     *
     * @return array{status: string, guest: ?Guest}
     */
    public static function register(string $name, string $email, string $password, ?string $phone): array
    {
        $email = self::normalize($email);
        $auto = self::autoVerify();

        $account = Guest::query()->where('login_email', $email)->first();
        $google = Guest::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->where(fn ($query) => $query->whereNotNull('google_id')->orWhereNotNull('apple_id'))
            ->oldest('id')
            ->first();

        // Already theirs: tell the owner by email (the page says the same thing as for a new address)
        if ($account?->email_verified_at || $google) {
            if ($auto) {
                return ['status' => 'exists', 'guest' => null];
            }

            self::send('existing', $account?->email_verified_at ? $account : $google);

            return ['status' => 'pending', 'guest' => null];
        }

        try {
            $guest = DB::transaction(function () use ($account, $name, $email, $password, $phone) {
                if ($account) {
                    // Never proven by anyone: the newest sign-up wins, and its link is the only valid one
                    $account->forceFill(['name' => $name, 'phone' => $phone, 'password' => $password])->save();

                    return $account;
                }

                $guest = new Guest(['name' => $name, 'email' => $email, 'phone' => $phone, 'source' => 'password']);
                $guest->forceFill(['login_email' => $email, 'password' => $password])->save();

                return $guest;
            });
        } catch (UniqueConstraintViolationException) {
            return ['status' => 'pending', 'guest' => null];
        }

        return self::finishRegistration($guest, $auto);
    }

    /**
     * @return array{status: string, guest: ?Guest}
     */
    private static function finishRegistration(Guest $guest, bool $auto): array
    {
        if ($auto) {
            // The demo has no mailbox to prove anything with: verified, but never joined to a walk-in
            $guest->forceFill(['email_verified_at' => now()])->save();

            return ['status' => 'verified', 'guest' => $guest];
        }

        self::send('verify', $guest);

        return ['status' => 'pending', 'guest' => null];
    }

    /**
     * Opens the emailed link: the address is proven. A walk-in customer
     * the team added with the same address (no Google, Apple or password
     * of their own) is the same person: their bookings and units stay, and
     * they get this password. Returns the customer, or null for a link that
     * doesn't match any account.
     */
    public static function verify(int $id, string $hash): ?Guest
    {
        $guest = Guest::query()->whereNotNull('login_email')->find($id);

        if (! $guest || ! hash_equals($guest->verificationHash(), $hash)) {
            return null;
        }

        return $guest->email_verified_at ? $guest : self::markVerified($guest);
    }

    /**
     * Marks the address verified, joining a walk-in customer with the
     * same address when there is one; returns whoever now holds the
     * account. Used for the emailed link and for a finished password reset.
     */
    public static function markVerified(Guest $guest): Guest
    {
        return DB::transaction(function () use ($guest) {
            $walkIn = Guest::query()
                ->whereKeyNot($guest->getKey())
                ->whereRaw('lower(email) = ?', [$guest->login_email])
                ->whereNull('google_id')
                ->whereNull('apple_id')
                ->whereNull('login_email')
                ->oldest('id')
                ->first();

            if (! $walkIn) {
                $guest->forceFill(['email_verified_at' => $guest->email_verified_at ?? now()])->save();

                return $guest;
            }

            $hashed = $guest->getRawOriginal('password');
            $login = $guest->login_email;
            $phone = $guest->phone;

            // The new row has nothing yet (nobody could sign in before this): it goes, the walk-in keeps its history
            $guest->tokens()->delete();
            $guest->delete();

            $walkIn->forceFill([
                'password' => $hashed,
                'login_email' => $login,
                'email' => $login,
                'email_verified_at' => now(),
                'phone' => $walkIn->phone ?: $phone,
            ])->save();

            return $walkIn;
        });
    }

    /**
     * The account for a password sign-in, or null. Always one password
     * check, so a missing account takes as long as a wrong password.
     *
     * @return array{guest: ?Guest, matches: bool}
     */
    public static function attempt(string $email, string $password): array
    {
        static $dummy = null;
        $dummy ??= Hash::make(Str::random(32));

        $guest = Guest::query()->where('login_email', self::normalize($email))->first();
        $matches = Hash::check($password, $guest?->password ?? $dummy) && $guest !== null;

        return ['guest' => $guest, 'matches' => $matches];
    }

    /**
     * Sends the verification email again, when the account is waiting for it.
     */
    public static function resendVerification(string $email): void
    {
        $guest = Guest::query()->where('login_email', self::normalize($email))->whereNull('email_verified_at')->first();

        if ($guest) {
            self::send('verify', $guest);
        }
    }

    /**
     * An email for someone who asked for a password reset: the reset link
     * for a password account, a "you already have an account" note for a
     * Google or Apple customer, nothing for an address we don't know.
     */
    public static function forgot(string $email): void
    {
        $email = self::normalize($email);

        if (Guest::query()->where('login_email', $email)->exists()) {
            Password::broker(self::BROKER)->sendResetLink(['login_email' => $email]);

            return;
        }

        $other = Guest::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->where(fn ($query) => $query->whereNotNull('google_id')->orWhereNotNull('apple_id'))
            ->oldest('id')
            ->first();

        if ($other) {
            self::send('existing', $other);
        }
    }

    public static function verifyUrl(Guest $guest): string
    {
        return URL::temporarySignedRoute('customer.verify', now()->addHours(self::VERIFY_HOURS), ['id' => $guest->getKey(), 'hash' => $guest->verificationHash()]);
    }

    /**
     * The page on the site that takes the new password. The email travels
     * in the link, the token proves it.
     */
    public static function resetUrl(Guest $guest, string $token): string
    {
        return url('/reset-password/'.$token).'?'.http_build_query(['email' => $guest->login_email]);
    }

    /**
     * Emails the guest at their own address, at most once a minute per
     * kind and address. A mailbox that fails is logged, never shown.
     */
    private static function send(string $kind, Guest $guest): void
    {
        if (! Cache::add('customer-mail|'.$kind.'|'.sha1((string) $guest->email), 1, self::MAIL_COOLDOWN_SECONDS)) {
            return;
        }

        try {
            Mail::to($guest->email)->send(new CustomerAccountMail($kind, $guest, $kind === 'verify' ? self::verifyUrl($guest) : null));
        } catch (Throwable $e) {
            Log::warning("Could not email a customer ({$kind}, guest {$guest->getKey()}): {$e->getMessage()}");
        }
    }
}
