<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\BlockedWhileImpersonating;
use App\Filament\Concerns\ShownInItsPanels;
use App\Models\MetaSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * The Meta app all branch Pages share (owners only): Messenger chats in the
 * Inbox. The App Secret is write-only (never sent back to the browser, only
 * replaced) and the webhook verify token is shown once, when it is made.
 */
class MetaSettings extends Page
{
    use BlockedWhileImpersonating, ShownInItsPanels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleOvalLeftEllipsis;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Meta (Messenger)';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Meta (Messenger)';

    protected static ?string $slug = 'meta';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * The verify token, only for the page view right after it was made.
     */
    public ?string $newVerifyToken = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canAccessArea('settings');
    }

    public static function webhookUrl(): string
    {
        return route('webhooks.meta');
    }

    public function mount(): void
    {
        $settings = MetaSetting::instance();

        // Shown once: the first time the page is opened after the token was made
        $this->newVerifyToken = $settings->revealVerifyTokenOnce();

        $this->fillForm($settings);
    }

    /**
     * The secret is never put in the form state, so it never reaches the page.
     */
    private function fillForm(MetaSetting $settings): void
    {
        $this->form->fill([
            'app_id' => $settings->app_id,
            'enabled' => $settings->enabled,
            'app_secret' => null,
            'replace_app_secret' => false,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $isSaved = fn (): bool => filled(MetaSetting::instance()->app_secret);

        return $schema
            ->statePath('data')
            ->components([
                Section::make('What to do in Meta')
                    ->description('Do this once. The owner does it, not the branches.')
                    ->components([
                        Text::make(new HtmlString(<<<'HTML'
                            <ol class="list-decimal space-y-2 ps-5 text-sm">
                                <li>Go to <strong>developers.facebook.com</strong> → My Apps → Create app (type Business) and add the <strong>Messenger</strong> product.</li>
                                <li>App settings → Basic: copy the <strong>App ID</strong> and <strong>App secret</strong> into the fields below.</li>
                                <li>Messenger → Webhooks (or Webhooks → Page): paste the <strong>Callback URL</strong> and the <strong>Verify token</strong> from this page, then subscribe to <strong>messages</strong> and <strong>message_echoes</strong>.</li>
                                <li>Messenger → add each branch’s Facebook Page, generate its <strong>Page access token</strong>, and paste the Page ID and token into that branch: Shop → Branches → the branch → <strong>Facebook page</strong> tab → Connect check.</li>
                                <li>Switch on <strong>Receive and send Messenger chats</strong> below and save. Until the app is Live and approved by Meta for pages_messaging, only people with a role on the app can chat with the Pages.</li>
                            </ol>
                        HTML)),
                    ]),
                Section::make('Webhook')
                    ->description('Paste these two into Meta (step 3).')
                    ->columns(2)
                    ->components([
                        TextEntry::make('callback_url')
                            ->label('Callback URL')
                            ->state(self::webhookUrl())
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->copyMessage('Copied'),
                        TextEntry::make('verify_token_shown')
                            ->label('Verify token')
                            ->state(fn (): string => $this->newVerifyToken ?? 'Hidden. Make a new one if you lost it.')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(fn (): bool => $this->newVerifyToken !== null)
                            ->copyMessage('Copied')
                            ->helperText(fn (): string => $this->newVerifyToken !== null ? 'Shown only now. Copy it before you leave this page.' : 'A new token replaces the old one: paste it into Meta again.'),
                    ]),
                Section::make('Meta app')
                    ->description('From App settings → Basic. The App secret is stored encrypted and never shown again.')
                    ->columns(2)
                    ->components([
                        TextInput::make('app_id')
                            ->label('App ID')
                            ->maxLength(50)
                            ->regex('/^[0-9]+$/')
                            ->autocomplete('off'),
                        ...$this->secretFields($isSaved),
                        Toggle::make('enabled')
                            ->label('Receive and send Messenger chats')
                            ->helperText('Off: webhooks are ignored and replies are not sent.')
                            ->inline(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * A write-only input: "Saved ••••" until the owner chooses to replace it.
     *
     * @return list<Component>
     */
    private function secretFields(callable $isSaved): array
    {
        return [
            TextEntry::make('saved_app_secret')
                ->label('App secret')
                ->state('Saved ••••')
                ->visible(fn (Get $get): bool => $isSaved() && ! $get('replace_app_secret')),
            TextInput::make('app_secret')
                ->label(fn (): string => $isSaved() ? 'New app secret' : 'App secret')
                ->password()
                ->revealable(false)
                ->autocomplete('new-password')
                ->maxLength(1000)
                ->visible(fn (Get $get): bool => ! $isSaved() || (bool) $get('replace_app_secret'))
                ->dehydrated(fn (?string $state): bool => filled($state)),
            Toggle::make('replace_app_secret')
                ->label('Replace app secret')
                ->live()
                ->visible($isSaved)
                ->dehydrated(false),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('regenerateToken')
                ->label('Make a new verify token')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Meta must be given the new token again, or it can’t re-check the webhook.')
                ->action(function (): void {
                    $settings = MetaSetting::instance();
                    $this->newVerifyToken = $settings->regenerateVerifyToken();
                    $settings->markVerifyTokenShown();
                    Notification::make()->success()->title('New verify token made')->body('Copy it now: it is not shown again.')->send();
                }),
        ];
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
                ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = MetaSetting::instance();

        $settings->fill([
            'app_id' => filled($data['app_id'] ?? null) ? $data['app_id'] : null,
            'enabled' => (bool) ($data['enabled'] ?? false),
            ...(filled($data['app_secret'] ?? null) ? ['app_secret' => $data['app_secret']] : []),
        ])->save();

        $this->fillForm($settings);

        Notification::make()->success()->title('Meta settings saved')->send();
    }
}
