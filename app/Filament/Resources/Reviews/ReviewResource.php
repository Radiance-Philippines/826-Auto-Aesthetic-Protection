<?php

namespace App\Filament\Resources\Reviews;

use App\Filament\Concerns\ScopedToStaffBranches;
use App\Filament\Resources\Reviews\Pages\EditReview;
use App\Filament\Resources\Reviews\Pages\ListReviews;
use App\Filament\Resources\Reviews\Pages\ViewReview;
use App\Models\Review;
use App\Support\ImagePipeline;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * What customers wrote about a finished booking. Each one waits here until
 * the team has read it: only approved reviews reach the site. Staff see
 * only their branches' reviews.
 */
class ReviewResource extends Resource
{
    use ScopedToStaffBranches;

    protected static ?string $model = Review::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Reviews';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->pending()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Reviews waiting for approval';
    }

    public static function stars(int $rating): string
    {
        return str_repeat('★', $rating).str_repeat('☆', 5 - $rating);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'rejected' => 'danger',
            default => 'warning',
        };
    }

    public static function approve(): Action
    {
        return Action::make('approve')
            ->authorize('update')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Review $record) => $record->status !== 'approved')
            ->requiresConfirmation()
            ->modalHeading('Show this review on the site?')
            ->modalDescription('It appears on the Home and Our work pages right away.')
            ->action(fn (Review $record) => $record->update(['status' => 'approved', 'admin_note' => null]));
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->authorize('update')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Review $record) => $record->status !== 'rejected')
            ->modalHeading('Don’t show this review?')
            ->modalDescription('The customer still sees it on their booking, marked as not shown.')
            ->schema([
                Textarea::make('admin_note')
                    ->label('Reason')
                    ->helperText('For the team only. The customer doesn’t see it.')
                    ->maxLength(250),
            ])
            ->modalSubmitActionLabel('Reject')
            ->action(fn (Review $record, array $data) => $record->update(['status' => 'rejected', 'admin_note' => $data['admin_note'] ?? null]));
    }

    public static function feature(): Action
    {
        return Action::make('feature')
            ->authorize('update')
            ->label(fn (Review $record) => $record->is_featured ? 'Unfeature' : 'Feature')
            ->icon(fn (Review $record) => $record->is_featured ? Heroicon::Star : Heroicon::OutlinedStar)
            ->color('gray')
            ->visible(fn (Review $record) => $record->status === 'approved')
            ->action(fn (Review $record) => $record->update(['is_featured' => ! $record->is_featured]));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Review')
                    ->columnSpan(2)
                    ->components([
                        Textarea::make('body')
                            ->label('What they wrote')
                            ->helperText('Fix typos only: this is shown as the customer’s own words.')
                            ->rows(6)
                            ->required()
                            ->maxLength(1000),
                    ]),
                Section::make('Shown as')
                    ->columnSpan(1)
                    ->components([
                        TextInput::make('display_name')
                            ->label('Name')
                            ->required()
                            ->maxLength(80),
                        Select::make('status')
                            ->options(Review::STATUSES)
                            ->required()
                            ->native(false),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Review')
                    ->columnSpan(2)
                    ->components([
                        TextEntry::make('rating')->formatStateUsing(fn (int $state) => static::stars($state)." {$state} out of 5"),
                        TextEntry::make('body')->label('What they wrote')->prose(),
                        ImageEntry::make('photo')
                            ->state(fn (Review $record) => $record->photo ? ImagePipeline::thumbPathFor($record->photo) : null)
                            ->disk('public')
                            ->imageHeight(160)
                            ->placeholder('No photo'),
                    ]),
                Section::make('Details')
                    ->columnSpan(1)
                    ->components([
                        TextEntry::make('display_name')->label('Shown as'),
                        TextEntry::make('booking.name')->label('Customer'),
                        TextEntry::make('booking.reference')->label('Booking'),
                        TextEntry::make('branch.name')->label('Branch')->placeholder('—'),
                        TextEntry::make('status')->badge()
                            ->formatStateUsing(fn (string $state) => Review::STATUSES[$state] ?? $state)
                            ->color(fn (string $state) => static::statusColor($state)),
                        TextEntry::make('admin_note')->label('Reason (team only)')->placeholder('—'),
                        TextEntry::make('created_at')->label('Sent')->dateTime('M j, Y g:i A'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['booking.items', 'branch']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('photo')
                    ->label('')
                    ->state(fn (Review $record) => $record->photo ? ImagePipeline::thumbPathFor($record->photo) : null)
                    ->disk('public')
                    ->imageHeight(48),
                TextColumn::make('display_name')
                    ->label('Customer')
                    ->description(fn (Review $record) => $record->booking?->reference)
                    ->searchable(),
                TextColumn::make('branch.name')->label('Branch')->placeholder('—'),
                TextColumn::make('service')
                    ->state(fn (Review $record) => $record->booking?->items->first()?->service_name)
                    ->placeholder('—'),
                TextColumn::make('rating')
                    ->formatStateUsing(fn (int $state) => static::stars($state))
                    ->color('warning')
                    ->sortable(),
                TextColumn::make('body')->label('Review')->limit(90)->wrap()->extraHeaderAttributes(['style' => 'min-width: 18rem']),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Review::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => static::statusColor($state)),
                TextColumn::make('featured')
                    ->state(fn (Review $record) => $record->is_featured ? 'Featured' : null)
                    ->badge()
                    ->color('info'),
            ])
            ->recordActions([
                static::approve(),
                static::reject(),
                ActionGroup::make([
                    static::feature(),
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviews::route('/'),
            'view' => ViewReview::route('/{record}'),
            'edit' => EditReview::route('/{record}/edit'),
        ];
    }
}
