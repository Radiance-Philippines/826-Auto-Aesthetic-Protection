<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Pages\Warranty;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\BookingsRelationManager;
use App\Filament\Resources\Customers\RelationManagers\ReferralRelationManager;
use App\Filament\Resources\Customers\RelationManagers\UnitsRelationManager;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Support\Warranty as WarrantyCoverage;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Customers: everyone who has booked (signed in with Google, or added by
 * the team for a walk-in or phone job), with their units, bookings,
 * referral code and warranty. Staff see only customers of their branches.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Guest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    protected static ?string $navigationLabel = 'Customers';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'customer';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone', 'email'];
    }

    /**
     * @return array<int, string>
     */
    public static function salesRepOptions(): array
    {
        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Details')
                ->columnSpan(2)
                ->columns(2)
                ->components([
                    TextInput::make('name')->required()->maxLength(120),
                    TextInput::make('phone')->label('Mobile')->tel()->maxLength(40),
                    TextInput::make('email')->email()->maxLength(190),
                    Select::make('branch_id')
                        ->label('Home branch')
                        ->options(fn () => BookingResource::branchOptions())
                        ->default(fn () => count(BookingResource::branchOptions()) === 1 ? array_key_first(BookingResource::branchOptions()) : null),
                    Select::make('sales_rep_id')
                        ->label('Sales rep')
                        ->options(fn () => self::salesRepOptions())
                        ->searchable(),
                ]),
            Section::make('Notes')
                ->columnSpan(1)
                ->components([
                    Textarea::make('notes')->label('Private note')->rows(6)->maxLength(3000),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Details')
                ->columnSpan(2)
                ->columns(2)
                ->components([
                    TextEntry::make('name')->weight('bold'),
                    TextEntry::make('phone')->label('Mobile')->copyable()->placeholder('—')
                        ->url(fn (Guest $record) => BookingResource::phoneUrl($record->phone)),
                    TextEntry::make('email')->copyable()->placeholder('—'),
                    TextEntry::make('branch.name')->label('Home branch')->placeholder('—'),
                    TextEntry::make('salesRep.name')->label('Sales rep')->placeholder('—'),
                    TextEntry::make('source')
                        ->label('Came in by')
                        ->formatStateUsing(fn (string $state) => Guest::SOURCES[$state] ?? $state),
                    TextEntry::make('notes')->label('Private note')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('Referral')
                ->columnSpan(1)
                ->components([
                    TextEntry::make('referral_code')->label('Code')->copyable()->placeholder('Not made yet'),
                    TextEntry::make('times_used')
                        ->label('Times used')
                        ->state(fn (Guest $record) => $record->referralRedemptions()->whereNull('voided_at')->count()),
                    TextEntry::make('points')
                        ->label('Points earned')
                        ->state(fn (Guest $record) => (int) $record->referralRedemptions()->sum('points_awarded'))
                        ->helperText(fn () => ReferralSetting::current()->owner_points === null ? 'No points set in Settings → Referral.' : null),
                ]),
            Section::make('Warranty')
                ->columnSpanFull()
                ->components([
                    TextEntry::make('warranty')
                        ->label('Services under warranty')
                        ->state(function (Guest $record) {
                            $active = WarrantyCoverage::search(auth()->user(), '', $record->id)
                                ->sum(fn ($booking) => $booking->items->filter(fn ($item) => WarrantyCoverage::status($booking, $item) === 'active')->count());

                            return $active === 1 ? '1 service is covered now' : "{$active} services are covered now";
                        })
                        ->helperText('Open the warranty checker for the dates and to log a backjob.')
                        ->url(fn (Guest $record) => Warranty::getUrl(['customer' => $record->id])),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['branch', 'salesRep'])
                ->withCount(['vehicles', 'bookings'])
                ->withMax(['bookings as last_visit_on' => fn (Builder $bookings) => $bookings->whereIn('status', ['arrived', 'in_progress', 'quality_check', 'ready', 'exited'])], 'drop_off_on'))
            ->columns([
                TextColumn::make('name')
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $match) => $match
                        ->where('guests.name', 'like', "%{$search}%")
                        ->orWhere('guests.phone', 'like', "%{$search}%")
                        ->orWhere('guests.email', 'like', "%{$search}%")
                        ->orWhereHas('vehicles', fn (Builder $vehicles) => $vehicles->where('plate_number', 'like', '%'.Booking::normalizePlate($search).'%'))))
                    ->sortable()
                    ->description(fn (Guest $record) => collect([$record->phone, $record->email])->filter()->implode(' · ')),
                TextColumn::make('branch.name')->label('Branch')->placeholder('—')->toggleable(),
                TextColumn::make('salesRep.name')->label('Sales rep')->placeholder('—')->toggleable(),
                TextColumn::make('vehicles_count')->label('Units')->alignCenter(),
                TextColumn::make('bookings_count')->label('Bookings')->alignCenter(),
                TextColumn::make('last_visit_on')->label('Last visit')->date('M j, Y')->placeholder('—'),
                TextColumn::make('referral_code')->label('Referral code')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(fn () => BookingResource::branchOptions())
                    ->visible(fn () => count(BookingResource::branchOptions()) > 1),
                SelectFilter::make('sales_rep_id')
                    ->label('Sales rep')
                    ->options(fn () => self::salesRepOptions()),
                SelectFilter::make('source')
                    ->options(Guest::SOURCES),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            UnitsRelationManager::class,
            BookingsRelationManager::class,
            ReferralRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
