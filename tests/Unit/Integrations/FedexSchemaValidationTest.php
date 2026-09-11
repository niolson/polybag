<?php

use PHPUnit\Framework\AssertionFailedError;

/**
 * Guards assertMatchesFedexSchema() and the hand-written schema behind it.
 *
 * fedexShip.json is our own description of the body FedexAdapter builds for
 * CreateShipment, not a vendored spec, so nothing upstream keeps it honest.
 * These tests pin down that it still resolves, still rejects the shapes it was
 * written to reject — a nameless contact, a string weight, a special service
 * without its detail, a SmartPost indicia with the wrong endorsement — and
 * still accepts the shapes the adapter legitimately produces.
 */
function validFedexParty(array $overrides = []): array
{
    return array_replace_recursive([
        'contact' => [
            'personName' => 'John Doe',
            'phoneNumber' => '5559876543',
        ],
        'address' => [
            'streetLines' => ['456 Main St'],
            'city' => 'Los Angeles',
            'stateOrProvinceCode' => 'CA',
            'postalCode' => '90210',
            'countryCode' => 'US',
        ],
    ], $overrides);
}

function validFedexLineItem(array $overrides = []): array
{
    return array_replace_recursive([
        'weight' => ['units' => 'LB', 'value' => 5.0],
        'dimensions' => ['length' => 12, 'width' => 10, 'height' => 8, 'units' => 'IN'],
        'customerReferences' => [
            ['customerReferenceType' => 'CUSTOMER_REFERENCE', 'value' => 'ORD-10042'],
        ],
        'packageSpecialServices' => [
            'specialServiceTypes' => ['SIGNATURE_OPTION', 'ALCOHOL'],
            'signatureOptionType' => 'ADULT',
            'alcoholDetail' => ['alcoholRecipientType' => 'CONSUMER'],
        ],
        'declaredValue' => ['amount' => 250.0, 'currency' => 'USD'],
    ], $overrides);
}

function validFedexCommodity(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Dictionaries',
        'description' => 'Dictionaries',
        'countryOfManufacture' => 'US',
        'quantity' => '2',
        'quantityUnits' => 'PCS',
        'numberOfPieces' => '2',
        'unitPrice' => ['amount' => '25.235', 'currency' => 'USD'],
        'customsValue' => ['amount' => '50.47', 'currency' => 'USD'],
        'weight' => ['units' => 'LB', 'value' => '0.8'],
        'harmonizedCode' => '490191',
    ], $overrides);
}

function validFedexCustomsClearanceDetail(array $overrides = []): array
{
    return array_replace_recursive([
        'commercialInvoice' => ['shipmentPurpose' => 'SOLD'],
        'dutiesPayment' => [
            'paymentType' => 'SENDER',
            'payor' => [
                'responsibleParty' => [
                    'address' => ['countryCode' => 'US'],
                    'accountNumber' => ['value' => '123456789'],
                ],
            ],
        ],
        'commodities' => [validFedexCommodity()],
    ], $overrides);
}

function validFedexShipBody(array $overrides = []): array
{
    return array_replace_recursive([
        'labelResponseOptions' => 'LABEL',
        'accountNumber' => ['value' => '123456789'],
        'requestedShipment' => [
            'shipper' => validFedexParty([
                'contact' => ['personName' => 'Shipping Center', 'companyName' => 'Test Company', 'phoneNumber' => '5551234567'],
                'address' => [
                    'streetLines' => ['123 Warehouse St', 'Dock 4'],
                    'city' => 'Seattle',
                    'stateOrProvinceCode' => 'WA',
                    'postalCode' => '98072',
                ],
            ]),
            'recipients' => [validFedexParty()],
            'shipDateStamp' => '2026-09-11',
            'pickupType' => 'USE_SCHEDULED_PICKUP',
            'serviceType' => 'FEDEX_GROUND',
            'packagingType' => 'YOUR_PACKAGING',
            'shippingChargesPayment' => [
                'paymentType' => 'SENDER',
                'payor' => ['responsibleParty' => ['accountNumber' => ['value' => '123456789']]],
            ],
            'labelSpecification' => [
                'labelFormatType' => 'COMMON2D',
                'imageType' => 'ZPLII',
                'labelStockType' => 'STOCK_4X6',
                'resolution' => 300,
            ],
            'requestedPackageLineItems' => [validFedexLineItem()],
        ],
    ], $overrides);
}

