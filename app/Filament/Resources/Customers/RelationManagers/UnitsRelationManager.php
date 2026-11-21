<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Models\Booking;
use App\Models\Vehicle;
use App\Models\VehicleSize;
use App\Support\Notifier;
use App\Support\Reservations;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The customer's units. "Set size" gives a unit its size class and prices
 * its open bookings that were waiting for it.
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
            ->modifyQueryUsing(fn ($query) => $query->with('vehicleSize'))
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
            ])
            ->recordActions([
                Action::make('setSize')
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
}
