<x-filament-panels::page>
    <x-filament::section>
        <dl class="divide-y divide-gray-950/5 dark:divide-white/10">
            <div class="flex items-center justify-between gap-4 py-3 first:pt-0">
                <dt class="text-sm text-gray-700 dark:text-gray-200">Customer app version</dt>
                <dd class="text-sm font-semibold text-gray-950 dark:text-white">{{ $version ?? 'None published' }}</dd>
            </div>
            @foreach ($checks as $check)
                <div class="flex items-center justify-between gap-4 py-3 last:pb-0">
                    <dt class="text-sm text-gray-700 dark:text-gray-200">{{ $check['label'] }}</dt>
                    <dd>
                        <x-filament::badge :color="$check['on'] ? 'success' : 'gray'" :icon="$check['on'] ? 'heroicon-m-check-circle' : 'heroicon-m-minus-circle'">
                            {{ $check['on'] ? 'Yes' : 'No' }}
                        </x-filament::badge>
                    </dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>
</x-filament-panels::page>
