<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Concerns\RefreshesLive;
use App\Filament\Resources\Bookings\BookingResource;
use App\Support\BookingDesk;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBookings extends ListRecords
{
    use RefreshesLive;

    protected function liveArea(): string
    {
        return 'bookings';
    }

    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add a booking'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return BookingDesk::DEFAULT_TAB;
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return collect(BookingDesk::TABS)->map(function (string $label, string $key) {
            $tab = Tab::make($label)->modifyQueryUsing(fn (Builder $query) => BookingDesk::tab($query, $key));

            return in_array($key, BookingDesk::COUNTED_TABS, true)
                ? $tab->badge(fn () => BookingDesk::tab(BookingResource::getEloquentQuery(), $key)->count() ?: null)->badgeColor('warning')
                : $tab;
        })->all();
    }
}
