<?php

use App\Enums\Role;
use App\Models\User;

/**
 * The printer settings live in the browser, so the resolver that decides which
 * printer a label goes to — and the one-time move off the single `labelPrinter`
 * key — can only be proved in one.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('moves a legacy ZPL workstation onto the raw label printer', function (): void {
    $page = visit('/device-settings');

    $page->script("
        localStorage.clear();
        localStorage.setItem('labelPrinter', 'ZDesigner ZD421');
        localStorage.setItem('labelFormat', 'zpl');
        localStorage.setItem('labelDpi', '300');
    ");

    $page->navigate('/device-settings');

    $page->assertNoJavaScriptErrors()
        ->assertSee('Raw ZPL Printer')
        ->assertSee('Document Printer');

    expect($page->script('localStorage.getItem("rawLabelPrinter")'))->toBe('ZDesigner ZD421')
        ->and($page->script('localStorage.getItem("imageLabelPrinter")'))->toBeNull()
        ->and($page->script('localStorage.getItem("labelPrinter")'))->toBeNull()
        ->and($page->script('PrinterSettings.labelFormat()'))->toBe('zpl')
        ->and($page->script('PrinterSettings.labelDpi()'))->toBe(300)
        ->and($page->script('PrinterSettings.labelPrinterFor("zpl")'))->toBe('ZDesigner ZD421')
        ->and($page->script('PrinterSettings.labelPrinterFor("pdf")'))->toBeNull();
});

it('moves a legacy PDF workstation onto the image label printer', function (): void {
    $page = visit('/device-settings');

    $page->script("
        localStorage.clear();
        localStorage.setItem('labelPrinter', 'Rollo');
        localStorage.setItem('reportPrinter', 'Office LaserJet');
    ");

    $page->navigate('/device-settings');

    expect($page->script('localStorage.getItem("imageLabelPrinter")'))->toBe('Rollo')
        ->and($page->script('localStorage.getItem("rawLabelPrinter")'))->toBeNull()
        ->and($page->script('PrinterSettings.labelFormat()'))->toBe('pdf')
        ->and($page->script('PrinterSettings.labelPrinterFor("png")'))->toBe('Rollo')
        ->and($page->script('PrinterSettings.documentPrinter()'))->toBe('Office LaserJet')
        ->and($page->script('PrinterSettings.hasDocumentPrinter()'))->toBeTrue();
});

it('buys in whichever format this workstation can still print', function (): void {
    $page = visit('/device-settings');

    // Preference says ZPL but only the image printer is left: buy PDF.
    $page->script("
        localStorage.clear();
        localStorage.setItem('imageLabelPrinter', 'Rollo');
        localStorage.setItem('labelFormat', 'zpl');
    ");

    expect($page->script('PrinterSettings.labelFormat()'))->toBe('pdf')
        ->and($page->script('PrinterSettings.hasLabelPrinter()'))->toBeTrue();

    // Preference says PDF but only the raw printer is set: buy ZPL.
    $page->script("
        localStorage.clear();
        localStorage.setItem('rawLabelPrinter', 'Generic / Text Only');
        localStorage.setItem('labelFormat', 'pdf');
    ");

    expect($page->script('PrinterSettings.labelFormat()'))->toBe('zpl');

    // Both set: the preference stands.
    $page->script("localStorage.setItem('imageLabelPrinter', 'Rollo');");

    expect($page->script('PrinterSettings.labelFormat()'))->toBe('pdf');

    // Neither set: nothing to route to, PDF is the harmless default.
    $page->script('localStorage.clear();');

    expect($page->script('PrinterSettings.labelFormat()'))->toBe('pdf')
        ->and($page->script('PrinterSettings.hasLabelPrinter()'))->toBeFalse();
});

it('lets a stale label printer be cleared by saving with none selected', function (): void {
    $page = visit('/device-settings');

    // A printer QZ Tray no longer lists: the select cannot show it, so the only
    // thing the operator can do is save with nothing chosen.
    $page->script("
        localStorage.clear();
        localStorage.setItem('rawLabelPrinter', 'Renamed Queue');
        localStorage.setItem('labelFormat', 'zpl');
    ");

    $page->navigate('/device-settings');

    $page->click('#save-settings')
        ->assertSee('Settings saved!')
        ->assertSee('No Label Printer');

    expect($page->script('localStorage.getItem("rawLabelPrinter")'))->toBeNull()
        ->and($page->script('PrinterSettings.hasLabelPrinter()'))->toBeFalse();
});
