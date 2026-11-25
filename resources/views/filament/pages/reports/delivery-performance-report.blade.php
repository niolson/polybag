<x-filament-panels::page>
    @php
        $summary = $this->getSummary();
        $report = \App\Filament\Pages\Reports\DeliveryPerformanceReport::class;
    @endphp

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Delivered</div>
            <div class="text-2xl font-bold">{{ $report::percent($summary['delivered_rate']) }}</div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ number_format($summary['delivered']) }} of {{ number_format($summary['packages'] - $summary['untracked']) }} tracked packages</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Median Transit</div>
            <div class="text-2xl font-bold">{{ $report::days($summary['median_days']) }}</div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">90% within {{ $report::days($summary['p90_days']) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">On Time vs Deliver-By</div>
            <div class="text-2xl font-bold">{{ $report::percent($summary['on_time_rate']) }}</div>
            @if ($summary['late'] > 0)
                <div class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ number_format($summary['late']) }} late</div>
            @endif
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Exceptions and Stuck</div>
            <div class="text-2xl font-bold">{{ number_format($summary['exceptions'] + $summary['stuck_pre_transit']) }}</div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ number_format($summary['exceptions']) }} exceptions, {{ number_format($summary['stuck_pre_transit']) }} pre-transit over 48 hours</div>
        </x-filament::section>
    </div>

    <p class="text-sm text-gray-600 dark:text-gray-400">
        Packages shipped in this range. Transit is calendar days from purchase to the delivery scan. A package
        is late when it was delivered after its deliver-by date, or is still out once that date has passed; one
        reported delivered without a delivery time counts as delivered but has no transit time. Delivered % is
        over packages with tracking. Recently shipped packages may still be in transit.
    </p>

    <div class="flex items-center gap-3">
        <label for="delivery-group-by" class="text-sm font-medium text-gray-700 dark:text-gray-200">Group by</label>
        <x-filament::input.wrapper class="w-56">
            <x-filament::input.select id="delivery-group-by" wire:model.live="groupBy">
                @foreach ($report::GROUPINGS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
