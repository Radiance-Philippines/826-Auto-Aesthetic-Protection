<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Inbox;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Filament\Widgets\Concerns\UsesInsights;
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
        $count = $this->insights()->attention();
        $stat = fn (string $label, ?int $value, string $help, Heroicon $icon, ?string $url = null) => $value === null ? null : Stat::make($label, $value)
            ->description($help)
            ->descriptionIcon($icon)
            ->color($value > 0 ? 'warning' : 'gray')
            ->url($url);

        return array_values(array_filter([
            $stat('Units to size', $count['size'], 'Open bookings whose unit has no size or price yet.', Heroicon::OutlinedScale, BookingResource::getUrl('index', ['tab' => 'size'])),
            $stat('Screenshots to check', $count['screenshots'], 'Payment screenshots waiting to be confirmed.', Heroicon::OutlinedPhoto, BookingResource::getUrl('index', ['tab' => 'action'])),
            $stat('Home-service requests', $count['home'], 'Requests nobody has scheduled yet.', Heroicon::OutlinedHome, BookingResource::getUrl('index', ['tab' => 'home'])),
            $stat('Reviews to approve', $count['reviews'], 'Customer reviews waiting for a decision.', Heroicon::OutlinedStar, ReviewResource::getUrl('index')),
            $stat('Unread chats', $count['chats'], 'Messenger conversations with new messages.', Heroicon::OutlinedChatBubbleLeftRight, Inbox::getUrl()),
            $stat('Late units', $count['late'], 'In the shop, past their ready date.', Heroicon::OutlinedClock, BookingResource::getUrl('index', ['tab' => 'in_shop'])),
        ]));
    }
}
