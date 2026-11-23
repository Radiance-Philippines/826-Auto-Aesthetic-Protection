<?php

namespace App\Filament\Resources\BackjobTypes;

use App\Filament\Concerns\ShownInItsPanels;
use App\Filament\Resources\BackjobTypes\Pages\ManageBackjobTypes;
use App\Models\BackjobType;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The kinds of backjob the warranty covers (owners only). Nothing is set
 * up for you: add your own ("Coating peeling", "Tint bubbling"…), then pick
 * which ones each service covers in Shop → Services.
 */
class BackjobTypeResource extends Resource
{
    use ShownInItsPanels;

    protected static ?string $model = BackjobType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrench;

    protected static string|UnitEnum|null $navigationGroup = 'Shop';

    protected static ?string $navigationLabel = 'Backjob types';

    protected static ?string $modelLabel = 'backjob type';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(120)->columnSpanFull(),
            Toggle::make('is_active')->label('In use')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->withCount('services'))
            ->emptyStateHeading('No backjob types yet')
            ->emptyStateDescription('Add the kinds of backjob your warranty covers.')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('services_count')->label('Services covered')->alignCenter(),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBackjobTypes::route('/'),
        ];
    }
}
