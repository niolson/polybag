<?php

use App\Services\Carriers\FedexErrorMessage;

it('says the ship-from location has no phone number when FedEx rejects a null shipper phone', function (): void {
    $errors = [[
        'code' => 'INVALID.INPUT.EXCEPTION',
        'message' => "Validation failed for object='shipShipmentInputVO'. Error count: 1",
        'parameterList' => [[
            'key' => 'NotNull.shipShipmentInputVO.requestedShipment.shipper.contact.phoneNumber',
            'value' => 'phoneNumber cannot be null',
        ]],
    ]];

    expect(FedexErrorMessage::from($errors))
        ->toBe('The ship-from location has no phone number. Add one under Locations.');
});

it('keeps the message of an error with no parameter list', function (): void {
    expect(FedexErrorMessage::from([
        ['code' => 'SERVICE.PACKAGECOMBINATION.INVALID', 'message' => 'Invalid service type and packaging combination.'],
    ]))->toBe('Invalid service type and packaging combination.');
});

it('reports every error FedEx returned', function (): void {
    $message = FedexErrorMessage::from([
        ['code' => 'ACCOUNT.NUMBER.MISMATCH', 'message' => 'The account number does not match'],
        [
            'code' => 'INVALID.INPUT.EXCEPTION',
            'message' => "Validation failed for object='shipShipmentInputVO'. Error count: 2",
            'parameterList' => [
                ['key' => 'NotNull.shipShipmentInputVO.requestedShipment.recipients[0].address.city', 'value' => 'city cannot be null'],
                ['key' => 'Size.shipShipmentInputVO.requestedShipment.shipper.address.postalCode', 'value' => 'postalCode is too long'],
            ],
        ],
    ]);

    expect($message)->toBe(
        'The account number does not match.'
        ." The shipment's address was rejected: city cannot be null (recipients[0].address.city). Check the shipment's address."
        .' The ship-from location was rejected: postalCode is too long (shipper.address.postalCode). Check it under Locations.'
    );
});

it('shows a field it has no plain words for with its path cleaned, not dropped', function (): void {
    expect(FedexErrorMessage::from([[
        'code' => 'INVALID.INPUT.EXCEPTION',
        'message' => "Validation failed for object='rateRequestInputVO'. Error count: 1",
        'parameterList' => [[
            'key' => 'Pattern.rateRequestInputVO.requestedShipment.requestedPackageLineItems[0].weight.units',
            'value' => 'units must match LB or KG',
        ]],
    ]]))->toBe('units must match LB or KG (requestedPackageLineItems[0].weight.units).');
});

it('keeps a specific message ahead of its fields', function (): void {
    expect(FedexErrorMessage::from([[
        'code' => 'RECIPIENT.CONTACT.INVALID',
        'message' => 'Recipient contact is invalid',
        'parameterList' => [['key' => 'recipient.contact.personName', 'value' => 'too long']],
    ]]))->toBe("Recipient contact is invalid. The shipment's address was rejected: too long (recipient.contact.personName). Check the shipment's address.");
});

it('points a shipper phone number that is present but rejected at the location, without claiming it is missing', function (): void {
    expect(FedexErrorMessage::describeField('Size.shipShipmentInputVO.requestedShipment.shipper.contact.phoneNumber', 'phoneNumber is too long'))
        ->toBe('The ship-from location was rejected: phoneNumber is too long (shipper.contact.phoneNumber). Check it under Locations.');
});

it('cleans a parameter key down to the field it names', function (string $key, string $path): void {
    expect(FedexErrorMessage::fieldPath($key))->toBe($path);
})->with([
    'constraint, object and requestedShipment' => ['NotNull.shipShipmentInputVO.requestedShipment.shipper.contact.phoneNumber', 'shipper.contact.phoneNumber'],
    'constraint and object only' => ['NotNull.shipShipmentInputVO.accountNumber.value', 'accountNumber.value'],
    'already a path' => ['shipper.address.city', 'shipper.address.city'],
    'a single word' => ['specialServiceType', 'specialServiceType'],
]);

it('falls back when FedEx returned no errors', function (): void {
    expect(FedexErrorMessage::from([]))->toBe('FedEx API error')
        ->and(FedexErrorMessage::from([['code' => 'SYSTEM.UNAVAILABLE.EXCEPTION']]))->toBe('SYSTEM.UNAVAILABLE.EXCEPTION.');
});
