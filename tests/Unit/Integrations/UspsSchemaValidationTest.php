<?php

use PHPUnit\Framework\AssertionFailedError;

/**
 * Guards assertMatchesUspsSchema() and the hand-written schema behind it.
 *
 * uspsLabel.json is our own description of the bodies UspsAdapter builds, not
 * a vendored spec, so nothing upstream keeps it honest. These tests pin down
 * that it still resolves, still rejects the shapes it was written to reject —
 * a nameless address, a numeric ZIP, a missing customs form — and still
 * accepts the shapes the adapter legitimately produces.
 */
function validUspsDomesticAddress(array $overrides = []): array
{
    return array_replace([
        'streetAddress' => '456 Main St',
        'city' => 'Los Angeles',
        'state' => 'CA',
        'ZIPCode' => '90210',
        'firstName' => 'John',
        'lastName' => 'Doe',
    ], $overrides);
}

function validUspsPackageDescription(array $overrides = []): array
{
    return array_replace_recursive([
        'mailClass' => 'USPS_GROUND_ADVANTAGE',
        'rateIndicator' => 'SP',
        'weightUOM' => 'lb',
        'weight' => 2.5,
        'dimensionsUOM' => 'in',
        'length' => 10,
        'height' => 6,
        'width' => 8,
        'processingCategory' => 'MACHINABLE',
        'mailingDate' => '2026-09-11',
        'extraServices' => [922, 931],
        'destinationEntryFacilityType' => 'NONE',
        'customerReference' => [
            ['referenceNumber' => 'ORD-10042', 'printReferenceNumber' => true],
        ],
        'packageOptions' => [
            'packageValue' => 750.00,
            'physicalSignatureRequired' => false,
        ],
    ], $overrides);
}

function validUspsCustomsForm(array $overrides = []): array
{
    return array_replace_recursive([
        'AESITN' => 'NO EEI 30.37(a)',
        'customsContentType' => 'MERCHANDISE',
        'invoiceNumber' => 'ORD-10042',
        'contents' => [[
            'itemDescription' => 'Blue Widget',
            'itemQuantity' => 2,
            'itemTotalValue' => 39.98,
            'weightUOM' => 'lb',
            'itemTotalWeight' => 1.0,
            'countryofOrigin' => 'US',
            'HSTariffNumber' => '950300',
        ]],
    ], $overrides);
}

function validUspsLabelBody(array $overrides = []): array
{
    return array_replace_recursive([
        'toAddress' => validUspsDomesticAddress(),
        'fromAddress' => validUspsDomesticAddress([
            'streetAddress' => '123 Warehouse St',
            'city' => 'Seattle',
            'state' => 'WA',
            'ZIPCode' => '98072',
            'firstName' => 'Shipping',
            'lastName' => 'Center',
        ]),
        'packageDescription' => validUspsPackageDescription(),
        'imageInfo' => ['receiptOption' => 'NONE', 'imageType' => 'ZPL203DPI'],
    ], $overrides);
}

function validUspsInternationalLabelBody(array $overrides = []): array
{
    return array_replace_recursive([
        'toAddress' => [
            'streetAddress' => '4 Chome-2-8 Shibakoen',
            'secondaryAddress' => 'Floor 3',
            'city' => 'Minato City',
            'province' => 'TOKYO',
            'postalCode' => '105-0011',
            'country' => 'JP',
            'countryISOAlpha2Code' => 'JP',
            'firstName' => 'Kenji',
            'lastName' => 'Sato',
        ],
        'fromAddress' => validUspsDomesticAddress([
            'streetAddress' => '123 Warehouse St',
            'city' => 'Seattle',
            'state' => 'WA',
            'ZIPCode' => '98072',
            'firstName' => 'Shipping',
            'lastName' => 'Center',
        ]),
        'packageDescription' => validUspsPackageDescription([
            'mailClass' => 'PRIORITY_MAIL_INTERNATIONAL',
            'destinationEntryFacilityType' => 'INTERNATIONAL_SERVICE_CENTER',
        ]),
        'customsForm' => validUspsCustomsForm(),
        'imageInfo' => ['receiptOption' => 'NONE'],
    ], $overrides);
}

