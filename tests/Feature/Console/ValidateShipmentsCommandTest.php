<?php

use App\Enums\Deliverability;
use App\Http\Integrations\USPS\Requests\Address;
use App\Models\Shipment;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

it('validates pending shipments', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'matches' => [['code' => '31']],
            'address' => [
                'streetAddress' => '1600 PENNSYLVANIA AVE NW',
                'secondaryAddress' => '',
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

    Shipment::factory()->create(['country' => 'US', 'checked' => false]);

    $this->artisan('shipments:validate')
        ->assertSuccessful()
        ->expectsOutputToContain('Validated');
});

it('reports skipped shipments on server error', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make('', 503),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US', 'checked' => false]);

    $this->artisan('shipments:validate')
        ->assertFailed()
        ->expectsOutputToContain('Skipped');

    $shipment->refresh();
    expect($shipment->checked)->toBeFalse()
        ->and($shipment->deliverability)->toBe(Deliverability::NotChecked);
});

it('shows nothing to do when all shipments are checked', function (): void {
    Shipment::factory()->create(['country' => 'US', 'checked' => true]);

    $this->artisan('shipments:validate')
        ->assertSuccessful()
        ->expectsOutputToContain('No pending shipments');
});
