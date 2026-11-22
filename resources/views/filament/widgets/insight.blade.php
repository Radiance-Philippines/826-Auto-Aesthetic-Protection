<x-filament-widgets::widget>
    <x-filament::section :heading="$heading" :description="$description">
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach ($blocks as $block)
                <div class="min-w-0 {{ ($block['wide'] ?? false) ? 'lg:col-span-2' : '' }}">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $block['title'] }}</h3>

                    @if (filled($block['help'] ?? null))
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $block['help'] }}</p>
                    @endif

                    @if (($block['type'] ?? 'table') === 'stats')
                        <dl class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach ($block['items'] as $item)
                                <div class="rounded-lg bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                                    <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $item['label'] }}</dt>
                                    <dd class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">
                                        @if (filled($item['url'] ?? null))
                                            <a href="{{ $item['url'] }}" class="hover:underline">{{ $item['value'] }}</a>
                                        @else
                                            {{ $item['value'] }}
                                        @endif
                                    </dd>
                                    @if (filled($item['help'] ?? null))
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $item['help'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </dl>
                    @elseif (count($block['rows']) === 0)
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ $block['empty'] ?? 'Nothing to show in this period.' }}</p>
                    @else
                        <div class="mt-3 overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                            <table class="w-full min-w-max divide-y divide-gray-200 text-sm dark:divide-white/10">
                                <thead class="bg-gray-50 dark:bg-white/5">
                                    <tr>
                                        @foreach ($block['columns'] as $index => $column)
                                            <th scope="col" class="px-3 py-2 text-xs font-medium text-gray-500 dark:text-gray-400 {{ $index === 0 ? 'text-start' : 'text-end' }}">{{ $column }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                    @foreach ($block['rows'] as $row)
                                        <tr>
                                            @foreach ($row as $index => $cell)
                                                <td class="px-3 py-2 text-gray-950 dark:text-white {{ $index === 0 ? 'text-start' : 'text-end tabular-nums' }}">{{ $cell }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
