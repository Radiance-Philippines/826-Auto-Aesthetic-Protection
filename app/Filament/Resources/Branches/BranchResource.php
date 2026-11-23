<?php

namespace App\Filament\Resources\Branches;

use App\Filament\Concerns\ShownInItsPanels;
use App\Filament\Resources\Branches\Pages\CreateBranch;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Models\Branch;
use App\Models\PaymentSetting;
use App\Models\User;
use App\Support\Inbox\InboxException;
use App\Support\Meta\PageConnection;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * 826's branches (owners only): address and contacts, daily cap and open
 * days, which services each offers, home service, its own GCash or Maya, and
 * its staff. A branch takes online bookings once it's Open and nothing is
 * missing.
 */
class BranchResource extends Resource
{
    use ShownInItsPanels;

    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Shop';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Callout::make('Not taking online bookings yet')
                ->warning()
                ->description(fn (?Branch $record) => $record ? self::missingHtml($record) : null)
                ->visible(fn (?Branch $record, string $operation) => $operation === 'edit' && $record !== null && $record->missing() !== []),
            Tabs::make('Branch')
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('Info')
                        ->icon(Heroicon::OutlinedMapPin)
                        ->columns(2)
                        ->schema([
                            TextInput::make('name')
                                ->placeholder('Cainta – Ortigas Extension')
                                ->required()
                                ->maxLength(120)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (?string $state, Set $set, ?Branch $record) {
                                    if (! $record) {
                                        $set('slug', Str::slug((string) $state));
                                    }
                                }),
                            TextInput::make('slug')
                                ->label('Web address')
                                ->prefix('/branches/')
                                ->required()
                                ->maxLength(80)
                                ->alphaDash()
                                ->unique(ignoreRecord: true),
                            Select::make('status')
                                ->options(Branch::STATUSES)
                                ->default('draft')
                                ->required()
                                ->selectablePlaceholder(false)
                                ->helperText('Draft: hidden. Coming soon: listed with no booking. Open: customers reserve online once nothing is missing.'),
                            TextInput::make('city')->maxLength(80),
                            Textarea::make('address')->rows(2)->maxLength(500)->columnSpanFull(),
                            TextInput::make('phone')->tel()->maxLength(40),
                            TextInput::make('messenger_url')->label('Messenger link')->url()->placeholder('https://m.me/…')->maxLength(255),
                            TextInput::make('facebook_url')->label('Facebook page')->url()->placeholder('https://www.facebook.com/…')->maxLength(255),
                            TextInput::make('map_url')->label('Google Maps link')->url()->maxLength(500)->columnSpanFull(),
                            TextInput::make('map_lat')->label('Map pin latitude')->numeric()->minValue(4)->maxValue(22),
                            TextInput::make('map_lng')->label('Map pin longitude')->numeric()->minValue(116)->maxValue(127),
                            TextInput::make('alert_email')
                                ->label('Email new bookings to')
                                ->email()
                                ->maxLength(190)
                                ->helperText('Leave blank to use the address in Email settings.'),
                            TextInput::make('sort_order')->label('Order on the site')->integer()->default(0),
                        ]),
                    Tab::make('Days and cap')
                        ->icon(Heroicon::OutlinedClock)
                        ->columns(2)
                        ->schema([
                            TextInput::make('daily_cap')
                                ->label('Units per day (daily cap)')
                                ->integer()
                                ->minValue(0)
                                ->maxValue(Branch::MAX_DAILY_CAP)
                                ->default(0)
                                ->required()
                                ->helperText('How many units customers can book to drop off on the same day. 0 turns off online bookings.'),
                            TextInput::make('lead_days')
                                ->label('Notice needed')
                                ->suffix('days')
                                ->integer()
                                ->minValue(0)
                                ->maxValue(Branch::MAX_LEAD_DAYS)
                                ->default(1)
                                ->required()
                                ->helperText('The earliest drop-off date, counted from today. 1 means from tomorrow.'),
                            Select::make('months_ahead')
                                ->label('Book up to')
                                ->options(array_combine(range(1, 12), array_map(fn (int $months) => $months === 1 ? '1 month ahead' : "{$months} months ahead", range(1, 12))))
                                ->default(3)
                                ->required()
                                ->selectablePlaceholder(false),
                            Section::make('Weekly hours')
                                ->description('The days the branch is open; the times are shown on the site. A day left off is closed: customers can’t drop off then and it doesn’t count toward a job’s days.')
                                ->columnSpanFull()
                                ->columns(['md' => 2, 'xl' => 3])
                                ->schema(collect(Branch::WEEKDAYS)->map(fn (string $label, int $day) => Fieldset::make($label)
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make("weekly_hours.{$day}.open_today")
                                            ->label('Open')
                                            ->live()
                                            ->columnSpanFull(),
                                        TimePicker::make("weekly_hours.{$day}.open")
                                            ->label('Opens')
                                            ->seconds(false)
                                            ->displayFormat('g:i A')
                                            ->required(fn (Get $get) => (bool) $get("weekly_hours.{$day}.open_today"))
                                            ->visible(fn (Get $get) => (bool) $get("weekly_hours.{$day}.open_today")),
                                        TimePicker::make("weekly_hours.{$day}.close")
                                            ->label('Closes')
                                            ->seconds(false)
                                            ->displayFormat('g:i A')
                                            ->after("weekly_hours.{$day}.open")
                                            ->required(fn (Get $get) => (bool) $get("weekly_hours.{$day}.open_today"))
                                            ->visible(fn (Get $get) => (bool) $get("weekly_hours.{$day}.open_today")),
                                    ]))->values()->all()),
                        ]),
                    Tab::make('Services')
                        ->icon(Heroicon::OutlinedSparkles)
                        ->schema([
                            CheckboxList::make('services')
                                ->label('Offered at this branch')
                                ->relationship('services', 'name', fn ($query) => $query->orderBy('sort_order')->orderBy('name'))
                                ->bulkToggleable()
                                ->columns(['md' => 2])
                                ->helperText('Day estimates and prices are set per service (Shop → Services).'),
                        ]),
                    Tab::make('Home service')
                        ->icon(Heroicon::OutlinedHome)
                        ->schema([
                            Toggle::make('offers_home_service')
                                ->label('Takes home-service requests')
                                ->helperText('Customers send a request with their address; the branch calls to set a time. Only services marked “Can be done at home” are offered.')
                                ->live(),
                            TagsInput::make('home_service_areas')
                                ->label('Areas covered')
                                ->placeholder('Add a city or barangay')
                                ->helperText('Customers pick one of these (or Other). Leave empty to let them type their area.')
                                ->visible(fn (Get $get) => (bool) $get('offers_home_service')),
                        ]),
                    Tab::make('Payments')
                        ->icon(Heroicon::OutlinedQrCode)
                        ->columns(2)
                        ->schema([
                            Callout::make('Optional')
                                ->info()
                                ->description('Add this branch’s own GCash or Maya if its reservation fees go to a different account. With none switched on here, customers pay to the main accounts in Settings → Payments.')
                                ->columnSpanFull(),
                            ...self::paymentFields('gcash', 'GCash'),
                            ...self::paymentFields('maya', 'Maya'),
                        ]),
                    Tab::make('Facebook page')
                        ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                        ->columns(2)
                        ->schema(self::facebookFields()),
                    Tab::make('Staff')
                        ->icon(Heroicon::OutlinedUsers)
                        ->schema([
                            CheckboxList::make('staff')
                                ->label('Team members at this branch')
                                ->relationship('staff', 'name', fn ($query) => $query->where('role', '!=', 'super_admin')->orderBy('name'))
                                ->getOptionLabelFromRecordUsing(fn (User $record) => "{$record->name} ({$record->email})")
                                ->columns(['md' => 2])
                                ->helperText('They see this branch’s bookings, queue and closures. Super admins see every branch. Invite people in Settings → Users.'),
                        ]),
                ]),
        ]);
    }

    /**
     * This branch's Facebook Page, for its Messenger chats in the Inbox. The
     * token is write-only: it is never put in the form or shown again.
     *
     * @return list<Component>
     */
    private static function facebookFields(): array
    {
        return [
            Callout::make('Messenger chats for this branch')
                ->info()
                ->description('Customers who message this Page show up in Inbox under this branch. The owner sets up the Meta app first (Settings → Meta). Paste the Page ID and a Page access token, save, then press Connect check.')
                ->columnSpanFull(),
            TextInput::make('facebook_page_id')
                ->label('Facebook Page ID')
                ->helperText('Page → About → Page transparency.')
                ->regex('/^[0-9]+$/')
                ->maxLength(50)
                ->unique(ignoreRecord: true)
                ->autocomplete('off')
                ->live(onBlur: true),
            TextInput::make('facebook_page_name')
                ->label('Page name')
                ->helperText('Filled in by Connect check.')
                ->maxLength(190)
                ->disabled()
                ->dehydrated(false),
            TextInput::make('facebook_page_token')
                ->label(fn (?Branch $record): string => filled($record?->facebook_page_token) ? 'New Page access token' : 'Page access token')
                ->helperText(fn (?Branch $record): string => filled($record?->facebook_page_token) ? 'A token is saved (hidden). Leave blank to keep it, or paste a new one to replace it.' : 'Generated for this Page in your Meta app (Messenger → Generate token). Stored encrypted.')
                ->password()
                ->revealable(false)
                ->autocomplete('new-password')
                ->maxLength(1000)
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->columnSpanFull(),
            Actions::make([
                Action::make('connectCheck')
                    ->label('Connect check')
                    ->icon(Heroicon::OutlinedSignal)
                    ->color('gray')
                    ->visible(fn (?Branch $record, string $operation): bool => $operation === 'edit' && $record !== null)
                    ->action(function (Get $get, ?Branch $record): void {
                        try {
                            $warning = PageConnection::check($record, (string) $get('facebook_page_id'), $get('facebook_page_token'));
                        } catch (InboxException $exception) {
                            Notification::make()->danger()->persistent()->title('Not connected')->body($exception->getMessage())->send();

                            return;
                        }

                        $notification = Notification::make()
                            ->title('Connected to '.$record->facebook_page_name)
                            ->body($warning ?? 'The token and the Page ID match. New messages arrive in the Inbox.');

                        ($warning === null ? $notification->success() : $notification->warning()->persistent())->send();
                    }),
            ])->columnSpanFull(),
            TextEntry::make('facebook_status')
                ->label('Status')
                ->state(fn (?Branch $record): string => $record?->hasFacebookPage() ? 'Connected '.$record->facebook_connected_at->diffForHumans() : 'Not connected')
                ->columnSpanFull(),
        ];
    }

    /**
     * @return list<Component>
     */
    private static function paymentFields(string $key, string $label): array
    {
        return [
            Section::make($label)
                ->columnSpan(1)
                ->schema([
                    Toggle::make("{$key}_enabled")->label("Use this branch’s {$label}"),
                    FileUpload::make("{$key}_qr")
                        ->label('QR code')
                        ->image()
                        ->disk(PaymentSetting::DISK)
                        ->directory('payments/branches')
                        ->visibility('public')
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                        ->maxSize(5120)
                        ->imagePreviewHeight('160'),
                    TextInput::make("{$key}_name")->label('Account name')->maxLength(120),
                    TextInput::make("{$key}_number")->label("{$label} number")->tel()->maxLength(40),
                ]),
        ];
    }

    public static function missingHtml(Branch $record): HtmlString
    {
        $items = collect($record->missing())->map(fn (string $item) => '<li>'.e($item).'</li>')->implode('');

        return new HtmlString('<ul class="list-disc ps-5">'.$items.'</ul>');
    }

    /**
     * Weekly hours as the form edits them (an "open today" switch per day).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillHours(array $data): array
    {
        $saved = is_array($data['weekly_hours'] ?? null) ? $data['weekly_hours'] : [];
        $hours = [];

        foreach (array_keys(Branch::WEEKDAYS) as $day) {
            $row = $saved[$day] ?? $saved[(string) $day] ?? null;
            $hours[$day] = [
                'open_today' => is_array($row) && filled($row['open'] ?? null),
                'open' => $row['open'] ?? null,
                'close' => $row['close'] ?? null,
            ];
        }

        return [...$data, 'weekly_hours' => $hours];
    }

    /**
     * Back to the stored shape: "HH:MM" times, null for closed days.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function saveHours(array $data): array
    {
        $form = is_array($data['weekly_hours'] ?? null) ? $data['weekly_hours'] : [];
        $hours = [];

        foreach (array_keys(Branch::WEEKDAYS) as $day) {
            $row = $form[$day] ?? $form[(string) $day] ?? [];
            $hours[(string) $day] = ($row['open_today'] ?? false) && Branch::clock($row['open'] ?? null) && Branch::clock($row['close'] ?? null)
                ? [
                    'open' => Branch::clock($row['open']),
                    'close' => Branch::clock($row['close']),
                ]
                : null;
        }

        $data['weekly_hours'] = $hours;

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->description(fn (Branch $record) => $record->city),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'open' => 'Open',
                        'coming_soon' => 'Coming soon',
                        default => 'Draft',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'open' => 'success',
                        'coming_soon' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('online')
                    ->label('Online booking')
                    ->state(fn (Branch $record) => $record->isOnsiteBookable() ? 'Taking bookings' : (count($record->missing()) === 0 ? 'Not open' : count($record->missing()).' to fill in'))
                    ->badge()
                    ->color(fn (Branch $record) => $record->isOnsiteBookable() ? 'success' : 'warning'),
                TextColumn::make('daily_cap')->label('Daily cap')->alignCenter(),
                TextColumn::make('staff_count')
                    ->label('Staff')
                    ->counts('staff')
                    ->alignCenter(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }
}
