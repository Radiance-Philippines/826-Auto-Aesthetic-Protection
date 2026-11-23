<?php

namespace App\Filament\Resources\Videos;

use App\Filament\Concerns\ShownInItsPanels;
use App\Filament\Resources\Videos\Pages\ManageVideos;
use App\Models\Video;
use App\Support\YouTube;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Videos on the Our work page (owners only). Paste a YouTube link: nothing
 * is uploaded, so nothing heavy sits in storage. Drag to reorder.
 */
class VideoResource extends Resource
{
    use ShownInItsPanels;

    protected static ?string $model = Video::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('youtube_url')
                ->label('YouTube link')
                ->placeholder('https://www.youtube.com/watch?v=…')
                ->required()
                ->maxLength(255)
                ->rule(fn () => fn (string $attribute, mixed $value, \Closure $fail) => YouTube::videoId($value) ?: $fail('Paste a YouTube link (youtube.com or youtu.be, starting with https://).'))
                ->helperText('Videos are not uploaded here. Upload to YouTube first, then paste its link.'),
            TextInput::make('title')->required()->maxLength(120),
            TextInput::make('caption')->maxLength(200)->helperText('Optional, shown under the video.'),
            Toggle::make('is_published')->label('Shown on the site')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('title')->searchable()->description(fn (Video $record) => YouTube::watchUrl($record->youtube_id)),
                ToggleColumn::make('is_published')->label('Shown'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVideos::route('/')];
    }
}