it('accepts a well-formed domestic ship body', function (): void {
    assertMatchesFedexSchema(validFedexShipBody(), 'CreateShipmentRequest');
});

it('accepts a ship body with only the fields the adapter always sends', function (): void {
    $body = validFedexShipBody();
    unset(
        $body['requestedShipment']['shipDateStamp'],
        $body['requestedShipment']['labelSpecification']['resolution'],
        $body['requestedShipment']['requestedPackageLineItems'][0]['customerReferences'],
        $body['requestedShipment']['requestedPackageLineItems'][0]['packageSpecialServices'],
        $body['requestedShipment']['requestedPackageLineItems'][0]['declaredValue'],
        $body['requestedShipment']['shipper']['contact']['companyName'],
    );
    $body['requestedShipment']['labelSpecification']['imageType'] = 'PDF';

    assertMatchesFedexSchema($body, 'CreateShipmentRequest');
});

it('accepts a company in place of a person name', function (): void {
    $body = validFedexShipBody();
    unset($body['requestedShipment']['recipients'][0]['contact']['personName']);
    $body['requestedShipment']['recipients'][0]['contact']['companyName'] = 'Acme Corp';

    assertMatchesFedexSchema($body, 'CreateShipmentRequest');
});

it('accepts a recipient with no phone number', function (): void {
    // The customs path: the adapter does not stand in the shipper's phone
    // when the recipient contact feeds a customs declaration.
    $body = validFedexShipBody();
    unset($body['requestedShipment']['recipients'][0]['contact']['phoneNumber']);

    assertMatchesFedexSchema($body, 'CreateShipmentRequest');
});

it('accepts a ship body with a customs clearance detail', function (): void {
    assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => [
            'recipients' => [validFedexParty([
                'address' => ['city' => 'Toronto', 'stateOrProvinceCode' => 'ON', 'postalCode' => 'M5V 2T6', 'countryCode' => 'CA'],
            ])],
            'serviceType' => 'FEDEX_INTERNATIONAL_PRIORITY',
            'customsClearanceDetail' => validFedexCustomsClearanceDetail(),
        ],
    ]), 'CreateShipmentRequest');
});

it('accepts a commodity without a harmonized code', function (): void {
    $detail = validFedexCustomsClearanceDetail();
    unset($detail['commodities'][0]['harmonizedCode']);

    assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['customsClearanceDetail' => $detail],
    ]), 'CreateShipmentRequest');
});

it('accepts a SmartPost detail for either weight band', function (array $detail): void {
    assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['serviceType' => 'SMART_POST', 'smartPostInfoDetail' => $detail],
    ]), 'CreateShipmentRequest');
})->with([
    'under a pound' => [['hubId' => '5983', 'indicia' => 'PRESORTED_STANDARD', 'ancillaryEndorsement' => 'ADDRESS_CORRECTION']],
    'a pound and up' => [['hubId' => '5983', 'indicia' => 'PARCEL_SELECT']],
]);

it('accepts a One Rate ship body with Saturday delivery', function (): void {
    assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => [
            'serviceType' => 'PRIORITY_OVERNIGHT',
            'packagingType' => 'FEDEX_SMALL_BOX',
            'shipmentSpecialServices' => ['specialServiceTypes' => ['FEDEX_ONE_RATE', 'SATURDAY_DELIVERY']],
        ],
    ]), 'CreateShipmentRequest');
});

it('accepts a battery declaration', function (): void {
    $body = validFedexShipBody();
    $body['requestedShipment']['requestedPackageLineItems'][0]['packageSpecialServices'] = [
        'specialServiceTypes' => ['BATTERY'],
        'batteryDetails' => [['batteryPackingType' => 'CONTAINED_IN_EQUIPMENT', 'batteryMaterialType' => 'LITHIUM_ION']],
    ];

    assertMatchesFedexSchema($body, 'CreateShipmentRequest');
});

it('rejects a ship body missing a required top-level property', function (string $property): void {
    $body = validFedexShipBody();
    unset($body[$property]);

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, $property);
})->with(['labelResponseOptions', 'accountNumber', 'requestedShipment']);

it('rejects a requested shipment missing a required property', function (string $property): void {
    $body = validFedexShipBody();
    unset($body['requestedShipment'][$property]);

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, $property);
})->with(['shipper', 'recipients', 'pickupType', 'serviceType', 'packagingType', 'shippingChargesPayment', 'labelSpecification', 'requestedPackageLineItems']);

