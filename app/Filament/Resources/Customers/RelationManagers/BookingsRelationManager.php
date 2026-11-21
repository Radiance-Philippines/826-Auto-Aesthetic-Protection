<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The customer's bookings at the branches the team member works at.
 */
class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = 'Bookings';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->visibleTo(auth()->user())->with(['branch', 'items']))
            ->recordUrl(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('reference')
                    ->url(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record]))
                    ->description(fn (Booking $record) => $record->whenLabel()),
                TextColumn::make('services')->state(fn (Booking $record) => $record->servicesLabel())->wrap()->limit(60),
                TextColumn::make('branch.name')->label('Branch'),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (Booking $record) => BookingResource::statusLabel($record))
                    ->color(fn (Booking $record) => BookingResource::statusColor($record->status)),
                TextColumn::make('total_price')
                    ->label('Price')
                    ->formatStateUsing(fn (?int $state) => $state === null ? 'Size needed' : '₱'.number_format($state)),
            ]);
    }
}
