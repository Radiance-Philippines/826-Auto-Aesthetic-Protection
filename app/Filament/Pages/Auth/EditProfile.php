<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Concerns\BlockedWhileImpersonating;
use App\Models\SecurityEvent;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Support\Passkeys;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Spatie\LaravelPasskeys\Models\Passkey;

/**
 * The profile (name, email, password, two-factor), plus the passkeys that
 * sign this person in: add one per device, rename, remove, and a read-only
 * list of recent security activity.
 */
class EditProfile extends BaseEditProfile
{
    use BlockedWhileImpersonating;

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return parent::content($schema)->components([
            $this->getFormContentComponent(),
            ...collect([$this->getMultiFactorAuthenticationContentComponent()])->filter()->all(),
            Section::make('Passkeys')
                ->description('Sign in with your fingerprint, face, PIN or a security key instead of a password. A passkey is a strong sign-in on its own, so it skips the 2FA code.')
                ->compact()
                ->secondary()
                ->schema([View::make('filament.passkeys.manage')]),
        ]);
    }

    /**
     * @return Collection<int, Passkey>
     */
    #[Unexposed]
    public function passkeys(): Collection
    {
        return $this->currentUser()->passkeys()->oldest()->get();
    }

    /**
     * @return Collection<int, SecurityEvent>
     */
    #[Unexposed]
    public function securityEvents(): Collection
    {
        return SecurityEvent::query()->where('user_id', $this->getUser()->getKey())->with('actor')->latest('id')->limit(15)->get();
    }

    #[Unexposed]
    public function passkeysAllowed(): bool
    {
        return SecuritySetting::passkeysAllowed();
    }

    /**
     * What the browser needs to keep the password manager's list in step.
     *
     * @return array{rpId: string, userId: string, ids: list<string>}
     */
    #[Exposed]
    public function passkeyState(): array
    {
        return Passkeys::state($this->currentUser());
    }

    /**
     * The browser's request to create a passkey: a one-time challenge for this person.
     */
    #[Exposed]
    public function passkeyOptions(): string
    {
        abort_unless(SecuritySetting::passkeysAllowed(), 403);

        return Passkeys::registrationOptions($this->currentUser());
    }

    /**
     * Checks what the device made and keeps it (App\Support\Passkeys).
     *
     * @return array{ok: bool, message?: string}
     */
    #[Exposed]
    public function storePasskey(string $credential, string $name): array
    {
        abort_unless(SecuritySetting::passkeysAllowed(), 403);

        $result = Passkeys::store($this->currentUser(), $credential, $name);

        if (! $result['ok']) {
            return $result;
        }

        Notification::make()->success()->title("Passkey added: {$result['name']}")->send();

        return ['ok' => true];
    }

    public function renamePasskeyAction(): Action
    {
        return Action::make('renamePasskey')
            ->label('Rename')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->size('sm')
            ->modalWidth('sm')
            ->modalHeading('Rename passkey')
            ->fillForm(fn (array $arguments) => ['name' => $this->ownPasskey($arguments)?->name])
            ->schema([
                TextInput::make('name')->label('Name')->required()->maxLength(60)->placeholder('Work laptop'),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->ownPasskey($arguments)?->update(['name' => trim($data['name'])]);

                Notification::make()->success()->title('Passkey renamed')->send();
            });
    }

    public function removePasskeyAction(): Action
    {
        return Action::make('removePasskey')
            ->label('Remove')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Remove this passkey?')
            ->modalDescription('It can no longer sign you in. Remove it from your device or password manager too.')
            ->action(function (array $arguments): void {
                $passkey = $this->ownPasskey($arguments);

                if (! $passkey) {
                    return;
                }

                Passkeys::remove($this->currentUser(), $passkey);

                Notification::make()->success()->title('Passkey removed')->send();
                $this->js('window.dispatchEvent(new CustomEvent("passkeys-changed"))');
            });
    }

    /**
     * Only ever one of this person's own passkeys, whatever id is sent.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function ownPasskey(array $arguments): ?Passkey
    {
        return $this->currentUser()->passkeys()->whereKey($arguments['passkey'] ?? 0)->first();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
