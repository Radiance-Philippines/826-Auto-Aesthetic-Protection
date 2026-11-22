@php
    $cardView = 'filament.pages.partials.queue-card';
@endphp

<x-filament-panels::page>
    @if (! $shop)
        <div class="rounded-xl bg-white p-6 text-sm text-gray-600 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10">
            @if ($branches === [])
                You’re not assigned to a branch yet. Ask an owner to add you to one in Settings → Users.
            @else
                Pick a branch.
            @endif
        </div>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                @if (count($branches) > 1)
                    <div class="min-w-56">{{ $this->branchForm }}</div>
                @else
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $shop->name }}</span>
                @endif

                <div class="flex items-center gap-1">
                    <x-filament::icon-button icon="heroicon-m-chevron-left" color="gray" label="Earlier" wire:click="previous" />
                    <h2 class="min-w-44 text-center text-base font-semibold text-gray-950 dark:text-white" aria-live="polite">{{ $heading }}</h2>
                    <x-filament::icon-button icon="heroicon-m-chevron-right" color="gray" label="Later" wire:click="next" />
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-calendar" wire:click="today">Today</x-filament::button>
                </div>
            </div>
        </div>

        {{-- The day's header strip --}}
        <div @class([
            'flex flex-wrap items-center justify-between gap-3 rounded-xl px-4 py-3 text-sm ring-1',
            'bg-white text-gray-700 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-200 dark:ring-white/10' => $state === 'ok',
            'bg-danger-50 text-danger-800 ring-danger-200 dark:bg-danger-400/10 dark:text-danger-300 dark:ring-danger-400/30' => $state === 'full',
            'bg-gray-50 text-gray-600 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10' => $state === 'closed',
        ]) role="status">
            @if ($cap < 1)
                <p>This branch has no daily cap set, so it takes no online bookings. Set it in Shop → Branches.</p>
            @else
                <p class="font-semibold">Dropping off {{ $isToday ? 'today' : 'on '.$heading }}: {{ $units }} of {{ $cap }}</p>
                <p>
                    @if ($state === 'closed')
                        Closed day
                    @elseif ($state === 'full')
                        {{ $units > $cap ? 'Over the cap' : 'Full' }}
                    @else
                        {{ $cap - $units }} {{ $cap - $units === 1 ? 'place' : 'places' }} left
                    @endif
                </p>
            @endif
        </div>

        @if ($sizeReviews || $toSchedule)
            <p class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-warning-700 dark:text-warning-300">
                @if ($sizeReviews)
                    <a href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl('index', ['activeTab' => 'size']) }}" class="underline">{{ $sizeReviews }} {{ $sizeReviews === 1 ? 'unit needs' : 'units need' }} a size review</a>
                @endif
                @if ($toSchedule)
                    <a href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl('index', ['activeTab' => 'home']) }}" class="underline">{{ $toSchedule }} home {{ $toSchedule === 1 ? 'request' : 'requests' }} to schedule</a>
                @endif
            </p>
        @endif

        {{-- The queue: five columns on a tablet and up, stacked on a phone --}}
        <div class="grid grid-cols-1 gap-3 md:grid-cols-5">
            @foreach ($columns as $status => $column)
                <section class="min-w-0 rounded-xl bg-gray-50 p-2 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10" aria-labelledby="queue-{{ $status }}">
                    <h3 id="queue-{{ $status }}" class="flex items-center justify-between px-1 py-1 text-sm font-semibold text-gray-900 dark:text-white">
                        {{ $column['label'] }}
                        <span class="rounded-full bg-gray-200 px-2 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">{{ count($column['cards']) }}</span>
                    </h3>
                    <div class="space-y-2">
                        @forelse ($column['cards'] as $card)
                            @include($cardView, ['card' => $card, 'column' => $column])
                        @empty
                            <p class="px-1 py-2 text-xs text-gray-500 dark:text-gray-400">Nothing here.</p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>

        {{-- Today's expected arrivals --}}
        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" aria-labelledby="queue-arrivals">
            <h3 id="queue-arrivals" class="text-sm font-semibold text-gray-950 dark:text-white">Expected today</h3>
            @if ($arrivals === [])
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">No booked units still to arrive today.</p>
            @else
                <ul class="mt-2 divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($arrivals as $card)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <a href="{{ $card['url'] }}" wire:navigate class="min-w-0">
                                <span class="block truncate text-sm font-medium text-gray-950 dark:text-white">{{ $card['name'] }} · {{ $card['unit'] }}</span>
                                <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $card['services'] }}</span>
                            </a>
                            <x-filament::button size="xs" color="info" icon="heroicon-m-arrow-right-end-on-rectangle" wire:click="advance({{ $card['id'] }})" wire:confirm="Mark {{ $card['name'] }} as arrived?">Mark arrived</x-filament::button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</x-filament-panels::page>
