<?php

namespace App\Passkeys;

use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Support\Serializer;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;

/**
 * Registration options with user verification required, a two-minute
 * prompt, and the user's existing passkeys excluded so the same device
 * can't be added twice.
 */
class GenerateRegistrationOptions extends GeneratePasskeyRegisterOptionsAction
{
    public function execute(HasPasskeys $authenticatable, bool $asJson = true): string|PublicKeyCredentialCreationOptions
    {
        $options = new PublicKeyCredentialCreationOptions(
            rp: $this->relatedPartyEntity(),
            user: $this->generateUserEntity($authenticatable),
            challenge: $this->challenge(),
            authenticatorSelection: $this->authenticatorSelection(),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $authenticatable->passkeys()->get()
                ->map(fn ($passkey) => PublicKeyCredentialDescriptor::create('public-key', $passkey->data->publicKeyCredentialId))
                ->all(),
            timeout: 120_000,
        );

        return $asJson ? Serializer::make()->toJson($options) : $options;
    }

    public function authenticatorSelection(): AuthenticatorSelectionCriteria
    {
        return new AuthenticatorSelectionCriteria(
            null,
            AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
        );
    }
}
