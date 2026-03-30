<table class="w-full text-sm">
    <thead class="border-b text-left text-gray-500 dark:text-gray-400">
        <tr>
            <th class="pb-2 pr-4">Label</th>
            <th class="pb-2 pr-4">Code</th>
            <th class="pb-2 pr-4">Type</th>
            <th class="pb-2 pr-4">Dimensions</th>
            <th class="pb-2">Max Weight</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($boxes as $box)
            <tr class="border-b border-gray-100 dark:border-gray-700">
                <td class="py-2 pr-4">{{ $box->label }}</td>
                <td class="py-2 pr-4 font-mono text-xs">{{ $box->code }}</td>
                <td class="py-2 pr-4">{{ $box->type->getLabel() }}</td>
                <td class="py-2 pr-4">{{ $box->length }} x {{ $box->width }} x {{ $box->height }} in</td>
                <td class="py-2">{{ $box->max_weight }} lbs</td>
            </tr>
        @endforeach
    </tbody>
</table>
