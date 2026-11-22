<?php

use App\Models\User;
use App\Passkeys\ConfigureCeremonySteps;
use App\Passkeys\GenerateAuthenticationOptions;
use App\Passkeys\GenerateRegistrationOptions;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Models\Passkey;

return [
    /*
     * Where a passkey sign-in lands when there is no page to return to.
     */
    'redirect_to_after_login' => '/admin',

    /*
     * Registration and sign-in options are ours: both require user
     * verification (PIN, fingerprint or face), so a passkey sign-in is a
     * strong login on its own. The verification itself (challenge, origin,
     * signature, counter) stays the package's.
     */
    'actions' => [
        'generate_passkey_register_options' => GenerateRegistrationOptions::class,
        'store_passkey' => StorePasskeyAction::class,
        'generate_passkey_authentication_options' => GenerateAuthenticationOptions::class,
        'find_passkey' => FindPasskeyToAuthenticateAction::class,
        'configure_ceremony_step_manager_factory' => ConfigureCeremonySteps::class,
    ],

    /*
     * The relying party. Leave PASSKEY_RP_ID empty and the id is the host the
     * admin is opened on (127.0.0.1, localhost, a demo or the real domain),
     * which is what a browser requires. Set it only to share passkeys across
     * subdomains (the parent domain).
     */
    'relying_party' => [
        'name' => env('PASSKEY_RP_NAME', config('app.name')),
        'id' => env('PASSKEY_RP_ID'),
        'icon' => null,
    ],

    'models' => [
        'passkey' => Passkey::class,
        'authenticatable' => env('AUTH_MODEL', User::class),
    ],
];
