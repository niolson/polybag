<?php

use App\Console\Commands\ValidateShipmentsCommand;
use App\Contracts\AddressValidationPlan;
use App\Enums\Deliverability;
use App\Http\Integrations\Google\Requests\ValidateAddress as GoogleValidateAddress;
use App\Http\Integrations\USPS\Requests\Address;
use App\Models\Shipment;
use App\Services\SettingsService;
use App\Services\Validation\FakeAddressValidator;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    createUspsAccount();
});

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

// International Shipments

/**
 * @return array<string, mixed>
 */
function googleGermanyResponse(): array
{
    return [
        'result' => [
            'verdict' => ['addressComplete' => true],
            'address' => [
                'postalAddress' => [
                    'regionCode' => 'DE',
                    'addressLines' => ['Unter den Linden 1'],
                    'locality' => 'Berlin',
                    'postalCode' => '10117',
                ],
            ],
            'metadata' => ['residential' => true],
        ],
    ];
}

it('validates an international shipment on the schedule', function (): void {
    config(['services.google_address_validation.api_key' => 'test-google-key']);
    app(SettingsService::class)->set('address_validation_google_enabled', true);

    Saloon::fake([
        GoogleValidateAddress::class => MockResponse::make(googleGermanyResponse()),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'DE', 'checked' => false]);

    $this->artisan('shipments:validate')
        ->assertSuccessful()
        ->expectsOutputToContain('Found 1 shipment(s) to validate.');

    Saloon::assertSent(GoogleValidateAddress::class);
    expect($shipment->fresh()->validation_attempted_at)->not->toBeNull();
});

it('does not select an attempted international shipment again', function (): void {
    config(['services.google_address_validation.api_key' => 'test-google-key']);
    app(SettingsService::class)->set('address_validation_google_enabled', true);

    Saloon::fake([
        GoogleValidateAddress::class => MockResponse::make(googleGermanyResponse()),
    ]);

    Shipment::factory()->create(['country' => 'DE', 'checked' => false]);

    $this->artisan('shipments:validate')->assertSuccessful();
    $this->artisan('shipments:validate')
        ->assertSuccessful()
        ->expectsOutputToContain('No pending shipments');

    Saloon::mockClient()->assertSentCount(1, GoogleValidateAddress::class);
});

it('leaves out an international shipment no validator can check, without failing', function (): void {
    Saloon::fake([]);

    $shipment = Shipment::factory()->create(['country' => 'DE', 'checked' => false]);

    $this->artisan('shipments:validate')
        ->assertSuccessful()
        ->expectsOutputToContain('Left out 1 shipment(s) with no address validator')
        ->expectsOutputToContain('No pending shipments')
        ->doesntExpectOutputToContain('Skipped');

    Saloon::assertNothingSent();
    expect($shipment->fresh()->validation_attempted_at)->toBeNull();
});

it('counts only shipments with a validator against the limit', function (): void {
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
            'additionalInfo' => ['DPVConfirmation' => 'Y', 'business' => 'Y'],
        ]),
    ]);

    // Created first, so a limit applied before the validator check would pick only these.
    Shipment::factory()->count(2)->create(['country' => 'DE', 'checked' => false]);
    $us = Shipment::factory()->create(['country' => 'US', 'checked' => false]);

    $this->artisan('shipments:validate', ['--limit' => 1])
        ->assertSuccessful()
        ->expectsOutputToContain('Found 1 shipment(s) to validate.');

    expect($us->fresh()->validation_attempted_at)->not->toBeNull();
});

it('warns about capped international shipments', function (): void {
    Shipment::factory()->create([
        'country' => 'DE',
        'checked' => false,
        'validation_attempts' => ValidateShipmentsCommand::MAX_SCHEDULED_ATTEMPTS,
    ]);

    $this->artisan('shipments:validate')
        ->expectsOutputToContain('1 shipment(s) reached the limit');
});

it('stops reading candidates once the limit is filled', function (): void {
    $plan = new class implements AddressValidationPlan
    {
        /** @var array<int, true> */
        public array $asked = [];

        public function validatorsFor(Shipment $shipment): array
        {
            $this->asked[$shipment->id] = true;

            return [new FakeAddressValidator];
        }
    };
    app()->instance(AddressValidationPlan::class, $plan);

    Shipment::factory()->count(3)->create(['country' => 'US', 'checked' => false]);

    $this->artisan('shipments:validate', ['--limit' => 1])->assertSuccessful();

    expect($plan->asked)->toHaveCount(1);
});