it('accepts a well-formed domestic label body', function (): void {
    assertMatchesUspsSchema(validUspsLabelBody(), 'LabelRequest');
});

it('accepts a domestic label body with only the fields the adapter always sends', function (): void {
    $body = validUspsLabelBody();
    unset(
        $body['packageDescription']['customerReference'],
        $body['packageDescription']['packageOptions'],
        $body['imageInfo']['imageType'],
    );
    $body['packageDescription']['extraServices'] = [];

    assertMatchesUspsSchema($body, 'LabelRequest');
});

it('accepts a firm in place of a first and last name', function (): void {
    $body = validUspsLabelBody();
    unset($body['toAddress']['firstName'], $body['toAddress']['lastName']);
    $body['toAddress']['firm'] = 'Acme Corp';

    assertMatchesUspsSchema($body, 'LabelRequest');
});

it('accepts a domestic label carrying a customs form for a military destination', function (): void {
    assertMatchesUspsSchema(validUspsLabelBody([
        'toAddress' => ['streetAddress' => 'PSC 402 BOX 301', 'city' => 'FPO', 'state' => 'AE', 'ZIPCode' => '09532'],
        'customsForm' => validUspsCustomsForm(),
    ]), 'LabelRequest');
});

it('accepts a well-formed international label body', function (): void {
    assertMatchesUspsSchema(validUspsInternationalLabelBody(), 'InternationalLabelRequest');
});

it('accepts an international label body without the optional address and customs fields', function (): void {
    $body = validUspsInternationalLabelBody();
    unset(
        $body['toAddress']['secondaryAddress'],
        $body['toAddress']['province'],
        $body['toAddress']['postalCode'],
        $body['customsForm']['invoiceNumber'],
        $body['customsForm']['contents'][0]['HSTariffNumber'],
    );

    assertMatchesUspsSchema($body, 'InternationalLabelRequest');
});

it('rejects a domestic label body missing a required top-level property', function (string $property): void {
    $body = validUspsLabelBody();
    unset($body[$property]);

    expect(fn () => assertMatchesUspsSchema($body, 'LabelRequest'))
        ->toThrow(AssertionFailedError::class, $property);
})->with(['toAddress', 'fromAddress', 'packageDescription', 'imageInfo']);

it('rejects an international label body without a customs form', function (): void {
    $body = validUspsInternationalLabelBody();
    unset($body['customsForm']);

    expect(fn () => assertMatchesUspsSchema($body, 'InternationalLabelRequest'))
        ->toThrow(AssertionFailedError::class, 'customsForm');
});

it('rejects an address with no name at all', function (): void {
    // The class of failure that reached production — USPS answered a label
    // with "[Path '/toAddress'] Instance failed to match all required
    // schemas". AddressData::fromShipment() builds a nameless address from a
    // shipment with no first name, last name or company, and the adapter
    // sends it as-is.
    $body = validUspsLabelBody();
    unset($body['toAddress']['firstName'], $body['toAddress']['lastName']);

    expect(fn () => assertMatchesUspsSchema($body, 'LabelRequest'))
        ->toThrow(AssertionFailedError::class, 'toAddress');
});

it('rejects a lone first name with no last name or firm', function (): void {
    $body = validUspsLabelBody();
    unset($body['toAddress']['lastName']);

    expect(fn () => assertMatchesUspsSchema($body, 'LabelRequest'))
        ->toThrow(AssertionFailedError::class, 'toAddress');
});

it('rejects a numeric ZIP code', function (): void {
    expect(fn () => assertMatchesUspsSchema(validUspsLabelBody(['toAddress' => ['ZIPCode' => 90210]]), 'LabelRequest'))
        ->toThrow(AssertionFailedError::class, 'ZIPCode');
});

