<?php

namespace App\Filament\Pages;

use App\Models\ReferralSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Referral codes (owners only): turn them on, set what a customer saves
 * by using a friend's code on their first booking, and the points the code's
 * owner earns once that booking exits. Nothing is set until you do.
 */
class ReferralSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Referral';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Referral';

    protected static ?string $slug = 'referral';

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
        $this->form->fill(ReferralSetting::instance()->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Referral codes')
                    ->description('Every customer gets a code to share. A friend with no earlier booking enters it when they reserve, and the discount shows once the branch has seen their unit and priced it.')
                    ->columns(2)
                    ->components([
                        Toggle::make('enabled')
                            ->label('Accept referral codes')
                            ->helperText('Needs a discount below. Off hides the code box on the site.')
                            ->live()
                            ->columnSpanFull(),
                        Select::make('discount_type')
                            ->label('The friend gets')
                            ->options(ReferralSetting::TYPES)
                            ->required()
                            ->live(),
                        TextInput::make('discount_value')
                            ->label(fn (Get $get) => $get('discount_type') === 'percent' ? 'Percent off the price' : 'Pesos off the price')
                            ->prefix(fn (Get $get) => $get('discount_type') === 'percent' ? null : '₱')
                            ->suffix(fn (Get $get) => $get('discount_type') === 'percent' ? '%' : null)
                            ->integer()
                            ->minValue(1)
                            ->maxValue(fn (Get $get) => $get('discount_type') === 'percent' ? 100 : 10_000_000)
                            ->required(fn (Get $get) => (bool) $get('enabled'))
                            ->helperText('Never more than the price.'),
                        TextInput::make('owner_points')
                            ->label('Points for the code’s owner')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(1_000_000)
                            ->helperText('Earned once, when the friend’s booking exits. Leave blank for none.'),
                        TextInput::make('peso_per_point')
                            ->label('Pesos one point is worth')
                            ->prefix('₱')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100_000)
                            ->helperText('Set by you. Blank means points can’t be redeemed for a discount; the team can still adjust points with a note.'),
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
                        ->label('Save referral')
                        ->submit('save')
                        ->keyBindings(['mod+s']),
                ]),
            ]);
    }

    public function save(): void
    {
        ReferralSetting::instance()->update($this->form->getState());

        Notification::make()->success()->title('Referral saved')->send();
    }
}
