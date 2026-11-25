<x-filament-panels::page>
    @php
        $summary = $this->getSummary();
        $pastShipBy = $this->getPastShipBy();
    @endphp

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Orders Shipped</div>
            <div class="text-2xl font-bold">{{ number_format($summary['orders']) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Median Time to Ship</div>
            <div class="text-2xl font-bold">{{ \App\Filament\Pages\Reports\FulfillmentSpeedReport::duration($summary['median_hours']) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Shipped by Ship-By</div>
            <div class="text-2xl font-bold">{{ $summary['on_time_rate'] === null ? '—' : number_format($summary['on_time_rate'], 1).'%' }}</div>
            @if ($summary['late'] > 0)
                <div class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ number_format($summary['late']) }} late</div>
            @endif
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Open Past Ship-By Now</div>
            <div class="text-2xl font-bold">
                <a href="{{ $pastShipBy['url'] }}" class="hover:underline">{{ number_format($pastShipBy['count']) }}</a>
            </div>
        </x-filament::section>
    </div>

    <p class="text-sm text-gray-600 dark:text-gray-400">
        Imported orders, counted on the day their first package shipped. Time to ship runs from when
        PolyBag imported the order, not when it was placed. Orders without a ship-by date are left out of
        the on-time figures.
    </p>

    <div class="flex items-center gap-3">
        <label for="fulfillment-group-by" class="text-sm font-medium text-gray-700 dark:text-gray-200">Group by</label>
        <x-filament::input.wrapper class="w-56">
            <x-filament::input.select id="fulfillment-group-by" wire:model.live="groupBy">
                @foreach ($this->groupings() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