it('rejects a ZIP code that is not five digits', function (string $zip): void {
    expect(fn () => assertMatchesUspsSchema(validUspsLabelBody(['toAddress' => ['ZIPCode' => $zip]]), 'LabelRequest'))
        ->toThrow(AssertionFailedError::class, 'ZIPCode');
})->with(['9021', '90210-1234', 'ABCDE']);

it('rejects a renamed address key', function (): void {
    $body = validUspsLabelBody();
    $body['toAddress']['zipCode'] = $body['toAddress']['ZIPCode'];
    unset($body['toAddress']['ZIPCode']);

    expect(fn () => assertMatchesUspsSchema($body, 'LabelRequest'))
        ->toThrow(AssertionFailedError::class, 'zipCode is not defined');
});

it('rejects a weight sent as a string', function (): void {
    expect(fn () => assertMatchesUspsSchema(validUspsLabelBody(['packageDescription' => ['weight' => '2.5']]), 'LabelRequest'))
        ->toThrow(AssertionFailedError::class, 'weight');
});

it('rejects a non-integer extra service code', function (): void {
    expect(fn () => assertMatchesUspsSchema(validUspsLabelBody(['packageDescription' => ['extraServices' => ['922']]]), 'LabelRequest'))
        ->toThrow(AssertionFailedError::class, 'extraServices');
});

it('rejects an empty packageOptions object', function (): void {
    // The adapter omits packageOptions rather than sending it empty; an empty
    // PHP array would reach USPS as a JSON list, not an object.
    $body = validUspsLabelBody();
    $body['packageDescription']['packageOptions'] = [];

    expect(fn () => assertMatchesUspsSchema($body, 'LabelRequest'))
        ->toThrow(AssertionFailedError::class, 'packageOptions');
});

it('rejects a mailing date that is not Y-m-d', function (): void {
    expect(fn () => assertMatchesUspsSchema(validUspsLabelBody(['packageDescription' => ['mailingDate' => '09/11/2026']]), 'LabelRequest'))
        ->toThrow(AssertionFailedError::class, 'mailingDate');
});

it('rejects a customs item quantity sent as a string', function (): void {
    $body = validUspsInternationalLabelBody(['customsForm' => ['contents' => [['itemQuantity' => '2']]]]);

    expect(fn () => assertMatchesUspsSchema($body, 'InternationalLabelRequest'))
        ->toThrow(AssertionFailedError::class, 'itemQuantity');
});

it('rejects a customs item missing a required property', function (string $property): void {
    $body = validUspsInternationalLabelBody();
    unset($body['customsForm']['contents'][0][$property]);

    expect(fn () => assertMatchesUspsSchema($body, 'InternationalLabelRequest'))
        ->toThrow(AssertionFailedError::class, $property);
})->with(['itemDescription', 'itemQuantity', 'itemTotalValue', 'weightUOM', 'itemTotalWeight', 'countryofOrigin']);

it('rejects a customs form with no contents', function (): void {
    $body = validUspsInternationalLabelBody();
    $body['customsForm']['contents'] = [];

    expect(fn () => assertMatchesUspsSchema($body, 'InternationalLabelRequest'))
        ->toThrow(AssertionFailedError::class, 'contents');
});

it('rejects an image type other than the two ZPL densities', function (): void {
    expect(fn () => assertMatchesUspsSchema(validUspsLabelBody(['imageInfo' => ['imageType' => 'PDF']]), 'LabelRequest'))
        ->toThrow(AssertionFailedError::class, 'imageType');
});

it('fails loudly when the schema name is unknown', function (): void {
    expect(fn () => assertMatchesUspsSchema(validUspsLabelBody(), 'DoesNotExist'))
        ->toThrow(AssertionFailedError::class, 'not defined');
});
