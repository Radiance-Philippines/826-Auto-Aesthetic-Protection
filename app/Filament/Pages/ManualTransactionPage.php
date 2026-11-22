<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Service;
use App\Models\VehicleSize;
use App\Support\ManualTransaction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * For jobs done outside the site (walk-in or phone), even ones already
 * finished: the team enters the customer, unit, services with their
 * prices, the date, where it stands and what was paid. It's saved as a
 * booking marked "Manual entry"; the customer gets no email and no cap
 * or lead-time check applies.
 */
class ManualTransactionPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Manual transaction';

    protected static ?string $title = 'Manual transaction';

    protected static ?string $slug = 'manual-transaction';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->canAccessArea('bookings.sales') && ($user->isOwner() || $user->branchIds() !== []);
    }

    public function mount(): void
    {
        $branches = BookingResource::branchOptions();

        $this->form->fill([
            'branch_id' => count($branches) === 1 ? array_key_first($branches) : null,
            'service_date' => today()->toDateString(),
            'status' => 'exited',
            'services' => [],
            'prices' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                Section::make('Customer')
                    ->columnSpan(2)
                    ->columns(2)
                    ->components([
                        Select::make('branch_id')
                            ->label('Branch')
                            ->options(fn () => BookingResource::branchOptions())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('services', []);
                                $set('prices', []);
                            }),
                        Toggle::make('new_customer')
                            ->label('New customer')
                            ->live()
                            ->inline(false),
                        Select::make('guest_id')
                            ->label('Customer')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Guest::query()
                                ->visibleTo(auth()->user())
                                ->where(fn (Builder $match) => $match->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                                ->orderBy('name')
                                ->limit(20)
                                ->get()
                                ->mapWithKeys(fn (Guest $guest) => [$guest->id => $guest->name.($guest->phone ? " · {$guest->phone}" : '')])
                                ->all())
                            ->getOptionLabelUsing(fn ($value) => Guest::query()->visibleTo(auth()->user())->find($value)?->name)
                            ->required(fn (Get $get) => ! $get('new_customer'))
                            ->visible(fn (Get $get) => ! $get('new_customer'))
                            ->columnSpanFull(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(120)
                            ->visible(fn (Get $get) => (bool) $get('new_customer')),
                        TextInput::make('phone')
                            ->label('Mobile')
                            ->tel()
                            ->required()
                            ->maxLength(40)
                            ->helperText('The same number is the same customer: an existing one is reused.')
                            ->visible(fn (Get $get) => (bool) $get('new_customer')),
                    ]),
                Section::make('Date and status')
                    ->columnSpan(1)
                    ->components([
                        DatePicker::make('service_date')
                            ->label('Date of service')
                            ->displayFormat('D, M j, Y')
                            ->required(),
                        Select::make('status')
                            ->options(collect(ManualTransaction::STATUSES)->mapWithKeys(fn (string $status) => [$status => $status === 'confirmed' ? 'Booked' : Booking::STATUSES[$status]])->all())
                            ->required(),
                    ]),
                Section::make('Unit')
                    ->columnSpan(2)
                    ->columns(2)
                    ->components([
                        TextInput::make('vehicle_year')->label('Year')->integer()->minValue(1950)->maxValue(now()->year + 1)->required(),
                        TextInput::make('vehicle_model')->label('Make and model')->placeholder('Toyota Vios')->required()->maxLength(80),
                        Select::make('vehicle_type')->label('Type')->options(Booking::VEHICLE_TYPES)->required(),
                        TextInput::make('plate_number')->label('Plate number')->required()->maxLength(15),
                        Select::make('vehicle_size_id')
                            ->label('Size class')
                            ->options(fn () => VehicleSize::query()->active()->ordered()->pluck('name', 'id')->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->fillRates($get, $set)),
                    ]),
                Section::make('Services')
                    ->columnSpanFull()
                    ->components([
                        CheckboxList::make('services')
                            ->options(fn (Get $get) => BookingResource::serviceOptions($get('branch_id')))
                            ->helperText(fn (Get $get) => blank($get('branch_id')) ? 'Pick the branch first.' : 'The services this branch offers.')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->fillRates($get, $set))
                            ->columns(['md' => 2]),
                    ]),
                Section::make('Prices')
                    ->description('Start from the rates for the unit’s size class. Type over any price.')
                    ->columnSpanFull()
                    ->columns(['md' => 2, 'xl' => 4])
                    ->visible(fn (Get $get) => filled($get('services')))
                    ->components(fn (Get $get) => Service::query()
                        ->whereKey(array_map('intval', $get('services') ?? []))
                        ->orderBy('sort_order')
                        ->get()
                        ->map(fn (Service $service) => TextInput::make("prices.{$service->id}")
                            ->label($service->name)
                            ->prefix('₱')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10_000_000)
                            ->required())
                        ->values()
                        ->all()),
                Section::make('Payment')
                    ->columnSpan(2)
                    ->columns(2)
                    ->components([
                        TextInput::make('discount')
                            ->label('Discount (optional)')
                            ->prefix('₱')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10_000_000),
                        TextInput::make('amount_paid')
                            ->label('Amount paid')
                            ->prefix('₱')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10_000_000)
                            ->live(onBlur: true),
                        Select::make('payment_method')
                            ->options(ManualTransaction::METHODS)
                            ->required(fn (Get $get) => (int) $get('amount_paid') > 0),
                        TextInput::make('payment_reference')->label('Reference note')->placeholder('GCash ref no., receipt number…')->maxLength(255),
                    ]),
                Section::make('Notes')
                    ->columnSpan(1)
                    ->components([
                        Textarea::make('admin_note')->label('Private note')->rows(5)->maxLength(2000),
                    ]),
            ]);
    }

    /**
     * Starts each picked service's price from its rate for the unit's size class.
     */
    private function fillRates(Get $get, Set $set): void
    {
        $ids = array_map('intval', $get('services') ?? []);
        $sizeId = (int) $get('vehicle_size_id');
        $rates = $sizeId > 0 ? ManualTransaction::ratesFor($sizeId, $ids) : [];

        foreach ($ids as $id) {
            $set("prices.{$id}", $rates[$id] ?? null);
        }
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
                        ->label('Save transaction')
                        ->icon(Heroicon::OutlinedCheck)
                        ->submit('save')
                        ->keyBindings(['mod+s']),
                ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        try {
            $booking = ManualTransaction::record(auth()->user(), $data);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Not saved')->body(collect($e->errors())->flatten()->first())->persistent()->send();

            return;
        }

        Notification::make()->success()->title('Saved')->body("{$booking->reference} · ₱".number_format((int) $booking->total_price))->send();

        $this->redirect(BookingResource::getUrl('view', ['record' => $booking]));
    }
}
