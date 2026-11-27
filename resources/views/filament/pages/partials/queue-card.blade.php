<article class="rounded-lg bg-white p-2.5 text-sm shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <a href="{{ $card['url'] }}" wire:navigate class="block">
        <span class="block truncate font-semibold text-gray-950 dark:text-white">{{ $card['unit'] }}</span>
        <span class="block truncate text-xs text-gray-600 dark:text-gray-300">{{ $card['name'] }}</span>
        <span class="mt-1 block text-xs text-gray-600 dark:text-gray-300">{{ $card['services'] }}</span>
        @if ($card['readyOn'])
            <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">Ready around {{ $card['readyOn'] }}</span>
        @endif
    </a>

    @if ($card['assignedTo'])
        <p class="mt-1 break-words text-xs font-medium text-gray-800 dark:text-gray-100">Assigned to {{ $card['assignedTo'] }}</p>
    @endif

    @if ($card['needsSize'] || $card['note'] || $card['home'])
        <div class="mt-1.5 flex flex-wrap gap-1">
            @if ($card['needsSize'])
                <x-filament::badge color="warning" size="sm">Size needed</x-filament::badge>
            @endif
            @if ($card['note'])
                <x-filament::badge color="info" size="sm">{{ $card['note'] }}</x-filament::badge>
            @endif
            @if ($card['home'])
                <x-filament::badge color="gray" size="sm">Home visit</x-filament::badge>
            @endif
        </div>
    @endif

    @if ($card['canAdvance'] || $card['canAssign'])
        <div class="mt-2 flex flex-wrap gap-1.5">
            @if ($card['canAdvance'])
                <x-filament::button size="xs" color="gray" outlined :icon="$column['icon']" wire:click="advance({{ $card['id'] }})" wire:confirm="{{ $card['button'] }}: {{ $card['name'] }}, {{ $card['unit'] }}?">{{ $card['button'] }}</x-filament::button>
            @endif
            @if ($card['canAssign'])
                <x-filament::button size="xs" color="gray" outlined icon="heroicon-m-user" wire:click="mountAction('assignedTo', { booking: {{ $card['id'] }} })" aria-label="{{ ($card['assignedTo'] ? 'Change who is on ' : 'Assign someone to ').$card['unit'] }}">{{ $card['assignedTo'] ? 'Change' : 'Assign' }}</x-filament::button>
            @endif
        </div>
    @endif
</article>
