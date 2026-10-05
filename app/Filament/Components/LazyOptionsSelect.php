<?php

namespace App\Filament\Components;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Livewire\Attributes\Renderless;

/**
 * A select whose options are loaded when its dropdown opens, not when the form
 * renders.
 *
 * Filament's own dynamic options evaluate `options()` on every render as well
 * as on open, so options that cost a remote call would be fetched on every
 * Livewire round trip. Here the render sees no options — a saved value is
 * labeled through `getOptionLabelUsing()` — and the list arrives on open, to
 * be filtered in the browser as the user types.
 */
class LazyOptionsSelect extends Select
{
    protected ?Closure $loadOptionsUsing = null;

    /**
     * @param  Closure  $callback  Evaluated with Filament's usual injection; returns value => label
     */
    public function loadOptionsUsing(Closure $callback): static
    {
        $this->loadOptionsUsing = $callback;
        $this->dynamicOptions();

        return $this;
    }

    /**
     * @return array<array{'label': string, 'value': string}>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function getOptionsForJs(): array
    {
        if (! $this->loadOptionsUsing) {
            return parent::getOptionsForJs();
        }

        return $this->transformOptionsForJs($this->evaluate($this->loadOptionsUsing) ?? []);
    }
}
