<?php

it('explains what each mode of the ship button does', function (): void {
    $this->blade('<x-shipping-submit-button />')
        ->assertSee('the service a shipping rule picks, otherwise the cheapest rate this shipping method allows', false)
        ->assertSee('Opens the rate list if nothing can be bought automatically', false)
        ->assertSee('Opens the rate list to compare rates and choose a shipping service', false);
});

it('leaves the ship tooltip off a button given its own label', function (): void {
    $this->blade('<x-shipping-submit-button label="Pack" />')
        ->assertSee('Pack')
        ->assertDontSee('title=', false);
});
