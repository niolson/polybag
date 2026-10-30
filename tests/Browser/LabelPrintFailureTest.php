<?php

use App\Enums\Role;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;

/**
 * A label that fails to print must say so where the operator is looking: the banner
 * is at the top of the page, so the failure also raises a toast that stays put.
 */
it('raises a persistent toast when a label fails to print', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));
    $shipment = Shipment::factory()->create();

    $page = visit('/shipments/'.$shipment->id);
    $page->script("localStorage.setItem('imageLabelPrinter', 'Zebra 4x6');");

    // Let the page's own QZ Tray connection attempt settle, then stand in for a
    // printer that is switched off.
    $page->wait(2);
    $page->script("
        window.qz = window.qz || {};
        qz.websocket = { isActive: () => true };
        // As a QZ Config holds it: setPrinter() wraps a name as { name }.
        qz.configs = { create: (printer) => ({ getPrinter: () => ({ name: printer }) }) };
        qz.print = async () => { throw new Error('Zebra 4x6 is offline'); };
        true;
    ");

    $page->script("Livewire.dispatch('print-label', { label: 'JVBERg==', format: 'pdf', redirectTo: '/' })");
    $page->wait(1);

    $page->assertSee('Label did not print')
        ->assertSee('Zebra 4x6 is offline')
        ->assertNoJavaScriptErrors();

    // Still there after the default toast lifetime, and the redirect was not followed.
    $page->wait(7);
    $page->assertSee('Label did not print');
    expect($page->script('window.location.pathname'))->toBe('/shipments/'.$shipment->id);
});

it('gives up on a printer that never answers and says so', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));
    $shipment = Shipment::factory()->create();

    $page = visit('/shipments/'.$shipment->id);
    $page->script("localStorage.setItem('imageLabelPrinter', 'Zebra 4x6');");

    // A disconnected printer can leave qz.print pending forever: no success, no error.
    $page->wait(2);
    $page->script('
        window.qz = window.qz || {};
        qz.websocket = { isActive: () => true };
        // As a QZ Config holds it: setPrinter() wraps a name as { name }.
        qz.configs = { create: (printer) => ({ getPrinter: () => ({ name: printer }) }) };
        qz.print = () => new Promise(() => {});
        true;
    ');

    $page->script("Livewire.dispatch('print-label', { label: 'JVBERg==', format: 'pdf', redirectTo: '/' })");
    $page->wait(17);

    $page->assertSee('Label did not print')
        ->assertSee('did not respond within 15 seconds')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.getElementById('qz-status-text').textContent"))->not->toBe('Printing label...')
        ->and($page->script('window.location.pathname'))->toBe('/shipments/'.$shipment->id);
});

/**
 * Stand in for a QZ Tray that is not yet connected and whose printer reports
 * status, the way a Zebra driver does. `window.reportStatus(text, severity)`
 * pushes a status event, as switching the printer off or on does.
 */
function visitWithReportingPrinter(Shipment $shipment, string $initialStatus, string $initialSeverity): mixed
{
    $page = visit('/shipments/'.$shipment->id);
    $page->script("localStorage.setItem('imageLabelPrinter', 'Zebra ZP 450');");

    $page->wait(2);
    $page->script("
        window.printCalls = 0;
        window.qz = window.qz || {};
        let active = false;
        let callbacks = () => {};
        window.reportPrinter = (printerName, statusText, severity) => callbacks({
            eventType: 'PRINTER', type: 'ACTION', printerName, statusText, severity,
        });
        window.reportStatus = (statusText, severity) => window.reportPrinter('Zebra ZP 450', statusText, severity);
        qz.security = { setCertificatePromise() {}, setSignatureAlgorithm() {}, setSignaturePromise() {} };
        qz.websocket = { isActive: () => active, connect: async () => { active = true; } };
        // As a QZ Config holds it: setPrinter() wraps a name as { name }.
        qz.configs = { create: (printer) => ({ getPrinter: () => ({ name: printer }) }) };
        qz.printers = {
            setPrinterCallbacks: (calls) => { callbacks = calls; },
            startListening: async () => null,
            getStatus: async () => { window.reportStatus('{$initialStatus}', '{$initialSeverity}'); },
        };
        qz.print = () => { window.printCalls++; return new Promise(() => {}); };
        true;
    ");

    return $page;
}

it('refuses to print to a printer the OS reports offline, without waiting', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));
    $shipment = Shipment::factory()->create();

    $page = visitWithReportingPrinter($shipment, 'OFFLINE', 'FATAL');

    $page->script("Livewire.dispatch('print-label', { label: 'JVBERg==', format: 'pdf', redirectTo: '/' })");
    $page->wait(1);

    $page->assertSee('Label did not print')
        ->assertSee('Zebra ZP 450 is offline')
        ->assertNoJavaScriptErrors();

    expect($page->script('window.printCalls'))->toBe(0);

    // Switching it back on says so.
    $page->script("window.reportStatus('OK', 'INFO')");
    $page->wait(1);
    expect($page->script("document.getElementById('qz-status-text').textContent"))->toContain('Zebra ZP 450 is back online');
});

it('fails a waiting print the moment its printer goes offline', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));
    $shipment = Shipment::factory()->create();

    $page = visitWithReportingPrinter($shipment, 'OK', 'INFO');

    $page->script("Livewire.dispatch('print-label', { label: 'JVBERg==', format: 'pdf', redirectTo: '/' })");
    $page->wait(1);
    expect($page->script('window.printCalls'))->toBe(1);

    $page->script("window.reportStatus('OFFLINE', 'FATAL')");
    $page->wait(1);

    $page->assertSee('Label did not print')
        ->assertSee('Zebra ZP 450 is offline')
        ->assertSee('do not print it again')
        ->assertNoJavaScriptErrors();

    expect($page->script('window.location.pathname'))->toBe('/shipments/'.$shipment->id);
});

