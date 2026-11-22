<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Queue;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Today at the signed-in team member's branches: drop-offs, cars in the
 * shop, and what needs them (screenshots, home-service requests and units
 * waiting for their size review).
 */
class BookingStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canAccessArea('bookings.view');
    }

    // Gentle on SQLite: refresh once a minute, not every 5 seconds
    protected ?string $pollingInterval = '60s';

    protected function getColumns(): int|array|null
    {
        return ['md' => 3];
    }

    protected function getStats(): array
    {
        $scoped = fn () => Booking::query()->visibleTo(auth()->user());

        $dropOffs = $scoped()->countingTowardCap()->where('drop_off_on', today()->toDateString())->count();
        $inShop = $scoped()->whereIn('status', Booking::IN_SHOP)->count();
        $ready = $scoped()->where('status', 'ready')->count();
        $toDo = $scoped()->needsAction()->count();
        $sizes = $scoped()->needsSizeReview()->count();

        return [
            Stat::make('Drop-offs today', $dropOffs)
                ->description('Booked for today')
                ->descriptionIcon(Heroicon::OutlinedCalendarDays)
                ->color($dropOffs ? 'primary' : 'gray')
                ->url(Queue::getUrl()),
            Stat::make('In the shop', $inShop)
                ->description($ready ? "{$ready} ready" : 'Arrived or being worked on')
                ->descriptionIcon(Heroicon::OutlinedWrenchScrewdriver)
                ->color($inShop ? 'info' : 'gray')
                ->url(BookingResource::getUrl('index', ['activeTab' => 'in_shop'])),
            Stat::make('Needs you', $toDo)
                ->description($toDo ? ($sizes ? "{$sizes} to size, plus screenshots and home visits" : 'Screenshots to check, home visits to schedule') : 'All caught up')
                ->descriptionIcon(Heroicon::OutlinedInboxArrowDown)
                ->color($toDo ? 'warning' : 'gray')
                ->url(BookingResource::getUrl('index', ['activeTab' => 'action'])),
        ];
    }
}
