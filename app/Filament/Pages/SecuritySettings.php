<?php

namespace App\Filament\Pages;

use App\Models\SecuritySetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Sign-in security (settings area): make two-factor sign-in compulsory.
 * Everyone can still set it up from their profile whether this is on or
 * not; a locked-out user is reset from Users.
 */
class SecuritySettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Security';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Security';

    protected static ?string $slug = 'security';

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
        $this->form->fill(SecuritySetting::instance()->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Two-factor sign-in')
                    ->description('Each person can turn on an authenticator app (with recovery codes) or an emailed code from their profile, under the user menu.')
                    ->components([
                        Toggle::make('require_two_factor')
                            ->label('Require two-factor sign-in for everyone')
                            ->helperText('When on, anyone without it is sent to set it up before they can use the admin. The email code only works once email is set up in Email settings, so the app is the safer choice.'),
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
                        ->label('Save security')
                        ->submit('save')
                        ->keyBindings(['mod+s']),
                ]),
            ]);
    }

    public function save(): void
    {
        SecuritySetting::instance()->update($this->form->getState());

        Notification::make()->success()->title('Security saved')->send();
    }
}
