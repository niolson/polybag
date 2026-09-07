@php
    /**
     * Rows here are live customer order data — render only, never log.
     *
     * @var \App\DataTransferObjects\QueryPreviewResult|null $preview
     * @var array<string, string|null> $writeChecks
     */
    $format = function (mixed $value): string {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return \Illuminate\Support\Str::limit(is_scalar($value) ? (string) $value : json_encode($value), 60);
    };

    $columns = fn (array $rows, string $side): array => array_keys(array_merge(
        [], ...array_map(fn (array $row): array => $row[$side], $rows),
    ));
@endphp

<div class="space-y-6 text-sm">
    @if ($expired)
        <div class="rounded-lg bg-gray-50 p-4 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
            <p class="font-medium">This preview has expired</p>
            <p class="mt-1">
                Close this dialog and choose Preview Queries again for a fresh look at the source database.
            </p>
        </div>
    @elseif ($connectionError)
        <div class="rounded-lg bg-danger-50 p-4 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
            <p class="font-medium">Connection failed</p>
            <p class="mt-1">{{ $connectionError }}</p>
        </div>
    @else
        @foreach ($preview->errors as $label => $message)
            <div class="rounded-lg bg-danger-50 p-4 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                <p class="font-medium">{{ $label }} failed</p>
                <p class="mt-1 font-mono text-xs">{{ $message }}</p>
            </div>
        @endforeach

        @foreach ([
            ['heading' => 'Shipments', 'rows' => $preview->shipments, 'empty' => 'The shipments query returned no rows.'],
            ['heading' => 'Items', 'rows' => $preview->items, 'empty' => 'The items query returned no rows for this shipment.'],
        ] as $section)
            @continue($section['heading'] === 'Items' && $preview->itemsReference === null)

            <div class="space-y-3">
                <div class="flex items-baseline justify-between gap-4">
                    <h3 class="text-base font-semibold">{{ $section['heading'] }}</h3>

                    @if ($section['heading'] === 'Items')
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            shipment_reference = <span class="font-mono">{{ $preview->itemsReference }}</span>
                        </span>
                    @else
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            First {{ $previewRows }} rows
                        </span>
                    @endif
                </div>

                @if ($section['rows'] === [])
                    <p class="text-gray-500 dark:text-gray-400">{{ $section['empty'] }}</p>
                @else
                    @foreach ([['side' => 'raw', 'title' => 'Source columns'], ['side' => 'mapped', 'title' => 'Mapped internal fields']] as $view)
                        <div class="space-y-1">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $view['title'] }}
                            </p>

                            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                                <table class="w-full text-left text-xs">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            @foreach ($columns($section['rows'], $view['side']) as $column)
                                                <th class="whitespace-nowrap px-3 py-2 font-medium">{{ $column }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($section['rows'] as $row)
                                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                                @foreach ($columns($section['rows'], $view['side']) as $column)
                                                    <td class="whitespace-nowrap px-3 py-2 font-mono">
                                                        {{ $format($row[$view['side']][$column] ?? null) }}
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @if ($view['side'] === 'mapped' && $columns($section['rows'], 'mapped') === [])
                                <p class="text-warning-600 dark:text-warning-400">
                                    The field mapping resolved no fields for these rows — check that its source column
                                    names match the columns above.
                                </p>
                            @endif
                        </div>
                    @endforeach
                @endif
            </div>
        @endforeach
    @endif

    @if ($writeChecks !== [])
        <div class="space-y-1">
            <h3 class="text-base font-semibold">Write queries</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Checked for valid syntax only — a preview never executes a write against the source database.
            </p>

            <ul class="mt-2 space-y-1">
                @foreach ($writeChecks as $label => $error)
                    <li>
                        @if ($error === null)
                            <span class="font-medium text-success-600 dark:text-success-400">{{ $label }}: valid</span>
                        @else
                            <span class="font-medium text-danger-600 dark:text-danger-400">{{ $label }}:</span>
                            <span class="font-mono text-xs">{{ $error }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
