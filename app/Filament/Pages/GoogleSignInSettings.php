<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\BlockedWhileImpersonating;
use App\Filament\Concerns\ShownInItsPanels;
use App\Models\SiteSetting;
use App\Support\GoogleSignIn;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The Google OAuth client for "Sign in with Google" (owners only).
 * Customers sign in to reserve and follow their bookings, so online
 * booking needs it.
 */
class GoogleSignInSettings extends Page
{
    use BlockedWhileImpersonating, ShownInItsPanels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Google sign-in';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Google sign-in';

    protected static ?string $slug = 'google-sign-in';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canAccessArea('settings');
    }

    public function mount(): void
    {
        $this->form->fill($this->values());
    }

    /**
     * The saved ids (the secret is never filled back in).
     *
     * @return array<string, mixed>
     */
    private function values(): array
    {
        return [
            ...SiteSetting::instance()->only(['google_client_id', 'google_android_client_id', 'google_ios_client_id', 'apple_client_id']),
            'google_client_secret' => null,
        ];
    }

    public function form(Schema $schema): Schema
    {
        $settings = SiteSetting::instance();
        $hasSecret = filled($settings->google_client_secret);

        return $schema
            ->statePath('data')
            ->components([
                Section::make(GoogleSignIn::enabled() ? 'On: customers sign in with Google to reserve' : 'Off: customers can’t reserve online yet')
                    ->description('Make an OAuth client in Google Cloud Console → APIs & Services → Credentials → Create credentials → OAuth client ID → Web application. Add the redirect URI below, then paste the client ID and secret here.')
                    ->components([
                        Text::make('Authorised redirect URI: '.GoogleSignIn::redirectUrl())->copyable(),
                        TextInput::make('google_client_id')
                            ->label('Client ID')
                            ->maxLength(255)
                            ->placeholder('…apps.googleusercontent.com'),
                        TextInput::make('google_client_secret')
                            ->label('Client secret')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->maxLength(255)
                            ->helperText($hasSecret
                                ? 'Saved (hidden). Leave blank to keep it, or paste a new one.'
                                : 'Stored encrypted. Never shown again after saving.'),
                    ]),
                Section::make(GoogleSignIn::appEnabled() || GoogleSignIn::appleEnabled() ? 'Customer app sign-in: on' : 'Customer app sign-in: off')
                    ->description('The 826 app signs customers in with their Google or Apple account. Each button shows in the app only once its client ID is saved here. The app checks these IDs, so they must match the ones the app was built with.')
                    ->components([
                        TextInput::make('google_android_client_id')
                            ->label('Google Android client ID')
                            ->maxLength(255)
                            ->placeholder('…apps.googleusercontent.com')
                            ->helperText('Google Cloud Console → Credentials → OAuth client ID → Android (package ph.radianceph.aap826 and the app’s signing certificate SHA-1).'),
                        TextInput::make('google_ios_client_id')
                            ->label('Google iOS client ID')
                            ->maxLength(255)
                            ->placeholder('…apps.googleusercontent.com')
                            ->helperText('OAuth client ID → iOS (bundle ID ph.radianceph.aap826). Needed once the iPhone app exists.'),
                        TextInput::make('apple_client_id')
                            ->label('Apple client ID')
                            ->maxLength(255)
                            ->placeholder('ph.radianceph.aap826')
                            ->helperText('For Sign in with Apple: the app’s bundle ID (from your Apple Developer account). Needed for the iPhone app.'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label('Save')
                        ->submit('save')
                        ->keyBindings(['mod+s']),
                    Action::make('turnOff')
                        ->label('Turn off Google sign-in')
                        ->icon(Heroicon::OutlinedPower)
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalDescription('Customers can’t reserve online until it’s set up again. Their accounts and bookings stay.')
                        ->visible(fn () => GoogleSignIn::enabled())
                        ->action(function () {
                            SiteSetting::instance()->update(['google_client_id' => null, 'google_client_secret' => null, 'google_android_client_id' => null, 'google_ios_client_id' => null]);
                            $this->form->fill($this->values());
                            Notification::make()->success()->title('Google sign-in is off')->send();
                        }),
                ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        if (blank($data['google_client_secret'] ?? null)) {
            unset($data['google_client_secret']);
        }

        SiteSetting::instance()->update($data);
        $this->form->fill($this->values());

        Notification::make()->success()
            ->title(GoogleSignIn::enabled() ? 'Google sign-in is on' : 'Saved')
            ->body(GoogleSignIn::enabled() ? 'Customers now sign in with Google to reserve.' : 'Add both the client ID and the secret to turn it on.')
            ->send();
    }
}
