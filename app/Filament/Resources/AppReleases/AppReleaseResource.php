<?php

namespace App\Filament\Resources\AppReleases;

use App\Filament\Concerns\ShownInItsPanels;
use App\Filament\Resources\AppReleases\Pages\CreateAppRelease;
use App\Filament\Resources\AppReleases\Pages\EditAppRelease;
use App\Filament\Resources\AppReleases\Pages\ListAppReleases;
use App\Models\AppRelease;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * The Android app's APKs (super admin only). The newest published one is
 * what /download and the app's update check offer. Size and sha256 come from
 * the stored file.
 */
class AppReleaseResource extends Resource
{
    use ShownInItsPanels;

    protected static ?string $model = AppRelease::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'App releases';

    protected static ?string $modelLabel = 'app release';

    protected static ?string $recordTitleAttribute = 'version';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->components([
                Select::make('app')
                    ->label('App')
                    ->options(AppRelease::apps())
                    ->default(AppRelease::CUSTOMER)
                    ->required()
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->columnSpanFull(),
                TextInput::make('version')
                    ->required()
                    ->maxLength(20)
                    ->regex(AppRelease::VERSION_PATTERN)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('app', $get('app') ?? AppRelease::CUSTOMER))
                    ->helperText('Like 0.1.0: the same as the version in the app.'),
                TextInput::make('version_code')
                    ->label('Version code')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('app', $get('app') ?? AppRelease::CUSTOMER))
                    ->helperText('A whole number that goes up with every release.'),
                FileUpload::make('apk_path')
                    ->label('APK file')
                    ->required()
                    ->columnSpanFull()
                    ->disk(AppRelease::DISK)
                    ->directory('apks')
                    ->visibility('private')
                    ->maxSize(AppRelease::APK_MAX_KB)
                    ->acceptedFileTypes(AppRelease::APK_TYPES)
                    ->helperText('The signed release APK. Kept private; people download it from the Download page.'),
                Textarea::make('notes')
                    ->rows(4)
                    ->maxLength(2000)
                    ->columnSpanFull()
                    ->helperText('What changed, shown on the Download page and in the app.'),
                Toggle::make('mandatory')
                    ->label('Everyone must update')
                    ->helperText('The app asks people to install this version before they continue.'),
                Toggle::make('is_published')
                    ->label('Published')
                    ->helperText('Off keeps it hidden: nobody can download a draft.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('version_code', 'desc')
            ->columns([
                TextColumn::make('app')->label('App')->badge()->formatStateUsing(fn (string $state) => AppRelease::apps()[$state] ?? $state),
                TextColumn::make('version')->searchable()->description(fn (AppRelease $record) => 'Code '.$record->version_code),
                TextColumn::make('size')->formatStateUsing(fn ($state) => number_format($state / 1048576, 1).' MB'),
                IconColumn::make('is_published')->label('Published')->boolean(),
                IconColumn::make('mandatory')->label('Must update')->boolean(),
                TextColumn::make('created_at')->label('Added')->dateTime('M j, Y'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppReleases::route('/'),
            'create' => CreateAppRelease::route('/create'),
            'edit' => EditAppRelease::route('/{record}/edit'),
        ];
    }
}
