<?php

namespace App\Filament\Resources\Services;

use App\Filament\Concerns\ShownInItsPanels;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Support\ImageUpload;
use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\VehicleSize;
use App\Support\ServiceForm;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * What customers can book (owners only): one estimate in days, and a price
 * for each size class. Customers never see the prices: they are used when
 * the team assigns a unit its size class.
 */
class ServiceResource extends Resource
{
    use ShownInItsPanels;

    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Shop';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * The longest job, in open days.
     */
    public const MAX_DAYS = ServiceForm::MAX_DAYS;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Service')
                ->columnSpan(2)
                ->columns(2)
                ->components([
                    TextInput::make('name')
                        ->placeholder('Graphene coating')
                        ->required()
                        ->maxLength(120)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, Set $set, ?Service $record) {
                            if (! $record) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    Select::make('service_category_id')
                        ->label('Category')
                        ->options(fn () => ServiceCategory::query()->ordered()->pluck('name', 'id')->all())
                        ->required(),
                    TextInput::make('slug')
                        ->label('Web address')
                        ->prefix('/services/')
                        ->required()
                        ->maxLength(80)
                        ->alphaDash()
                        ->unique(ignoreRecord: true),
                    TextInput::make('summary')
                        ->label('One-line summary')
                        ->maxLength(160),
                    TextInput::make('estimate_days')
                        ->label('Takes (days)')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(self::MAX_DAYS)
                        ->suffix('days')
                        ->helperText('Open days, counting the drop-off day. Leave blank until you know it: a service without it can’t be booked.'),
                    TextInput::make('warranty_months')
                        ->label('Warranty (months)')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(240)
                        ->suffix('months')
                        ->helperText('Counted from the day the unit exits. Leave blank if the service has no warranty. Bookings already made keep what they were booked with.'),
                    Select::make('backjobTypes')
                        ->label('Covers backjobs for')
                        ->multiple()
                        ->relationship('backjobTypes', 'name', fn ($query) => $query->ordered())
                        ->preload()
                        ->helperText('Define the kinds of backjob in Shop → Backjob types.'),
                    Textarea::make('description')
                        ->rows(4)
                        ->maxLength(3000)
                        ->columnSpanFull(),
                    Repeater::make('inclusions')
                        ->label('What’s included')
                        ->simple(TextInput::make('item')->maxLength(160)->required())
                        ->addActionLabel('Add a line')
                        ->reorderable()
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ]),
            Section::make('On the site')
                ->columnSpan(1)
                ->components([
                    Toggle::make('is_active')->label('Bookable')->default(true)->helperText('Off hides it from the site and the booking form.'),
                    Toggle::make('home_service_ok')->label('Can be done at home')->helperText('Offered in home-service requests.'),
                    ImageUpload::make('image', 'services')->label('Picture'),
                    TextInput::make('sort_order')->label('Order')->integer()->default(0),
                ]),
            Section::make('Prices by size class')
                ->description('One price per size class, never shown to customers: it prices a booking once the team has seen the unit and picked its class. Leave a class blank if the service isn’t offered for it.')
                ->columnSpanFull()
                ->columns(['md' => 2, 'xl' => 4])
                ->components(fn () => VehicleSize::query()->ordered()->get()->map(fn (VehicleSize $size) => TextInput::make("rates.{$size->id}.price")
                    ->label($size->name.($size->is_active ? '' : ' (hidden)'))
                    ->prefix('₱')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(10_000_000))->values()->all()),
            Section::make('Branches')
                ->columnSpanFull()
                ->components([
                    CheckboxList::make('branches')
                        ->label('Offered at')
                        ->relationship('branches', 'name', fn ($query) => $query->orderBy('sort_order')->orderBy('name'))
                        ->getOptionLabelFromRecordUsing(fn (Branch $record) => $record->name.($record->status === 'draft' ? ' (draft)' : ''))
                        ->bulkToggleable()
                        ->columns(['md' => 3]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'rates.vehicleSize'])->withCount('branches'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->description(fn (Service $record) => $record->category?->name),
                TextColumn::make('estimate_days')
                    ->label('Takes')
                    ->formatStateUsing(fn (?int $state) => ServiceForm::takesLabel($state))
                    ->color(fn (Service $record) => $record->estimate_days > 0 ? null : 'warning'),
                TextColumn::make('prices')
                    ->label('Prices')
                    ->state(fn (Service $record) => ServiceForm::priceLabel($record))
                    ->description(fn (Service $record) => $record->rates->filter->hasPrice()->count().' of '.VehicleSize::query()->active()->count().' size classes')
                    ->color(fn (Service $record) => $record->rates->filter->hasPrice()->isEmpty() ? 'warning' : null),
                TextColumn::make('branches_count')
                    ->label('Branches')
                    ->alignCenter(),
                IconColumn::make('home_service_ok')->label('At home')->boolean(),
                IconColumn::make('is_active')->label('Bookable')->boolean(),
            ])
            ->filters([
                SelectFilter::make('service_category_id')
                    ->label('Category')
                    ->options(fn () => ServiceCategory::query()->ordered()->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
