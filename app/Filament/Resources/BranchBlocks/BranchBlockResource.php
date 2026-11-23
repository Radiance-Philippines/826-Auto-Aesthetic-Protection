<?php

namespace App\Filament\Resources\BranchBlocks;

use App\Filament\Concerns\ScopedToStaffBranches;
use App\Filament\Concerns\ShownInItsPanels;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\BranchBlocks\Pages\ManageBranchBlocks;
use App\Models\BranchBlock;
use App\Support\Panels;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Whole days a branch is closed (holidays, brownouts) or fully booked, per
 * branch (or for every branch, by the owner). Customers can't pick those
 * days.
 */
class BranchBlockResource extends Resource
{
    use ScopedToStaffBranches;
    use ShownInItsPanels;

    protected static function includesEveryBranchRecords(): bool
    {
        return true;
    }

    protected static ?string $model = BranchBlock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    /**
     * With the setup in /owner, in the daily work's group elsewhere.
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return Filament::getCurrentOrDefaultPanel()->getId() === Panels::OWNER ? 'Shop' : 'Bookings';
    }

    protected static ?string $navigationLabel = 'Closures';

    protected static ?string $modelLabel = 'closure';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('branch_id')
                ->label('Branch')
                ->options(fn () => BookingResource::branchOptions())
                ->placeholder(fn () => auth()->user()?->canAccessArea('shop') ? 'Every branch' : 'Pick a branch')
                ->required(fn () => ! auth()->user()?->canAccessArea('shop'))
                ->helperText(fn () => auth()->user()?->canAccessArea('shop') ? 'Leave blank to close every branch (Christmas, Holy Week).' : null),
            ToggleButtons::make('kind')
                ->label('What happens')
                ->options(BranchBlock::KINDS)
                ->default('closed')
                ->required(),
            TextInput::make('title')
                ->label('Why')
                ->placeholder('Bonifacio Day, brownout, big booking that week…')
                ->required()
                ->maxLength(120),
            DatePicker::make('first_day')
                ->label('From')
                ->displayFormat('D, M j, Y')
                ->required()
                ->dehydrated(false),
            DatePicker::make('last_day')
                ->label('To (the last day)')
                ->displayFormat('D, M j, Y')
                ->afterOrEqual('first_day')
                ->required()
                ->dehydrated(false),
            Textarea::make('note')
                ->label('Private note')
                ->rows(2)
                ->maxLength(1000)
                ->columnSpanFull(),
        ]);
    }

    /**
     * Whole days become midnight to midnight.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function prepare(array $data, array $raw): array
    {
        $data['starts_at'] = CarbonImmutable::parse($raw['first_day'])->startOfDay();
        $data['ends_at'] = CarbonImmutable::parse($raw['last_day'] ?? $raw['first_day'])->addDay()->startOfDay();

        if (! auth()->user()?->canAccessArea('shop') && blank($data['branch_id'] ?? null)) {
            abort(403);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fill(BranchBlock $record, array $data): array
    {
        return [
            ...$data,
            'first_day' => $record->firstDay()->toDateString(),
            'last_day' => $record->lastDay()->toDateString(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('starts_at')
                    ->label('When')
                    ->formatStateUsing(fn (BranchBlock $record) => $record->dateLabel())
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Why')
                    ->description(fn (BranchBlock $record) => $record->kind === 'full' ? 'Fully booked' : 'Closed'),
                TextColumn::make('branch.name')
                    ->label('Branch')
                    ->placeholder('Every branch'),
            ])
            ->filters([
                TernaryFilter::make('upcoming')
                    ->label('When')
                    ->trueLabel('Upcoming')
                    ->falseLabel('Past')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->where('ends_at', '>=', now()),
                        false: fn (Builder $query) => $query->where('ends_at', '<', now()),
                    ),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (BranchBlock $record, array $data) => self::fill($record, $data))
                    ->mutateDataUsing(fn (array $data, EditAction $action) => self::prepare($data, $action->getRawData())),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBranchBlocks::route('/'),
        ];
    }
}
