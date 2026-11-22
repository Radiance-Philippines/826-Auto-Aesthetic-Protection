<?php

namespace App\Passkeys;

use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Support\Config;
use Spatie\LaravelPasskeys\Support\Serializer;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Sign-in options for a discoverable passkey (no email needed), with user
 * verification required: the check on the answer fails without it.
 */
class GenerateAuthenticationOptions extends GeneratePasskeyAuthenticationOptionsAction
{
    public function execute(): string
    {
        $options = Serializer::make()->toJson(new PublicKeyCredentialRequestOptions(
            challenge: Str::random(),
            rpId: Config::getRelyingPartyId(),
            allowCredentials: [],
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: 120_000,
        ));

        Session::put('passkey-authentication-options', $options);

        return $options;
    }
}
