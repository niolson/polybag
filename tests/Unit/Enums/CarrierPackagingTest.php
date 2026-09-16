<?php

use App\Enums\CarrierPackaging;

it('names the carrier whose packaging each case is', function (): void {
    foreach (CarrierPackaging::cases() as $packaging) {
        $expected = match (substr($packaging->name, 0, 3)) {
            'Usp' => 'USPS',
            'Fed' => 'FedEx',
            default => 'UPS',
        };

        expect($packaging->carrier())->toBe($expected, $packaging->name);
    }
});

it('labels every case for the Box Size form', function (): void {
    foreach (CarrierPackaging::cases() as $packaging) {
        expect($packaging->getLabel())->not->toBe('')
            ->and($packaging->getLabel())->toStartWith($packaging->carrier());
    }
});

it('keeps Priority Mail Express flat-rate envelopes apart from the Priority Mail ones', function (): void {
    // A packer holding an Express envelope uses the service printed on it.
    expect(CarrierPackaging::UspsExpressFlatRateEnvelope)->not->toBe(CarrierPackaging::UspsFlatRateEnvelope)
        ->and(CarrierPackaging::UspsExpressLegalFlatRateEnvelope)->not->toBe(CarrierPackaging::UspsLegalFlatRateEnvelope)
        ->and(CarrierPackaging::UspsExpressPaddedFlatRateEnvelope)->not->toBe(CarrierPackaging::UspsPaddedFlatRateEnvelope);
});
