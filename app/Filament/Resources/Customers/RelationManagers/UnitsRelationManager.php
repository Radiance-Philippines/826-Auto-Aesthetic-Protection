<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\Vehicle;
use App\Models\VehicleSize;
use App\Support\Notifier;
use App\Support\Reservations;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The customer's units. "Set size" gives a unit its size class and prices
 * its open bookings that were waiting for it. "History" lists the unit's
 * bookings at the branches the team member works at.
 */
class UnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'vehicles';

    protected static ?string $title = 'Units';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('plate_number')
            ->modifyQueryUsing(fn ($query) => $query
                ->with('vehicleSize')
                ->withCount(['bookings as jobs_count' => fn ($bookings) => $bookings->visibleTo(auth()->user())])
                ->withMax(['bookings as last_service_at' => fn ($bookings) => $bookings->visibleTo(auth()->user())->where('status', 'exited')], 'exited_at'))
            ->columns([
                TextColumn::make('year'),
                TextColumn::make('model')->label('Make and model'),
                TextColumn::make('type')->formatStateUsing(fn (string $state) => Booking::VEHICLE_TYPES[$state] ?? $state),
                TextColumn::make('plate_number')->label('Plate'),
                TextColumn::make('vehicleSize.name')
                    ->label('Size class')
                    ->badge()
                    ->placeholder('Not reviewed')
                    ->color(fn (Vehicle $record) => $record->isReviewed() ? 'gray' : 'warning'),
                TextColumn::make('jobs_count')
                    ->label('Jobs')
                    ->numeric()
                    ->tooltip('Bookings for this unit at your branches, whatever their status.')
                    ->sortable(),
                TextColumn::make('last_service_at')
                    ->label('Last service')
                    ->date('M j, Y')
                    ->placeholder('Not yet')
                    ->tooltip('The day its latest finished job exited.')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('history')
                    ->label('History')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->modalHeading(fn (Vehicle $record) => "Service history: {$record->plate_number}")
                    ->modalDescription(fn (Vehicle $record) => trim("{$record->year} {$record->model}"))
                    ->modalContent(fn (Vehicle $record) => view('filament.customers.unit-history', ['jobs' => $this->jobsFor($record)]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->slideOver()
                    ->modalWidth(Width::FourExtraLarge),
                Action::make('setSize')
                    ->authorize(fn () => (bool) auth()->user()?->canAccessArea('bookings.sales'))
                    ->label('Set size')
                    ->icon(Heroicon::OutlinedScale)
                    ->modalDescription('Sets the unit’s size class. Its open bookings that had no price yet are priced from the services’ rates, and the customer gets an email with it.')
                    ->fillForm(fn (Vehicle $record) => ['vehicle_size_id' => $record->vehicle_size_id])
                    ->schema([
                        Select::make('vehicle_size_id')
                            ->label('Size class')
                            ->options(fn () => VehicleSize::query()->active()->ordered()->pluck('name', 'id')->all())
                            ->required(),
                    ])
                    ->modalSubmitActionLabel('Set size')
                    ->action(function (Vehicle $record, array $data) {
                        $size = VehicleSize::query()->findOrFail($data['vehicle_size_id']);
                        $priced = Reservations::setVehicleSize($record, $size);

                        foreach ($priced as $booking) {
                            Notifier::customer($booking, 'priced');
                        }

                        Notification::make()->success()->title("Size set: {$size->name}")->body(count($priced) > 0 ? count($priced).' booking(s) priced.' : null)->send();
                    }),
            ]);
    }

    /**
     * The unit's bookings, newest first, at the branches this user may see.
     *
     * @return list<array{reference: string, url: string, date: string, branch: string, services: string, status: string, price: string}>
     */
    private function jobsFor(Vehicle $unit): array
    {
        return $unit->bookings()
            ->visibleTo(auth()->user())
            ->with(['branch', 'items'])
            ->orderByRaw('coalesce(exited_at, drop_off_on, preferred_date, created_at) desc')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Booking $booking) => [
                'reference' => $booking->reference,
                'url' => BookingResource::getUrl('view', ['record' => $booking]),
                'date' => ($booking->exited_at ?? $booking->drop_off_on ?? $booking->preferred_date ?? $booking->created_at)->format('M j, Y'),
                'branch' => (string) $booking->branch?->name,
                'services' => $booking->servicesLabel() ?: '—',
                'status' => BookingResource::statusLabel($booking),
                'price' => $booking->total_price === null ? 'Size needed' : '₱'.number_format($booking->total_price),
            ])
            ->all();
    }
}
