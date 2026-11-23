@if (count($jobs) === 0)
    <p class="text-sm text-gray-500 dark:text-gray-400">No bookings for this unit at your branches.</p>
@else
    <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full min-w-max divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    @foreach (['Reference', 'Date', 'Branch', 'Services', 'Status', 'Price'] as $column)
                        <th scope="col" class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($jobs as $job)
                    <tr>
                        <td class="px-3 py-2"><a href="{{ $job['url'] }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $job['reference'] }}</a></td>
                        <td class="px-3 py-2 text-gray-950 dark:text-white">{{ $job['date'] }}</td>
                        <td class="px-3 py-2 text-gray-950 dark:text-white">{{ $job['branch'] }}</td>
                        <td class="px-3 py-2 text-gray-950 dark:text-white">{{ $job['services'] }}</td>
                        <td class="px-3 py-2 text-gray-950 dark:text-white">{{ $job['status'] }}</td>
                        <td class="px-3 py-2 text-gray-950 tabular-nums dark:text-white">{{ $job['price'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