it('rejects a null account number', function (): void {
    // resolveAccountNumber() returns null for an account with no account
    // number credential, and nothing in the adapter checks before sending.
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody(['accountNumber' => ['value' => null]]), 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'accountNumber.value');
});

it('rejects a contact with no name at all', function (): void {
    // AddressData::fromShipment() builds a nameless address from a shipment
    // with no first name, last name or company; buildContact() filters the
    // empty personName out and sends the contact with only a phone number.
    $body = validFedexShipBody();
    unset($body['requestedShipment']['recipients'][0]['contact']['personName']);

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'recipients[0].contact');
});

it('rejects an empty contact', function (): void {
    // An empty PHP array reaches FedEx as a JSON list, not an object.
    $body = validFedexShipBody();
    $body['requestedShipment']['recipients'][0]['contact'] = [];

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'recipients[0].contact');
});

it('rejects an address with no street lines', function (): void {
    $body = validFedexShipBody();
    $body['requestedShipment']['recipients'][0]['address']['streetLines'] = [];

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'streetLines');
});

it('rejects a street line longer than 35 characters', function (): void {
    $body = validFedexShipBody();
    $body['requestedShipment']['recipients'][0]['address']['streetLines'] = [str_repeat('x', 36)];

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'streetLines');
});

it('rejects a null state or postal code', function (string $property): void {
    // The adapter passes both through untouched, so an address without one
    // would send null. No test constructs such an address.
    $body = validFedexShipBody();
    $body['requestedShipment']['recipients'][0]['address'][$property] = null;

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, $property);
})->with(['stateOrProvinceCode', 'postalCode']);

it('rejects a lower-case country code', function (): void {
    $body = validFedexShipBody(['requestedShipment' => ['recipients' => [['address' => ['countryCode' => 'ca']]]]]);

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'countryCode');
});

it('rejects a renamed address key', function (): void {
    $body = validFedexShipBody();
    $address = &$body['requestedShipment']['recipients'][0]['address'];
    $address['postal_code'] = $address['postalCode'];
    unset($address['postalCode']);

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'postal_code is not defined');
});

it('rejects a packaging type outside our enum', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody(['requestedShipment' => ['packagingType' => 'FEDEX_CRATE']]), 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'packagingType');
});

it('rejects a ship date that is not Y-m-d', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody(['requestedShipment' => ['shipDateStamp' => '09/11/2026']]), 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'shipDateStamp');
});

it('rejects a second recipient', function (): void {
    $body = validFedexShipBody();
    $body['requestedShipment']['recipients'][] = validFedexParty();

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'recipients');
});

it('rejects a ZPL label without a resolution', function (): void {
    $body = validFedexShipBody();
    unset($body['requestedShipment']['labelSpecification']['resolution']);

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'labelSpecification');
});

it('rejects a PDF label carrying a resolution', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody(['requestedShipment' => ['labelSpecification' => ['imageType' => 'PDF']]]), 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'labelSpecification');
});

it('rejects a resolution other than 200 or 300', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody(['requestedShipment' => ['labelSpecification' => ['resolution' => 203]]]), 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'resolution');
});

it('rejects a package weight sent as a string', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['requestedPackageLineItems' => [['weight' => ['value' => '5.0']]]],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'weight.value');
});

it('rejects a fractional or zero dimension', function (int|float $length): void {
    // Dimensions are cast to int in the adapter, so a side under an inch
    // reaches FedEx as 0.
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['requestedPackageLineItems' => [['dimensions' => ['length' => $length]]]],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'dimensions.length');
})->with([0, 12.5]);

it('rejects a second package line item', function (): void {
    $body = validFedexShipBody();
    $body['requestedShipment']['requestedPackageLineItems'][] = validFedexLineItem();

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'requestedPackageLineItems');
});

it('rejects a customer reference longer than 40 characters', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['requestedPackageLineItems' => [['customerReferences' => [['value' => str_repeat('x', 41)]]]]],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'customerReferences[0].value');
});

it('rejects a fourth customer reference', function (): void {
    $body = validFedexShipBody();
    $reference = ['customerReferenceType' => 'CUSTOMER_REFERENCE', 'value' => 'REF'];
    $body['requestedShipment']['requestedPackageLineItems'][0]['customerReferences'] = [$reference, $reference, $reference, $reference];

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'customerReferences');
});

