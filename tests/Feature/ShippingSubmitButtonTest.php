<?php

it('names each mode of the ship button without a tooltip', function (): void {
    $this->blade('<x-shipping-submit-button />')
        ->assertSee("'Buy & Print Label'", false)
        ->assertSee('Choose a Rate')
        ->assertDontSee('title=', false);
});

it('shows a button given its own label', function (): void {
    $this->blade('<x-shipping-submit-button label="Pack" />')
        ->assertSee('Pack')
        ->assertDontSee('title=', false);
});
