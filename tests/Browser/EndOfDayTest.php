<?php

use App\Enums\Role;
use App\Models\Carrier;
use App\Models\Manifest;
use App\Models\Package;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FakeCarrierAdapter;
use App\Services\ShipDateService;

/**
 * End of Day in a browser: the day's USPS labels go on a manifest that is
 * sent to the document printer, then the shipping day is ended
 * (`release-polish` pass 5). Fake carriers make the sample SCAN form, so
 * nothing reaches USPS.
 */
beforeEach(function (): void {
    // A Wednesday morning: before the USPS cutoff and not a weekend.
    $this->travelTo(now()->next('Wednesday')->setTime(10, 0));

    app(CarrierRegistry::class)->registerInstance(Carrier::USPS, new FakeCarrierAdapter(Carrier::USPS));

    $this->carrier = Carrier::factory()->usps()->create(['active' => true]);
    $this->shipDate = app(ShipDateService::class)->getShipDate($this->carrier, null);
    $this->packages = Package::factory()->usps()->count(2)->create([
        'carrier_account_id' => null,
        'shipped_at' => now(),
        'ship_date' => $this->shipDate->toDateString(),
    ]);

    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('manifests the day\'s labels, prints the form and ends the shipping day', function (): void {
    $page = visit('/end-of-day');
    $page->assertSee('2 unmanifested');

    // Stand in for QZ Tray once the page's own connection attempt has settled.
    $page->script("localStorage.setItem('reportPrinter', 'Office Laser');");
    $page->wait(2);
    $page->script('
        window.printedTo = [];
        window.qz = window.qz || {};
        qz.websocket = { isActive: () => true };
        // As a QZ Config holds it: setPrinter() wraps a name as { name }.
        qz.configs = { create: (printer) => ({ getPrinter: () => ({ name: printer }) }) };
        qz.print = async (config) => { window.printedTo.push(config.getPrinter().name); };
        true;
    ');

    $page->click('button:has-text("Generate & Print")');
    $page->wait(2);

    $manifest = Manifest::sole();
    $page->assertSee('Manifest Created')
        ->assertSee($manifest->manifest_number)
        ->assertDontSee('unmanifested');

    expect($page->script('window.printedTo'))->toBe(['Office Laser'])
        ->and($manifest->package_count)->toBe(2)
        ->and($this->packages->every(fn (Package $package): bool => $package->fresh()->manifest_id === $manifest->id))->toBeTrue();

    $page->click('button:has-text("End Shipping Day")');
    $page->click('.fi-modal-window button:has-text("End Shipping Day")');
    $page->wait(2);

    $page->assertSee('Shipping Day Ended');
    expect(app(ShipDateService::class)->getShipDate($this->carrier, null)->isAfter($this->shipDate))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});