it('rejects a special service without its detail', function (string $type, string $detail): void {
    $body = validFedexShipBody();
    $body['requestedShipment']['requestedPackageLineItems'][0]['packageSpecialServices'] = [
        'specialServiceTypes' => [$type],
    ];

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, $detail);
})->with([
    ['SIGNATURE_OPTION', 'signatureOptionType'],
    ['ALCOHOL', 'alcoholDetail'],
    ['BATTERY', 'batteryDetails'],
]);

it('rejects a special service detail without its service type', function (): void {
    $body = validFedexShipBody();
    $body['requestedShipment']['requestedPackageLineItems'][0]['packageSpecialServices'] = [
        'specialServiceTypes' => ['ALCOHOL'],
        'alcoholDetail' => ['alcoholRecipientType' => 'CONSUMER'],
        'signatureOptionType' => 'ADULT',
    ];

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'packageSpecialServices');
});

it('rejects an empty special service type list', function (): void {
    $body = validFedexShipBody();
    $body['requestedShipment']['requestedPackageLineItems'][0]['packageSpecialServices'] = [
        'specialServiceTypes' => [],
    ];

    expect(fn () => assertMatchesFedexSchema($body, 'CreateShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'specialServiceTypes');
});

it('rejects a declared value sent as a string', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['requestedPackageLineItems' => [['declaredValue' => ['amount' => '250.00']]]],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'declaredValue.amount');
});

it('rejects a lightweight SmartPost detail without the endorsement', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['smartPostInfoDetail' => ['hubId' => '5983', 'indicia' => 'PRESORTED_STANDARD']],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'smartPostInfoDetail');
});

it('rejects a parcel select SmartPost detail carrying the endorsement', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['smartPostInfoDetail' => ['hubId' => '5983', 'indicia' => 'PARCEL_SELECT', 'ancillaryEndorsement' => 'ADDRESS_CORRECTION']],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'smartPostInfoDetail');
});

it('rejects a numeric hub ID', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['smartPostInfoDetail' => ['hubId' => 5983, 'indicia' => 'PARCEL_SELECT']],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'hubId');
});

it('rejects a customs detail with no commodities', function (): void {
    $detail = validFedexCustomsClearanceDetail();
    $detail['commodities'] = [];

    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['customsClearanceDetail' => $detail],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'commodities');
});

it('rejects a commodity missing a required property', function (string $property): void {
    $detail = validFedexCustomsClearanceDetail();
    unset($detail['commodities'][0][$property]);

    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['customsClearanceDetail' => $detail],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, $property);
})->with(['name', 'description', 'countryOfManufacture', 'quantity', 'quantityUnits', 'numberOfPieces', 'unitPrice', 'customsValue', 'weight']);

it('rejects a commodity quantity sent as a number', function (): void {
    // The adapter casts quantity to a string; the schema pins that so a
    // change in either direction is a deliberate one.
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['customsClearanceDetail' => validFedexCustomsClearanceDetail(['commodities' => [['quantity' => 2]]])],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'quantity');
});

it('rejects a commodity quantity of zero', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['customsClearanceDetail' => validFedexCustomsClearanceDetail(['commodities' => [['quantity' => '0']]])],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'quantity');
});

it('rejects a customs amount that is not a decimal string', function (string $amount): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['customsClearanceDetail' => validFedexCustomsClearanceDetail(['commodities' => [['customsValue' => ['amount' => $amount]]]])],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'customsValue.amount');
})->with(['1.0E+25', '-5', '$50.47', '']);

it('rejects a commodity name longer than 35 characters', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['customsClearanceDetail' => validFedexCustomsClearanceDetail(['commodities' => [['name' => str_repeat('x', 36)]]])],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'commodities[0].name');
});

it('rejects a duties payor without a country', function (): void {
    $detail = validFedexCustomsClearanceDetail();
    unset($detail['dutiesPayment']['payor']['responsibleParty']['address']);

    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['customsClearanceDetail' => $detail],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'responsibleParty.address');
});

it('rejects an empty shipment special service list', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['shipmentSpecialServices' => ['specialServiceTypes' => []]],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'specialServiceTypes');
});

it('rejects a shipment special service outside the two we send', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody([
        'requestedShipment' => ['shipmentSpecialServices' => ['specialServiceTypes' => ['HOLD_AT_LOCATION']]],
    ]), 'CreateShipmentRequest'))->toThrow(AssertionFailedError::class, 'specialServiceTypes');
});

it('fails loudly when the schema name is unknown', function (): void {
    expect(fn () => assertMatchesFedexSchema(validFedexShipBody(), 'DoesNotExist'))
        ->toThrow(AssertionFailedError::class, 'not defined');
});
