<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Services\Carriers\FakeCarrierAdapter;
use Carbon\CarbonImmutable;
use Smalot\PdfParser\Parser;

/**
 * What a fake carrier prints: a sample label carrying the order's address and
 * the fake tracking number in the format the workstation asked for, the
 * customs paperwork its real carrier would return, and nothing a carrier
 * scanner could read.
 */
function sampleShipRequest(string $country = 'US', string $labelFormat = 'pdf', ?int $labelDpi = null, string $street = '742 Evergreen Terrace'): ShipRequest
{
    $international = $country !== 'US';

    return new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Dock',
            lastName: 'Manager',
            streetAddress: '100 Warehouse Way',
            city: 'Woodinville',
            stateOrProvince: 'WA',
            postalCode: '98072',
            company: 'Sample Fulfillment',
        ),
        toAddress: new AddressData(
            firstName: 'Marge',
            lastName: 'Simpson',
            streetAddress: $street,
            city: $international ? 'Toronto' : 'Springfield',
            stateOrProvince: $international ? 'ON' : 'OR',
            postalCode: $international ? 'M5H 2N2' : '97477',
            country: $country,
        ),
        packageData: new PackageData(weight: 2.5, length: 10, width: 8, height: 4),
        selectedRate: new RateResponse(carrier: 'USPS', serviceCode: 'PRIORITY_MAIL', serviceName: 'Priority Mail', price: 12.75),
        customsItems: [
            new CustomsItem(description: 'Blue Hair Rollers', quantity: 2, unitValue: 9.5, weight: 0.4, hsTariffNumber: '9615110000', countryOfOrigin: 'US'),
        ],
        labelFormat: $labelFormat,
        labelDpi: $labelDpi,
        shipDate: CarbonImmutable::parse('2026-10-14'),
        references: ['ORDER-1001'],
    );
}

/**
 * @return list<string> The text of each page of a base64 PDF
 */
function samplePdfPages(?string $base64): array
{
    $bytes = base64_decode((string) $base64, true);

    expect($bytes)->toStartWith('%PDF-');

    return array_map(
        fn ($page): string => $page->getText(),
        (new Parser)->parseContent($bytes)->getPages(),
    );
}

function sampleShipment(string $carrier, ShipRequest $request): ShipResponse
{
    return (new FakeCarrierAdapter($carrier))->createShipment($request);
}

it('prints a PDF label with the order\'s address, the tracking number and a SAMPLE marker', function (): void {
    $response = sampleShipment('USPS', sampleShipRequest());
    $pages = samplePdfPages($response->labelData);

    expect($response->labelFormat)->toBe('pdf')
        ->and($response->labelDpi)->toBeNull()
        ->and($pages)->toHaveCount(1)
        ->and($pages[0])->toContain('MARGE SIMPSON')
        ->toContain('742 EVERGREEN TERRACE')
        ->toContain('SPRINGFIELD, OR 97477')
        ->toContain('PRIORITY MAIL')
        ->toContain('ORDER-1001')
        ->toContain('SAMPLE - NOT VALID POSTAGE')
        ->and(str_replace([' ', "\t"], '', $pages[0]))->toContain($response->trackingNumber);
});

it('prints a ZPL label sized for the printer\'s density when the workstation asks for ZPL', function (int $dpi, int $width, int $length): void {
    $response = sampleShipment('UPS', sampleShipRequest(labelFormat: 'zpl', labelDpi: $dpi));
    $zpl = base64_decode($response->labelData, true);

    expect($response->labelFormat)->toBe('zpl')
        ->and($response->labelDpi)->toBe($dpi)
        ->and($zpl)->toStartWith('^XA')
        ->and(trim($zpl))->toEndWith('^XZ')
        ->and($zpl)->not->toContain('%PDF')
        ->toContain("^PW{$width}")
        ->toContain("^LL{$length}")
        ->toContain('^FDMARGE SIMPSON^FS')
        ->toContain('^FD742 EVERGREEN TERRACE^FS')
        ->toContain('^FDSAMPLE - NOT VALID POSTAGE - DO NOT SHIP^FS')
        ->and(str_replace(' ', '', $zpl))->toContain($response->trackingNumber);
})->with([
    '203 dpi' => [203, 812, 1218],
    '300 dpi' => [300, 1200, 1800],
]);

