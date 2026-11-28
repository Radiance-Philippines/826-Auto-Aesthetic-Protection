<?php

namespace App\Support;

use App\Models\SecurityEvent;
use App\Models\User;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Exceptions\InvalidPasskey;
use Spatie\LaravelPasskeys\Models\Passkey;
use Spatie\LaravelPasskeys\Support\Config;

/**
 * A person's own passkeys, for both profile pages (the panels' and the
 * React admin's). The cryptography is spatie/laravel-passkeys'; sign-in with
 * one is PasskeyLoginController.
 */
class Passkeys
{
    /** Where the one-time challenge waits for the device's answer */
    private const OPTIONS = 'passkey-registration-options';

    /**
     * The browser's request to create a passkey: a one-time challenge for this person.
     */
    public static function registrationOptions(User $user): string
    {
        $options = Config::getAction('generate_passkey_register_options', GeneratePasskeyRegisterOptionsAction::class)->execute($user);

        session()->put(self::OPTIONS, $options);

        return $options;
    }

    /**
     * Checks what the device made (challenge, origin, user verification) and keeps it.
     *
     * @return array{ok: true, name: string}|array{ok: false, message: string}
     */
    public static function store(User $user, string $credential, string $name): array
    {
        $options = session()->pull(self::OPTIONS);

        if (blank($options)) {
            return ['ok' => false, 'message' => 'That took too long. Try adding the passkey again.'];
        }

        $name = trim(mb_substr(strip_tags($name), 0, 60)) ?: 'Passkey '.($user->passkeys()->count() + 1);

        try {
            Config::getAction('store_passkey', StorePasskeyAction::class)
                ->execute($user, $credential, $options, request()->getHost(), ['name' => $name]);
        } catch (InvalidPasskey) {
            return ['ok' => false, 'message' => 'That passkey could not be verified. Try again.'];
        }

        SecurityEvent::record(SecurityEvent::PASSKEY_ADDED, $user, $name);

        return ['ok' => true, 'name' => $name];
    }

    public static function remove(User $user, Passkey $passkey): void
    {
        $passkey->delete();
        SecurityEvent::record(SecurityEvent::PASSKEY_REMOVED, $user, $passkey->name);
    }

    /**
     * What the browser needs to keep the password manager's list in step.
     *
     * @return array{rpId: string, userId: string, ids: list<string>}
     */
    public static function state(User $user): array
    {
        $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return [
            'rpId' => Config::getRelyingPartyId(),
            'userId' => $encode($user->getPassKeyId()),
            'ids' => $user->passkeys()->oldest()->get()->map(fn (Passkey $passkey): string => $encode($passkey->data->publicKeyCredentialId))->all(),
        ];
    }
}
