<?php

namespace App\Filament\Pages\Auth;

use App\Models\SecuritySetting;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\View\PanelsRenderHook;

/**
 * The password form, plus "Sign in with a passkey" below it (and passkeys
 * in the email field's autofill list) while a super admin allows it.
 */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->autocomplete(fn (): string => SecuritySetting::passkeysAllowed() ? 'username webauthn' : 'email');
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE),
            $this->getFormContentComponent(),
            View::make('filament.passkeys.login')
                ->visible(fn (): bool => blank($this->userUndertakingMultiFactorAuthentication) && SecuritySetting::passkeysAllowed()),
            $this->getMultiFactorChallengeFormContentComponent(),
            RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER),
        ]);
    }
}
