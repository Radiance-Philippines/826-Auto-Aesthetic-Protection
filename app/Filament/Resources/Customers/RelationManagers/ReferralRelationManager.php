<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Models\ReferralRedemption;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Who used this customer's referral code, and the points it earned.
 */
class ReferralRelationManager extends RelationManager
{
    protected static string $relationship = 'referralRedemptions';

    protected static ?string $title = 'Referral';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['usedBy', 'booking']))
            ->emptyStateHeading('No one has used this code yet')
            ->columns([
                TextColumn::make('usedBy.name')->label('Used by'),
                TextColumn::make('booking.reference')->label('Booking'),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (ReferralRedemption $record) => ucfirst($record->status()))
                    ->color(fn (ReferralRedemption $record) => match ($record->status()) {
                        'awarded' => 'success',
                        'voided' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('discount_amount')
                    ->label('Discount')
                    ->formatStateUsing(fn (?int $state) => $state === null ? '—' : '₱'.number_format($state)),
                TextColumn::make('points_awarded')->label('Points'),
            ]);
    }
}
