<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Models\Guest;
use App\Support\Rewards;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The customer's points ledger: the points their referral code earned and
 * the points the team redeemed for them, newest first.
 */
class PointsRelationManager extends RelationManager
{
    protected static string $relationship = 'rewardRedemptions';

    protected static ?string $title = 'Points';

    public function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->recordAction(fn () => null)
            ->recordUrl(fn () => null)
            ->emptyStateHeading('No points earned or spent yet')
            ->records(fn () => Rewards::ledger($this->getOwnerRecord() instanceof Guest ? $this->getOwnerRecord() : abort(404))->take(200))
            ->columns([
                TextColumn::make('date'),
                TextColumn::make('line')->label('What')->wrap(),
                TextColumn::make('points')
                    ->state(fn (array $record) => ($record['points'] > 0 ? '+' : '').$record['points'])
                    ->color(fn (array $record) => $record['points'] > 0 ? 'success' : 'warning'),
                TextColumn::make('pesos')->label('Pesos off')->formatStateUsing(fn (?int $state) => $state ? '₱'.number_format($state) : '—'),
                TextColumn::make('by')->label('By')->placeholder('—'),
            ]);
    }
}
