<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Components\Contracts\HasEmbeddedView;
use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
use Filament\Support\View\Components\ToggleComponent;
use Illuminate\Support\Arr;
use Illuminate\Support\Js;

use function Filament\Support\get_component_color_classes;

/**
 * A header action rendered as a labeled switch, bound to the public Livewire property
 * sharing the action's name — the toggle counterpart of Filament's `SelectAction`.
 *
 * The binding is live, so flipping the switch re-renders the page and any actions whose
 * visibility depends on the property.
 */
class ToggleAction extends Action implements HasEmbeddedView
{
    public const TOGGLE_VIEW = 'app::toggle-action';

    protected string $view = self::TOGGLE_VIEW;

    public function toEmbeddedHtml(): string
    {
        $name = $this->getName();
        $isOn = (bool) data_get($this->getLivewire(), $name);
        $isDisabled = $this->isDisabled();
        $onColor = $this->getColor() ?? 'primary';

        $onClasses = Arr::toCssClasses([
            'fi-toggle-on',
            ...get_component_color_classes(ToggleComponent::class, $onColor),
        ]);

        $offClasses = Arr::toCssClasses([
            'fi-toggle-off',
            ...get_component_color_classes(ToggleComponent::class, 'gray'),
        ]);

        $toggleAttributes = (new FilamentComponentAttributeBag)
            ->merge([
                'aria-checked' => $isOn ? 'true' : 'false',
                'disabled' => $isDisabled,
                'role' => 'switch',
                'type' => 'button',
                'wire:loading.attr' => 'disabled',
                'wire:target' => $name,
            ], escape: false)
            ->class(['fi-toggle', $isOn ? $onClasses : $offClasses]);

        ob_start(); ?>

        <label class="fi-ac-toggle-action inline-flex cursor-pointer items-center gap-x-3">
            <button
                x-data="{ state: $wire.$entangle(<?= e(Js::from($name)) ?>, true) }"
                x-bind:aria-checked="state ? 'true' : 'false'"
                x-on:click="state = ! state"
                x-bind:class="state ? <?= e(Js::from($onClasses)) ?> : <?= e(Js::from($offClasses)) ?>"
                <?= $toggleAttributes->toHtml() ?>
            >
                <div>
                    <div aria-hidden="true"></div>
                    <div aria-hidden="true"></div>
                </div>
            </button>

            <span class="text-sm font-medium text-gray-950 dark:text-white">
                <?= e($this->getLabel()) ?>
            </span>
        </label>

        <?php return ob_get_clean();
    }

    public function hasView(): bool
    {
        return ($this->getView() !== static::TOGGLE_VIEW) && parent::hasView();
    }
}
