{{--
    The table's selection count and its Select all / Deselect all links, shown in the
    header toolbar in place of Filament's selection indicator, which is a separate row
    that pushes the records down when the first one is checked. Rendered inside the
    table's `filamentTable` Alpine component, so it uses that component's state; the
    stock indicator is hidden in the panel theme.
--}}
<div
    x-cloak
    x-show="getSelectedRecordsCount()"
    role="status"
    aria-live="polite"
    aria-atomic="true"
    class="pb-ta-selection-summary"
>
    {{
        \Filament\Support\generate_loading_indicator_html(new \Filament\Support\View\ComponentAttributeBag([
            'x-show' => 'isLoading',
        ]))
    }}

    <span
        class="pb-ta-selection-summary-count"
        x-text="
            window.pluralize(@js(__('filament-tables::table.selection_indicator.selected_count')), getSelectedRecordsCount(), {
                count: new Intl.NumberFormat(@js(str_replace('_', '-', app()->getLocale()))).format(getSelectedRecordsCount()),
            })
        "
    ></span>

    <x-filament::link
        color="primary"
        tag="button"
        x-on:click="selectAllRecords"
        x-show="canSelectAllRecords()"
    >
        {{-- In the slot, not on the component tag: Blade leaves directives in component attributes uncompiled. --}}
        <span
            x-text="
                @js(__('filament-tables::table.selection_indicator.actions.select_all.label'))
                    .replace(':count', new Intl.NumberFormat(@js(str_replace('_', '-', app()->getLocale()))).format(parseInt($refs.allSelectableRecordsCount?.value ?? 0)))
            "
        ></span>
    </x-filament::link>

    <x-filament::link
        color="danger"
        tag="button"
        x-on:click="deselectAllRecords"
    >
        {{ __('filament-tables::table.selection_indicator.actions.deselect_all.label') }}
    </x-filament::link>
</div>