it('warns as soon as a printer goes offline, before anything is printed', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));
    $shipment = Shipment::factory()->create();

    $page = visitWithReportingPrinter($shipment, 'OK', 'INFO');

    // A print that goes through connects the page to QZ and starts listening.
    $page->script('qz.print = async () => {}; true;');
    $page->script("Livewire.dispatch('print-label', { label: 'JVBERg==', format: 'pdf' })");
    $page->wait(1);

    expect($page->script("window.QzPrinterStatus.problem('Zebra ZP 450')"))->toBeNull();

    $page->script("window.reportStatus('OFFLINE', 'FATAL')");
    $page->wait(1);

    expect($page->script("document.getElementById('qz-status-text').textContent"))->toContain('Zebra ZP 450 is offline')
        ->and($page->script("window.QzPrinterStatus.problem('Zebra ZP 450')"))->toBe('Zebra ZP 450 is offline');

    $page->assertNoJavaScriptErrors();
});

it('does not auto ship from Pack while the label printer is offline', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer, 'auto_ship_enabled' => true]));
    $shipment = Shipment::factory()->create();

    $page = visit('/pack/'.$shipment->id);
    $page->script("localStorage.setItem('imageLabelPrinter', 'Zebra ZP 450');");
    $page->wait(2);

    $page->script("
        window.shipCalls = 0;
        window.QzPrinterStatus.problem = (printer) => printer === 'Zebra ZP 450' ? 'Zebra ZP 450 is offline' : null;
        const pack = Alpine.\$data(document.querySelector('[x-data*=\"autoShipEnabled\"]'));
        pack.isReadyToShip = () => true;
        pack.labelFormat = 'pdf';
        pack.\$wire.ship = async () => { window.shipCalls++; };
        pack.shipPackage();
        true;
    ");
    $page->wait(1);

    $page->assertSee('Label printer is offline')
        ->assertSee('no label was bought')
        ->assertNoJavaScriptErrors();

    expect($page->script('window.shipCalls'))->toBe(0);
});

it('names printers that go offline together in one message', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));
    $shipment = Shipment::factory()->create();

    $page = visitWithReportingPrinter($shipment, 'OK', 'INFO');
    $page->script('qz.print = async () => {}; true;');
    $page->script("Livewire.dispatch('print-label', { label: 'JVBERg==', format: 'pdf' })");
    $page->wait(1);

    // An image queue and a raw queue on the same Zebra, unplugged at once.
    $page->script("
        window.reportStatus('OFFLINE', 'FATAL');
        window.reportPrinter('Raw Printing', 'OFFLINE', 'FATAL');
        true;
    ");
    $page->wait(1);

    expect($page->script("document.getElementById('qz-status-text').textContent"))
        ->toBe('Zebra ZP 450 and Raw Printing are offline. Nothing will print until they are back.');

    $page->script("
        window.reportStatus('OK', 'INFO');
        window.reportPrinter('Raw Printing', 'OK', 'INFO');
        true;
    ");
    $page->wait(1);

    expect($page->script("document.getElementById('qz-status-text').textContent"))
        ->toBe('Zebra ZP 450 and Raw Printing are back online. Anything that did not print can be reprinted now.');
});

it('keeps the Ship page told whether its label printer is offline', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));
    // No shipping method: the page renders without quoting any carrier.
    $package = Package::factory()->for(Shipment::factory()->create(['shipping_method_id' => null]))->create();

    $page = visit('/ship/'.$package->id);
    $page->script("localStorage.setItem('imageLabelPrinter', 'Zebra ZP 450'); localStorage.setItem('labelFormat', 'pdf');");
    $page->wait(2);

    $wire = 'Alpine.$data(document.querySelector(\'[x-data*="_scanBuffer"]\')).$wire';

    $page->script("
        window.QzPrinterStatus.problem = (printer) => printer === 'Zebra ZP 450' ? 'Zebra ZP 450 is offline' : null;
        document.dispatchEvent(new CustomEvent('qz-printer-status'));
        true;
    ");

    expect($page->script("{$wire}.labelPrinterProblem"))->toBe('Zebra ZP 450 is offline');

    $page->script("
        window.QzPrinterStatus.problem = () => null;
        document.dispatchEvent(new CustomEvent('qz-printer-status'));
        true;
    ");

    expect($page->script("{$wire}.labelPrinterProblem"))->toBeNull();
    $page->assertNoJavaScriptErrors();
});

it('stays blocked while an offline printer also reports a lesser status, until a snapshot without offline', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));
    $shipment = Shipment::factory()->create();

    $page = visitWithReportingPrinter($shipment, 'OK', 'INFO');
    $page->script('qz.print = async () => {}; true;');
    $page->script("Livewire.dispatch('print-label', { label: 'JVBERg==', format: 'pdf' })");
    $page->wait(1);

    // One change, sent by QZ as one event per status.
    $page->script("
        window.reportStatus('OFFLINE', 'FATAL');
        window.reportStatus('TONER_LOW', 'WARN');
        true;
    ");
    $page->wait(1);

    expect($page->script("window.QzPrinterStatus.problem('Zebra ZP 450')"))->toBe('Zebra ZP 450 is offline');

    // Back on, still low: the next snapshot has no OFFLINE in it.
    $page->script("window.reportStatus('TONER_LOW', 'WARN')");
    $page->wait(1);

    expect($page->script("window.QzPrinterStatus.problem('Zebra ZP 450')"))->toBeNull()
        ->and($page->script("document.getElementById('qz-status-text').textContent"))->toContain('Zebra ZP 450 is back online');
});
