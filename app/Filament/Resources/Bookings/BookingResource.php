<?php

namespace App\Filament\Resources\Bookings;

use App\Filament\Concerns\ScopedToStaffBranches;
use App\Filament\Concerns\ShownInItsPanels;
use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\Branch;
use App\Models\PaymentSetting;
use App\Models\Service;
use App\Models\VehicleSize;
use App\Support\ManualTransaction;
use App\Support\Schedule\Estimate;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Reservations from the site, home-service requests, and bookings the team
 * takes at the counter or over the phone; units wait here for their size review. Staff see only their branches.
 */
class BookingResource extends Resource
{
    use ScopedToStaffBranches;
    use ShownInItsPanels;

    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    protected static ?string $navigationLabel = 'Bookings';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->needsAction()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Screenshots to check, home-service requests to schedule and units to size';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'name', 'phone', 'plate_number'];
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'requested', 'payment_review' => 'warning',
            'awaiting_payment' => 'info',
            'confirmed' => 'primary',
            'arrived', 'in_progress' => 'info',
            'quality_check' => 'warning',
            'ready', 'exited' => 'success',
            'no_show' => 'danger',
            default => 'gray',
        };
    }

    public static function statusLabel(Booking $record): string
    {
        return $record->stage() === 'expired' ? Booking::STATUSES['expired'] : (Booking::STATUSES[$record->status] ?? $record->status);
    }

    /**
     * Branches the signed-in team member may book for.
     *
     * @return array<int, string>
     */
    public static function branchOptions(): array
    {
        return Branch::query()->visibleTo(auth()->user())->ordered()->pluck('name', 'id')->all();
    }

    /**
     * The services a branch offers that can be booked, as
     * "Graphene coating · 3 days".
     *
     * @return array<int, string>
     */
    public static function serviceOptions(mixed $branchId, bool $home = false): array
    {
        if (blank($branchId)) {
            return [];
        }

        return Service::query()
            ->bookable()
            ->whereHas('branches', fn (Builder $branches) => $branches->whereKey($branchId))
            ->when($home, fn (Builder $services) => $services->where('home_service_ok', true))
            ->ordered()
            ->get()
            ->mapWithKeys(fn (Service $service) => [$service->id => "{$service->name} · ".(new Estimate((int) $service->estimate_days))->label()])
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make([
                    Section::make('Booking')
                        ->columns(2)
                        ->visibleOn('create')
                        ->components([
                            ToggleButtons::make('kind')
                                ->options(Booking::KINDS)
                                ->icons(['onsite' => Heroicon::OutlinedBuildingStorefront, 'home' => Heroicon::OutlinedHome])
                                ->default('onsite')
                                ->inline()
                                ->required()
                                ->live(),
                            Select::make('source')
                                ->label('Came in by')
                                ->options(['walk_in' => 'Walk-in', 'phone' => 'Call or message', 'admin' => 'Other'])
                                ->default('phone')
                                ->required(),
                            Select::make('branch_id')
                                ->label('Branch')
                                ->options(fn () => self::branchOptions())
                                ->default(fn () => count(self::branchOptions()) === 1 ? array_key_first(self::branchOptions()) : null)
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn (Set $set) => $set('services', [])),
                            CheckboxList::make('services')
                                ->options(fn (Get $get) => self::serviceOptions($get('branch_id'), $get('kind') === 'home'))
                                ->helperText(fn (Get $get) => blank($get('branch_id'))
                                    ? 'Pick the branch first.'
                                    : 'Only services this branch offers that have a day estimate.')
                                ->required()
                                ->columns(1)
                                ->columnSpanFull(),
                            DatePicker::make('drop_off_on')
                                ->label(fn (Get $get) => $get('kind') === 'home' ? 'Team comes on (optional)' : 'Drop-off date')
                                ->displayFormat('D, M j, Y')
                                ->required(fn (Get $get) => $get('kind') !== 'home')
                                ->helperText(fn (Get $get) => $get('kind') === 'home'
                                    ? 'Leave blank to schedule it later.'
                                    : 'The ready date is worked out from the services and the days the branch is open.'),
                            Toggle::make('force')
                                ->label('Book even if it’s full')
                                ->helperText('For when you’ve made room yourself. The queue shows the day as over its cap.')
                                ->visible(fn (Get $get) => $get('kind') !== 'home')
                                ->dehydrated(),
                            DatePicker::make('preferred_date')
                                ->label('Customer’s preferred day')
                                ->displayFormat('D, M j, Y')
                                ->visible(fn (Get $get) => $get('kind') === 'home'),
                            Select::make('preferred_window')
                                ->label('Preferred time')
                                ->options(Booking::WINDOWS)
                                ->default('any')
                                ->visible(fn (Get $get) => $get('kind') === 'home'),
                        ]),
                    Section::make('Vehicle')
                        ->columns(2)
                        ->components([
                            TextInput::make('vehicle_year')->label('Year')->integer()->minValue(1950)->maxValue(now()->year + 1)->required(fn (string $operation) => $operation === 'create'),
                            TextInput::make('vehicle_model')->label('Make and model')->placeholder('Toyota Vios')->required(fn (string $operation) => $operation === 'create')->maxLength(80),
                            Select::make('vehicle_type')->label('Type')->options(Booking::VEHICLE_TYPES)->required(fn (string $operation) => $operation === 'create'),
                            TextInput::make('plate_number')->label('Plate number')->required(fn (string $operation) => $operation === 'create')->maxLength(15),
                            Select::make('vehicle_size_id')
                                ->label('Size class (if you know it)')
                                ->options(fn () => VehicleSize::query()->active()->ordered()->pluck('name', 'id')->all())
                                ->helperText('Leave blank to review the unit later: the size sets the price.')
                                ->visibleOn('create'),
                        ]),
                    Section::make('Home service')
                        ->columns(2)
                        ->disabled(fn () => ! auth()->user()?->canAccessArea('bookings.sales'))
                        ->visible(fn (Get $get, ?Booking $record) => ($record?->kind ?? $get('kind')) === 'home')
                        ->components([
                            Textarea::make('address')->rows(2)->maxLength(500)->required()->columnSpanFull(),
                            TextInput::make('area')->maxLength(120),
                            TextInput::make('landmark')->maxLength(200),
                            Select::make('has_water')->label('Water hose')->options([1 => 'Yes', 0 => 'No']),
                            Select::make('has_power')->label('Power outlet')->options([1 => 'Yes', 0 => 'No']),
                            Select::make('has_shade')->label('Shade or garage')->options([1 => 'Yes', 0 => 'No']),
                        ]),
                ])->columnSpan(['default' => 'full', 'lg' => 2]),
                Group::make([
                    Section::make('Customer')
                        ->disabled(fn () => ! auth()->user()?->canAccessArea('bookings.sales'))
                        ->components([
                            TextInput::make('name')->required()->maxLength(120),
                            TextInput::make('phone')->label('Mobile')->tel()->required()->maxLength(40),
                            TextInput::make('email')->email()->maxLength(190),
                        ]),
                    Section::make('Notes')
                        ->disabled(fn () => ! auth()->user()?->canAccessArea('bookings.sales'))
                        ->components([
                            Textarea::make('message')->label('From the customer')->rows(3)->maxLength(1000),
                            Textarea::make('admin_note')->label('Private note')->rows(3)->maxLength(2000),
                        ]),
                ])->columnSpan(['default' => 'full', 'lg' => 1]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make([
                    Section::make('When and where')
                        ->columns(2)
                        ->components([
                            TextEntry::make('when')
                                ->label(fn (Booking $record) => $record->isHome() ? 'Schedule' : 'Drop-off · ready around')
                                ->state(fn (Booking $record) => $record->whenLabel())
                                ->weight('bold')
                                ->columnSpanFull(),
                            TextEntry::make('branch.name')->label('Branch'),
                            TextEntry::make('kind')
                                ->formatStateUsing(fn (string $state) => Booking::KINDS[$state] ?? $state)
                                ->badge()
                                ->color(fn (string $state) => $state === 'home' ? 'info' : 'gray'),
                            TextEntry::make('services')
                                ->state(fn (Booking $record) => $record->items->map(fn ($item) => $item->service_name.' · '.($item->price !== null ? '₱'.number_format($item->price) : 'price after review'))->all())
                                ->listWithLineBreaks()
                                ->bulleted()
                                ->columnSpanFull(),
                            TextEntry::make('total_price')
                                ->label('Price')
                                ->formatStateUsing(fn (?int $state) => $state === null ? 'Not set: review the unit' : '₱'.number_format($state))
                                ->color(fn (Booking $record) => $record->needsSizeReview() ? 'warning' : null)
                                ->helperText(fn (Booking $record) => $record->discount_amount ? 'After a ₱'.number_format($record->discount_amount).' referral discount' : null),
                            TextEntry::make('length')
                                ->label('Takes')
                                ->state(fn (Booking $record) => $record->estimate()->label() ?: '—'),
                            TextEntry::make('arrived_at')
                                ->label('Arrived')
                                ->dateTime('M j, Y g:i A')
                                ->placeholder('—'),
                            TextEntry::make('exited_at')
                                ->label('Exited')
                                ->dateTime('M j, Y g:i A')
                                ->placeholder('—'),
                            TextEntry::make('message')
                                ->label('From the customer')
                                ->prose()
                                ->formatStateUsing(fn (string $state) => nl2br(e($state)))
                                ->html()
                                ->placeholder('No note')
                                ->columnSpanFull(),
                        ]),
                    Section::make('Reservation fee')
                        ->columns(2)
                        ->visible(fn (Booking $record) => auth()->user()?->canAccessArea('bookings.sales') && $record->source !== 'manual' && ($record->amount_due !== null || filled($record->payment_proof) || $record->paid_at !== null))
                        ->components([
                            TextEntry::make('amount_due')
                                ->label('Fee')
                                ->formatStateUsing(fn (?int $state) => $state === null ? '—' : '₱'.number_format($state))
                                ->helperText(fn (Booking $record) => $record->paid_at ? 'Paid '.$record->paid_at->format('M j, g:i A').($record->payment_note ? " · {$record->payment_note}" : '') : null),
                            TextEntry::make('payment_method')
                                ->label('Paid with')
                                ->formatStateUsing(fn (?string $state) => PaymentSetting::METHODS[$state] ?? $state)
                                ->helperText(fn (Booking $record) => $record->payment_submitted_at ? 'Screenshot sent '.$record->payment_submitted_at->format('M j, g:i A') : null)
                                ->placeholder('Not yet'),
                            TextEntry::make('payment_rejected_reason')
                                ->label('Asked for another screenshot')
                                ->color('danger')
                                ->visible(fn (Booking $record) => filled($record->payment_rejected_reason) && $record->stage() === 'awaiting_payment'),
                            ViewEntry::make('payment_proof')
                                ->label('Screenshot')
                                ->view('filament.entries.payment-proof')
                                ->visible(fn (Booking $record) => filled($record->payment_proof))
                                ->columnSpanFull(),
                        ]),
                    Section::make('Payment')
                        ->columns(2)
                        ->visible(fn (Booking $record) => auth()->user()?->canAccessArea('bookings.sales') && $record->source === 'manual')
                        ->components([
                            TextEntry::make('amount_paid')
                                ->label('Paid')
                                ->formatStateUsing(fn (?int $state) => $state === null ? 'Nothing recorded' : '₱'.number_format($state))
                                ->helperText(fn (Booking $record) => $record->paid_at ? 'On '.$record->paid_at->format('M j, Y') : null),
                            TextEntry::make('payment_method')
                                ->label('Paid with')
                                ->formatStateUsing(fn (?string $state) => ManualTransaction::METHODS[$state] ?? $state)
                                ->helperText(fn (Booking $record) => $record->payment_note)
                                ->placeholder('—'),
                        ]),
                    Section::make('Home service')
                        ->columns(2)
                        ->visible(fn (Booking $record) => $record->isHome())
                        ->components([
                            TextEntry::make('address')->columnSpanFull(),
                            TextEntry::make('area')->placeholder('—'),
                            TextEntry::make('landmark')->placeholder('—'),
                            TextEntry::make('site')
                                ->label('Water / power / shade')
                                ->state(fn (Booking $record) => collect(['has_water', 'has_power', 'has_shade'])
                                    ->map(fn (string $field) => $record->{$field} === null ? 'not said' : ($record->{$field} ? 'yes' : 'no'))
                                    ->implode(' / ')),
                        ]),
                    Section::make('History')
                        ->collapsible()
                        ->components([
                            TextEntry::make('history')
                                ->hiddenLabel()
                                ->state(fn (Booking $record) => $record->events()->with('user')->limit(100)->get()->map(fn (BookingEvent $event) => $event->line())->all())
                                ->listWithLineBreaks()
                                ->bulleted()
                                ->placeholder('Nothing recorded yet. Moves and changes from now on show here.'),
                        ]),
                ])->columnSpan(['default' => 'full', 'lg' => 2]),
                Group::make([
                    Section::make('Customer')
                        ->components([
                            TextEntry::make('status')
                                ->state(fn (Booking $record) => self::statusLabel($record))
                                ->badge()
                                ->color(fn (Booking $record) => $record->stage() === 'expired' ? 'gray' : self::statusColor($record->status))
                                ->helperText(fn (Booking $record) => $record->stage() === 'awaiting_payment' && $record->hold_expires_at
                                    ? 'Held until '.$record->hold_expires_at->format('M j, g:i A')
                                    : null),
                            TextEntry::make('reference')->copyable(),
                            TextEntry::make('name')
                                ->url(fn (Booking $record) => $record->guest_id && CustomerResource::canViewAny() ? CustomerResource::getUrl('view', ['record' => $record->guest_id]) : null)
                                ->helperText(fn (Booking $record) => $record->guest?->email ? "Signed in as {$record->guest->email}" : ($record->guest ? 'Customer record, no Google account' : null)),
                            TextEntry::make('phone')
                                ->label('Mobile')
                                ->copyable()
                                ->url(fn (Booking $record) => self::phoneUrl($record->phone)),
                            TextEntry::make('email')
                                ->copyable()
                                ->url(fn (Booking $record) => $record->email ? 'mailto:'.$record->email : null)
                                ->placeholder('—'),
                            TextEntry::make('vehicle')
                                ->state(fn (Booking $record) => $record->vehicleLabel() ?: null)
                                ->placeholder('—'),
                            TextEntry::make('vehicle_size_name')
                                ->label('Size class')
                                ->state(fn (Booking $record) => $record->size_reviewed_at ? $record->vehicle_size_name : null)
                                ->placeholder('Not reviewed yet')
                                ->badge()
                                ->color(fn (Booking $record) => $record->size_reviewed_at ? 'gray' : 'warning'),
                            TextEntry::make('admin_note')->label('Private note')->placeholder('—'),
                            TextEntry::make('created_at')
                                ->label(fn (Booking $record) => 'Came in by '.strtolower(Booking::SOURCES[$record->source] ?? $record->source))
                                ->dateTime('M j, Y g:i A')
                                ->helperText(fn (Booking $record) => $record->reschedule_count > 0 ? "Moved by the customer {$record->reschedule_count}×" : null),
                        ]),
                ])->columnSpan(['default' => 'full', 'lg' => 1]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('drop_off_on')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branch', 'items']))
            ->recordClasses(fn (Booking $record) => $record->isRead() ? null : 'font-semibold')
            ->columns([
                IconColumn::make('read_at')
                    ->label('')
                    ->state(fn (Booking $record) => ! $record->isRead())
                    ->boolean()
                    ->trueIcon(Heroicon::Envelope)
                    ->falseIcon(Heroicon::OutlinedEnvelopeOpen)
                    ->trueColor('primary')
                    ->falseColor('gray')
                    ->tooltip(fn (Booking $record) => $record->isRead() ? 'Read' : 'Unread'),
                TextColumn::make('drop_off_on')
                    ->label('Drop-off')
                    ->state(fn (Booking $record) => $record->drop_off_on ?? $record->preferred_date)
                    ->formatStateUsing(fn (Booking $record) => $record->drop_off_on ? $record->drop_off_on->format('D, M j') : 'Asked for '.$record->preferred_date?->format('D, M j'))
                    ->description(fn (Booking $record) => $record->ready_on ? 'Ready around '.$record->ready_on->format('D, M j') : Booking::KINDS[$record->kind])
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Customer')
                    ->url(fn (Booking $record) => $record->guest_id && CustomerResource::canViewAny() ? CustomerResource::getUrl('view', ['record' => $record->guest_id]) : null)
                    ->searchable(['name', 'phone', 'reference', 'plate_number'])
                    ->description(fn (Booking $record) => "{$record->reference} · {$record->phone}"),
                TextColumn::make('services')
                    ->state(fn (Booking $record) => $record->servicesLabel())
                    ->description(fn (Booking $record) => $record->vehicleLabel())
                    ->wrap()
                    ->limit(60),
                TextColumn::make('branch.name')
                    ->label('Branch')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (Booking $record) => self::statusLabel($record))
                    ->color(fn (Booking $record) => $record->stage() === 'expired' ? 'gray' : self::statusColor($record->status))
                    ->description(fn (Booking $record) => $record->stage() === 'awaiting_payment' && $record->hold_expires_at ? 'until '.$record->hold_expires_at->format('g:i A') : null),
                TextColumn::make('total_price')
                    ->label('Price')
                    ->formatStateUsing(fn (?int $state) => $state === null ? 'Size needed' : '₱'.number_format($state))
                    ->badge(fn (Booking $record) => $record->needsSizeReview())
                    ->color(fn (Booking $record) => $record->needsSizeReview() ? 'warning' : null)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Made')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(fn () => self::branchOptions())
                    ->visible(fn () => count(self::branchOptions()) > 1),
                SelectFilter::make('kind')
                    ->options(Booking::KINDS),
                SelectFilter::make('status')
                    ->options(Booking::STATUSES)
                    ->multiple(),
            ])
            ->recordActions([
                ViewAction::make(),
                BookingActions::call(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markRead')
                        ->label('Mark as read')
                        ->icon(Heroicon::OutlinedEnvelopeOpen)
                        ->action(fn (Collection $records) => Booking::whereKey($records->modelKeys())->unread()->update(['read_at' => now()]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('markUnread')
                        ->label('Mark as unread')
                        ->icon(Heroicon::OutlinedEnvelope)
                        ->action(fn (Collection $records) => Booking::whereKey($records->modelKeys())->update(['read_at' => null]))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * "0917 758 6820" → "tel:+639177586820"; null when it isn't a number.
     */
    public static function phoneUrl(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (strlen($digits) < 7) {
            return null;
        }

        $number = match (true) {
            str_starts_with($digits, '63') => "+{$digits}",
            str_starts_with($digits, '09') => '+63'.substr($digits, 1),
            default => (str_starts_with(trim((string) $phone), '+') ? '+' : '').$digits,
        };

        return "tel:{$number}";
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookings::route('/'),
            'create' => CreateBooking::route('/create'),
            'view' => ViewBooking::route('/{record}'),
            'edit' => EditBooking::route('/{record}/edit'),
        ];
    }
}
