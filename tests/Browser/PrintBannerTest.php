<?php

use App\Enums\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Services\PackSlips\PackSlipReceipts;

/**
 * The QZ Tray status banner is drawn by JavaScript, not by Livewire, so only a real
 * browser shows whether a Livewire re-render wipes it.
 */
it('keeps the print banner and its Mark as printed button through the refresh after pack slips are recorded', function (): void {
    $user = User::factory()->create(['role' => Role::User]);
    $this->actingAs($user);
    $shipment = Shipment::factory()->create();

    $page = visit('/shipments/'.$shipment->id);
    $page->assertSee('Not printed');

    // Let the page's own QZ Tray connection attempt settle, then put the banner in
    // the state a print run leaves it in.
    $page->wait(2);
    $page->script("
        const banner = document.getElementById('qz-status');
        banner.classList.remove('hidden');
        banner.classList.add('flex');
        document.getElementById('qz-status-text').textContent = '1 pack slip sent but not recorded as printed.';
        const button = document.getElementById('qz-status-button');
        button.textContent = 'Mark as printed';
        button.classList.remove('hidden');
    ");

    // Something recorded the print, so the re-render has new content to draw.
    $receipts = app(PackSlipReceipts::class);
    $receipts->redeem($receipts->issue([$shipment->id], $user));

    $page->script("Livewire.dispatch('pack-slips-printed')");

    $page->assertSee('by '.$user->name)
        ->assertNoJavaScriptErrors();

    expect($page->script("document.getElementById('qz-status').classList.contains('hidden')"))->toBeFalse()
        ->and($page->script("document.getElementById('qz-status-text').textContent"))->toBe('1 pack slip sent but not recorded as printed.')
        ->and($page->script("document.getElementById('qz-status-button').classList.contains('hidden')"))->toBeFalse();
});
