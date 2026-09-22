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

it('sends the HID POS zero feature report through WebHID', function (): void {
    $page = visit('/device-settings');

    $report = $page->script(<<<'JS'
        (async () => {
            localStorage.setItem('scaleVendorId', '0xEB8');
            localStorage.setItem('scaleProductId', '0xF000');
            ScaleUtils.backend = 'webhid';
            ScaleUtils._lastReading = { weight: 0.05, isStable: true };
            ScaleUtils._webHidDevice = {
                sendFeatureReport(reportId, data) {
                    return Promise.resolve(window.sentScaleReport = [reportId, ...data]);
                },
            };

            await ScaleUtils.zero();

            return window.sentScaleReport;
        })()
    JS);

    expect($report)->toBe([2, 2]);
});

it('sends the narrowly-scoped PS60 zero command through QZ Tray', function (): void {
    $page = visit('/device-settings');

    $report = $page->script(<<<'JS'
        (async () => {
            localStorage.setItem('scaleVendorId', '0x0EB8');
            localStorage.setItem('scaleProductId', '0xF000');
            ScaleUtils.backend = 'qztray';
            ScaleUtils._lastReading = { weight: 0.05, isStable: true };
            window.qz = {
                hid: {
                    sendFeatureReport: (deviceInfo) => Promise.resolve(window.sentScaleReport = deviceInfo),
                },
            };

            await ScaleUtils.zero();

            return window.sentScaleReport;
        })()
    JS);

    expect($report)->toBe([
        'vendorId' => '0x0EB8',
        'productId' => '0xF000',
        'reportId' => '0x02',
        'data' => '02',
        'type' => 'HEX',
    ]);
});

it('refuses to zero the scale while a weight is still on the platform', function (): void {
    $page = visit('/device-settings');

    $error = $page->script(<<<'JS'
        (async () => {
            localStorage.setItem('scaleVendorId', '0x0EB8');
            localStorage.setItem('scaleProductId', '0xF000');
            ScaleUtils.backend = 'webhid';
            ScaleUtils._lastReading = { weight: 2.5, isStable: true };
            ScaleUtils._webHidDevice = {
                sendFeatureReport: () => Promise.resolve(window.sentScaleReport = true),
            };

            try {
                await ScaleUtils.zero();
                return null;
            } catch (error) {
                return error.message;
            }
        })()
    JS);

    expect($error)->toBe('Clear the platform before zeroing the scale.')
        ->and($page->script('window.sentScaleReport'))->toBeNull();
});

it('runs the hardware zero command when its barcode is scanned on the pack page', function (): void {
    $page = visit('/pack');

    $result = $page->script(<<<'JS'
        (async () => {
            const packElement = document.querySelector('[x-data*="scaleConnected"]');
            const pack = Alpine.$data(packElement);

            pack.scaleConnected = true;
            ScaleUtils.zero = () => Promise.resolve(window.scaleZeroed = true);
            await pack.executeCommand('4');

            return [window.scaleZeroed, pack.weight];
        })()
    JS);

    expect($result)->toBe([true, '0.00']);
});
