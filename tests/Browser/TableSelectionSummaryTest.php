<?php

use App\Enums\Role;
use App\Models\Shipment;
use App\Models\User;

/**
 * Checking a record shows the selection count in the table's header toolbar,
 * not in Filament's own indicator row, which pushed the records down when it
 * appeared.
 */
beforeEach(function (): void {
    $this->shipments = Shipment::factory()->validated()->count(3)->create();

    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('shows the selection in the toolbar without moving the records', function (): void {
    $page = visit('/shipments');

    $firstRowTop = fn (): float => $page->script("document.querySelector('.fi-ta-row').getBoundingClientRect().top");
    $summaryIsVisible = fn (): bool => $page->script("document.querySelector('.pb-ta-selection-summary').offsetParent !== null");

    $before = $firstRowTop();
    expect($summaryIsVisible())->toBeFalse();

    $page->click('.fi-ta-record-checkbox[value="'.$this->shipments->first()->id.'"]');

    $page->assertSeeIn('.fi-ta-header-toolbar', '1 record selected')
        ->assertSeeIn('.fi-ta-header-toolbar', 'Select all 3');

    expect($firstRowTop())->toBe($before)
        ->and($page->script("getComputedStyle(document.querySelector('.fi-ta-selection-indicator')).display"))->toBe('none');

    $page->click('.pb-ta-selection-summary button:has-text("Select all 3")');
    $page->assertSeeIn('.fi-ta-header-toolbar', '3 records selected');

    $page->click('.pb-ta-selection-summary button:has-text("Deselect all")');
    $page->wait(0.5);

    expect($summaryIsVisible())->toBeFalse()
        ->and($firstRowTop())->toBe($before);

    $page->assertNoJavaScriptErrors();
});
