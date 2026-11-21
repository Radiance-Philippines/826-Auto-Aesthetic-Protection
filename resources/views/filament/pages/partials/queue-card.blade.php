<article class="rounded-lg bg-white p-2.5 text-sm shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <a href="{{ $card['url'] }}" wire:navigate class="block">
        <span class="block truncate font-semibold text-gray-950 dark:text-white">{{ $card['unit'] }}</span>
        <span class="block truncate text-xs text-gray-600 dark:text-gray-300">{{ $card['name'] }}</span>
        <span class="mt-1 block text-xs text-gray-600 dark:text-gray-300">{{ $card['services'] }}</span>
        @if ($card['readyOn'])
            <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">Ready around {{ $card['readyOn'] }}</span>
        @endif
    </a>

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

    @if ($card['canAdvance'])
        <x-filament::button class="mt-2" size="xs" color="gray" outlined :icon="$column['icon']" wire:click="advance({{ $card['id'] }})" wire:confirm="{{ $card['button'] }}: {{ $card['name'] }}, {{ $card['unit'] }}?">{{ $card['button'] }}</x-filament::button>
    @endif
</article>
