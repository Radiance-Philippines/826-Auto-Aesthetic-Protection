<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesInsights;
use App\Support\Dashboard\Cards;
use App\Support\InboxAccess;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What is waiting right now at the user's branches, whatever period is
 * chosen. Each box only appears for the roles that act on it.
 */
class NeedsAttention extends StatsOverviewWidget
{
    use UsesInsights;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '60s';

    protected ?string $heading = 'Needs attention';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->canAccessAnyArea('bookings.sales', 'queue.update', 'reviews') || InboxAccess::allows($user));
    }

    protected function getColumns(): int|array|null
    {
        return ['md' => 3, 'xl' => 3];
    }

    protected function getStats(): array
    {
        $icons = [
            'size' => Heroicon::OutlinedScale,
            'screenshots' => Heroicon::OutlinedPhoto,
            'home' => Heroicon::OutlinedHome,
            'reviews' => Heroicon::OutlinedStar,
            'chats' => Heroicon::OutlinedChatBubbleLeftRight,
            'late' => Heroicon::OutlinedClock,
        ];

        return array_map(fn (array $stat) => Stat::make($stat['label'], $stat['value'])
            ->description($stat['help'])
            ->descriptionIcon($icons[$stat['key']])
            ->color($stat['tone'])
            ->url($stat['url']), Cards::attention($this->insights()));
    }
}
