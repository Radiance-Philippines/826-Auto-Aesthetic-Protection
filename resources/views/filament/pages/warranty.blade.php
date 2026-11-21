<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-3">
        <x-filament::input.wrapper class="w-full max-w-xl" prefix-icon="heroicon-m-magnifying-glass">
            <x-filament::input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Plate, customer name, phone or booking reference"
                aria-label="Search for a finished job"
            />
        </x-filament::input.wrapper>

        @if ($guest)
            <span class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                Showing
                <a href="{{ $guestUrl }}" class="font-semibold underline">{{ $guest->name }}</a>’s finished jobs
                <x-filament::button size="xs" color="gray" icon="heroicon-m-x-mark" wire:click="clearCustomer">Show all</x-filament::button>
            </span>
        @endif
    </div>

    @if (! $searched)
        <div class="rounded-xl bg-white p-6 text-sm text-gray-600 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10">
            Search for a finished job to see its warranty.
        </div>
    @elseif ($results === [])
        <div class="rounded-xl bg-white p-6 text-sm text-gray-600 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10" role="status">
            No finished job found. Warranty starts on the day a unit exits, so a booking that hasn’t exited isn’t listed.
        </div>
    @else
        <div class="space-y-4">
            @foreach ($results as $booking)
                <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" aria-label="Booking {{ $booking['reference'] }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                                <a href="{{ $booking['url'] }}" class="underline">{{ $booking['reference'] }}</a>
                                · {{ $booking['name'] }}
                            </h2>
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                {{ $booking['unit'] ?: '—' }} · {{ $booking['branch'] }} · {{ $booking['phone'] }} · finished {{ $booking['finished'] }}
                            </p>
                        </div>
                        <x-filament::button size="sm" color="gray" icon="heroicon-m-wrench-screwdriver" wire:click="mountAction('logBackjob', { booking: {{ $booking['id'] }} })">
                            Log a backjob
                        </x-filament::button>
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th class="py-2 pe-4 font-medium">Service done</th>
                                    <th class="py-2 pe-4 font-medium">Warranty</th>
                                    <th class="py-2 pe-4 font-medium">Covered until</th>
                                    <th class="py-2 pe-4 font-medium">Status</th>
                                    <th class="py-2 font-medium">Covers backjobs for</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                                @foreach ($booking['items'] as $item)
                                    <tr>
                                        <td class="py-2 pe-4 font-medium text-gray-950 dark:text-white">{{ $item['name'] }}</td>
                                        <td class="py-2 pe-4">{{ $item['months'] ? $item['months'].' '.($item['months'] === 1 ? 'month' : 'months') : '—' }}</td>
                                        <td class="py-2 pe-4">{{ $item['ends'] ?? '—' }}</td>
                                        <td class="py-2 pe-4">
                                            @if ($item['status'] === 'active')
                                                <x-filament::badge color="success">Active</x-filament::badge>
                                            @elseif ($item['status'] === 'expired')
                                                <x-filament::badge color="gray">Expired</x-filament::badge>
                                            @else
                                                <x-filament::badge color="gray">No warranty</x-filament::badge>
                                            @endif
                                        </td>
                                        <td class="py-2">{{ $item['covers'] === [] ? '—' : implode(', ', $item['covers']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 border-t border-gray-950/5 pt-3 dark:border-white/10">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Backjob history</h3>
                        @if ($booking['backjobs'] === [])
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">None logged.</p>
                        @else
                            <ul class="mt-2 space-y-2">
                                @foreach ($booking['backjobs'] as $backjob)
                                    <li class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                        <span>
                                            <span class="font-medium text-gray-950 dark:text-white">{{ $backjob['on'] }}</span>
                                            · {{ $backjob['service'] ?? 'Service' }} · {{ $backjob['type'] }}
                                            @if ($backjob['note'])
                                                <span class="text-gray-600 dark:text-gray-300">· {{ $backjob['note'] }}</span>
                                            @endif
                                        </span>
                                        @if ($backjob['open'])
                                            <x-filament::button size="xs" color="success" icon="heroicon-m-check-circle" wire:click="mountAction('markBackjobDone', { backjob: {{ $backjob['id'] }} })">
                                                Mark done
                                            </x-filament::button>
                                        @else
                                            <x-filament::badge color="success">Done</x-filament::badge>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
