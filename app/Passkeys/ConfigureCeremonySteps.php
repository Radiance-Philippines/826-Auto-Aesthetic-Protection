<?php

namespace App\Passkeys;

use Spatie\LaravelPasskeys\Actions\ConfigureCeremonyStepManagerFactoryAction;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * WebAuthn needs https; a developer's machine is the exception, so on
 * local and testing environments (never in production) localhost is
 * accepted over plain http.
 */
class ConfigureCeremonySteps extends ConfigureCeremonyStepManagerFactoryAction
{
    public function execute(): CeremonyStepManagerFactory
    {
        $factory = parent::execute();

        if (app()->environment('local', 'testing')) {
            $factory->setSecuredRelyingPartyId(['localhost', '127.0.0.1']);
        }

        return $factory;
    }
}