it('prints ZPL at 203 dpi when the workstation names a density printers do not run at', function (): void {
    $response = sampleShipment('USPS', sampleShipRequest(labelFormat: 'zpl', labelDpi: 600));

    expect($response->labelDpi)->toBe(203)
        ->and(base64_decode($response->labelData, true))->toContain('^PW812');
});

it('escapes ZPL control characters in an address', function (): void {
    $zpl = base64_decode(sampleShipment('USPS', sampleShipRequest(labelFormat: 'zpl', street: '1 Caret^Tilde~ Rd'))->labelData, true);

    expect($zpl)->toContain('^FD1 CARET\5ETILDE\7E RD^FS')
        ->and($zpl)->not->toContain('CARET^TILDE');
});

it('carries no barcode a carrier scanner could read', function (string $carrier): void {
    $zpl = base64_decode(sampleShipment($carrier, sampleShipRequest('CA', 'zpl'))->labelData, true);
    $pdf = base64_decode(sampleShipment($carrier, sampleShipRequest('CA'))->labelData, true);

    // Every ZPL barcode command starts ^B; the PDF has no images to hide one in.
    expect($zpl)->not->toMatch('/\^B[0-9A-Z]/')
        ->and($pdf)->not->toContain('/XObject')
        ->and($pdf)->not->toContain('/Image');
})->with(['USPS', 'UPS', 'FedEx']);

it('folds a USPS customs declaration into the label, as USPS does', function (): void {
    $response = sampleShipment('USPS', sampleShipRequest('CA'));
    $pages = samplePdfPages($response->labelData);
    $zpl = base64_decode(sampleShipment('USPS', sampleShipRequest('CA', 'zpl'))->labelData, true);

    expect($response->customsFormData)->toBeNull()
        ->and($pages)->toHaveCount(2)
        ->and($pages[0])->toContain('TORONTO, ON M5H 2N2')
        ->and($pages[1])->toContain('CUSTOMS DECLARATION')
        ->toContain('Blue Hair Rollers')
        ->toContain('NOT A CUSTOMS DECLARATION')
        ->toContain('SAMPLE')
        ->and(substr_count($zpl, '^XA'))->toBe(2);
});

it('returns a UPS commercial invoice beside the label for the document printer', function (): void {
    $response = sampleShipment('UPS', sampleShipRequest('CA'));
    $invoice = samplePdfPages($response->customsFormData);

    expect(samplePdfPages($response->labelData))->toHaveCount(1)
        ->and($response->customsFormFormat)->toBe('pdf')
        ->and($invoice)->toHaveCount(1)
        ->and($invoice[0])->toContain('COMMERCIAL INVOICE')
        ->toContain('Blue Hair Rollers')
        ->toContain('9615110000')
        ->toContain('MARGE SIMPSON')
        ->toContain($response->trackingNumber)
        ->toContain('not a customs declaration')
        ->toContain('SAMPLE - DEMO ONLY');
});

it('returns no customs document from FedEx, which never requests one', function (): void {
    $response = sampleShipment('FedEx', sampleShipRequest('CA'));

    expect($response->customsFormData)->toBeNull()
        ->and(samplePdfPages($response->labelData))->toHaveCount(1);
});

it('prints no customs paperwork for a domestic label', function (string $carrier): void {
    $response = sampleShipment($carrier, sampleShipRequest());

    expect($response->customsFormData)->toBeNull()
        ->and(samplePdfPages($response->labelData))->toHaveCount(1);
})->with(['USPS', 'UPS']);
