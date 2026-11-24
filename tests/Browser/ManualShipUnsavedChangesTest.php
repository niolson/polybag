<?php

use App\Enums\Role;
use App\Models\User;

/**
 * The scale keeps writing Weight, so leaving Manual Ship warns only of
 * changes the packer made to the rest of the form.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin, 'auto_ship_enabled' => false]));
});

/**
 * Whether leaving the page now would ask first.
 */
function manualShipWarnsBeforeLeaving(mixed $page): bool
{
    return $page->script(<<<'JS'
        (() => {
            const event = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(event);
            return event.defaultPrevented;
        })()
    JS);
}

it('does not warn about a changed weight, but does about a changed address', function (): void {
    $page = visit('/manual-ship');

    $page->assertSee('Manual Ship');

    expect(manualShipWarnsBeforeLeaving($page))->toBeFalse();

    $page->script(<<<'JS'
        (() => {
            const id = document.getElementById('manual-ship-form').closest('[wire\\:id]').getAttribute('wire:id');
            Livewire.find(id).data.weight = '3.20';
        })()
    JS);

    expect(manualShipWarnsBeforeLeaving($page))->toBeFalse();

    $page->script(<<<'JS'
        (() => {
            const id = document.getElementById('manual-ship-form').closest('[wire\\:id]').getAttribute('wire:id');
            Livewire.find(id).data.city = 'Portland';
        })()
    JS);

    expect(manualShipWarnsBeforeLeaving($page))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});
