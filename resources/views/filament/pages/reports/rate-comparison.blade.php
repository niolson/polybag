<x-filament-panels::page>
    @php
        $summary = $this->getSavingsSummary();
        $share = $summary['spend'] > 0 ? ($summary['savings'] / $summary['spend']) * 100 : null;
    @endphp

    <div class="mb-6">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Potential Savings &middot; {{ $this->getDateRangeLabel() }}</div>
            <div class="text-2xl font-bold">${{ number_format($summary['savings'], 2) }}</div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                @if ($share !== null)
                    {{ number_format($share, 1) }}% of the ${{ number_format($summary['spend'], 2) }} spent on postage for {{ number_format($summary['packages']) }} compared packages.
                @else
                    No packages with more than one quote in this range.
                @endif
                Counted as the selected rate minus the cheapest rate quoted for the same package, whatever delivery time that cheaper rate offered.
            </div>
        </x-filament::section>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
