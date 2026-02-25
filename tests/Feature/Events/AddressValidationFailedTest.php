<?php

use App\Events\AddressValidationFailed;
use App\Http\Integrations\USPS\Requests\Address;
use App\Models\Shipment;
use App\Services\AddressValidationService;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

it('dispatches AddressValidationFailed on API error', function (): void {
    Event::fake([AddressValidationFailed::class]);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'error' => [
                'message' => 'Address Not Found.',
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    app(AddressValidationService::class)->validate($shipment);

    Event::assertDispatched(AddressValidationFailed::class, function (AddressValidationFailed $event) use ($shipment): bool {
        return $event->shipment->id === $shipment->id
            && $event->reason === 'Address Not Found.';
    });
});

it('dispatches AddressValidationFailed for correction code 22', function (): void {
    Event::fake([AddressValidationFailed::class]);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'corrections' => [
                ['code' => '22', 'text' => 'Multiple addresses were found.'],
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    app(AddressValidationService::class)->validate($shipment);

    Event::assertDispatched(AddressValidationFailed::class, function (AddressValidationFailed $event): bool {
        return $event->reason === 'Multiple addresses were found.';
    });
});

it('dispatches AddressValidationFailed for unexpected response format', function (): void {
    Event::fake([AddressValidationFailed::class]);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'someUnexpectedField' => 'value',
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    app(AddressValidationService::class)->validate($shipment);

    Event::assertDispatched(AddressValidationFailed::class, function (AddressValidationFailed $event): bool {
        return $event->reason === 'Unexpected USPS response format';
    });
});

it('does not dispatch AddressValidationFailed for deliverable addresses', function (): void {
    Event::fake([AddressValidationFailed::class]);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'matches' => [['code' => '31']],
            'address' => [
                'streetAddress' => '1600 PENNSYLVANIA AVE NW',
                'city' => 'WASHINGTON',
                'state' => 'DC',
                'ZIPCode' => '20500',
            ],
            'additionalInfo' => [
                'DPVConfirmation' => 'Y',
                'business' => 'Y',
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    app(AddressValidationService::class)->validate($shipment);

    Event::assertNotDispatched(AddressValidationFailed::class);
});
