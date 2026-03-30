<table class="w-full text-sm">
    <thead class="border-b text-left text-gray-500 dark:text-gray-400">
        <tr>
            <th class="pb-2 pr-4">Name</th>
            <th class="pb-2 pr-4">Commitment</th>
            <th class="pb-2 pr-4">Carrier Services</th>
            <th class="pb-2">Aliases</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($methods as $method)
            <tr class="border-b border-gray-100 dark:border-gray-700">
                <td class="py-2 pr-4">{{ $method->name }}</td>
                <td class="py-2 pr-4">{{ $method->commitment_days ? $method->commitment_days . ' days' : '-' }}</td>
                <td class="py-2 pr-4 text-gray-500">
                    {{ $method->carrierServices->map(fn ($carrierService) => $carrierService->carrier->name . ': ' . $carrierService->name)->join(', ') ?: '-' }}
                </td>
                <td class="py-2 text-gray-500">{{ $method->aliases->pluck('reference')->join(', ') ?: '-' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
