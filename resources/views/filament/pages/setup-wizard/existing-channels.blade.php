<table class="w-full text-sm">
    <thead class="border-b text-left text-gray-500 dark:text-gray-400">
        <tr>
            <th class="pb-2 pr-4">Name</th>
            <th class="pb-2">Aliases</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($channels as $channel)
            <tr class="border-b border-gray-100 dark:border-gray-700">
                <td class="py-2 pr-4">{{ $channel->name }}</td>
                <td class="py-2 text-gray-500">{{ $channel->aliases->pluck('reference')->join(', ') ?: '-' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
