<?php

use App\Contracts\AddressValidationInterface;
use App\Enums\AddressValidationOutcome;
use App\Enums\AddressValidator;
use App\Enums\Deliverability;
use App\Enums\Role;
use App\Enums\ValidationReason;
use App\Enums\ValidationTrigger;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Http\Integrations\Fedex\Requests\ValidateAddress as FedexValidateAddress;
use App\Http\Integrations\Google\Requests\ValidateAddress as GoogleValidateAddress;
use App\Http\Integrations\Ups\Requests\ValidateAddress as UpsValidateAddress;
use App\Http\Integrations\USPS\Requests\Address;
use App\Models\AddressValidationAnswer;
use App\Models\Shipment;
use App\Models\User;
use App\Services\AddressValidationService;
use App\Services\Validation\FedexAddressValidator;
use App\Services\Validation\GoogleAddressValidator;
use App\Services\Validation\UpsAddressValidator;
use App\Services\Validation\UspsAddressValidator;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\Support\FixedValidationPlan;

beforeEach(function (): void {
    createUspsAccount();
    config(['services.google_address_validation.api_key' => 'test-google-key']);
});

function answerTestToken(): MockResponse
{
    return MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]);
}

function answerTestUspsNotFound(): MockResponse
{
    return MockResponse::make([
        'apiVersion' => 'v3',
        'error' => ['code' => '404', 'message' => 'There is no match for the address requested.'],
    ], 404);
}

function answerTestUspsMatch(string $dpv = 'Y', string $carrierRoute = 'C001'): MockResponse
{
    return MockResponse::make([
        'matches' => [['code' => '31']],
        'address' => [
            'streetAddress' => '1600 PENNSYLVANIA AVE NW',
            'city' => 'WASHINGTON',
            'state' => 'DC',
            'ZIPCode' => '20500',
        ],
        'additionalInfo' => ['DPVConfirmation' => $dpv, 'carrierRoute' => $carrierRoute, 'business' => 'Y'],
    ]);
}

/**
 * @param  array<string, mixed>  $result
 */
function answerTestGoogle(array $result = []): MockResponse
{
    return MockResponse::make(['result' => array_replace([
        'verdict' => ['addressComplete' => true],
        'address' => ['postalAddress' => ['addressLines' => ['1600 Amphitheatre Pkwy'], 'locality' => 'Mountain View']],
        'uspsData' => ['dpvConfirmation' => 'Y'],
    ], $result)]);
}

function answerTestUspsAndGoogle(): AddressValidationService
{
    return new AddressValidationService(new FixedValidationPlan([new UspsAddressValidator, new GoogleAddressValidator]));
}

// The log

it('logs each answer in a fallback chain and records the validator that settled it', function (): void {
    Saloon::fake([
        '*oauth*' => answerTestToken(),
        Address::class => answerTestUspsNotFound(),
        GoogleValidateAddress::class => answerTestGoogle(),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    answerTestUspsAndGoogle()->validate($shipment);

    $answers = $shipment->validationAnswers()->orderBy('id')->get();

    expect($shipment->fresh()->validation_source)->toBe(AddressValidator::Google)
        ->and($answers)->toHaveCount(2)
        ->and($answers[0]->validator)->toBe(AddressValidator::Usps)
        ->and($answers[0]->paid)->toBeTrue()
        ->and($answers[0]->outcome)->toBe(AddressValidationOutcome::Inconclusive)
        ->and($answers[0]->deliverability)->toBeNull()
        ->and($answers[0]->reason)->toBe(ValidationReason::NoMatch)
        ->and($answers[0]->country)->toBe('US')
        ->and($answers[0]->trigger)->toBe(ValidationTrigger::Manual)
        ->and($answers[0]->created_at)->not->toBeNull()
        ->and($answers[1]->validator)->toBe(AddressValidator::Google)
        ->and($answers[1]->outcome)->toBe(AddressValidationOutcome::Settled)
        ->and($answers[1]->deliverability)->toBe(Deliverability::Yes)
        ->and($answers[1]->reason)->toBeNull();
});

it('logs a settled no with its reason', function (): void {
    Saloon::fake([
        '*oauth*' => answerTestToken(),
        Address::class => answerTestUspsMatch(dpv: 'N'),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    app(AddressValidationService::class)->validate($shipment);

    $answer = $shipment->validationAnswers()->sole();

    expect($answer->outcome)->toBe(AddressValidationOutcome::Settled)
        ->and($answer->deliverability)->toBe(Deliverability::No)
        ->and($answer->reason)->toBe(ValidationReason::DpvNotConfirmed)
        ->and($shipment->fresh()->validation_source)->toBe(AddressValidator::Usps);
});

it('writes no answer for a validator that could not run', function (): void {
    config(['services.google_address_validation.api_key' => null, 'services.oauth.broker_url' => null]);
    Saloon::fake([
        '*oauth*' => answerTestToken(),
        Address::class => MockResponse::make(['error' => ['message' => 'Too Many Requests']], 429),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    expect(answerTestUspsAndGoogle()->validate($shipment))->toBe(AddressValidationOutcome::Unavailable)
        ->and(AddressValidationAnswer::count())->toBe(0)
        ->and($shipment->fresh()->validation_source)->toBeNull();
});

it('logs only the validators that answered when one in the chain could not run', function (): void {
    Saloon::fake([
        '*oauth*' => answerTestToken(),
        Address::class => MockResponse::make('', 503),
        GoogleValidateAddress::class => answerTestGoogle(),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    answerTestUspsAndGoogle()->validate($shipment);

    expect($shipment->validationAnswers()->pluck('validator')->all())->toBe([AddressValidator::Google]);
});

it('adds rows and replaces the source when validated again', function (): void {
    Saloon::fake([
        '*oauth*' => answerTestToken(),
        Address::class => answerTestUspsMatch(),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    app(AddressValidationService::class)->validate($shipment);

    Saloon::fake([
        '*oauth*' => answerTestToken(),
        Address::class => answerTestUspsNotFound(),
        GoogleValidateAddress::class => answerTestGoogle(),
    ]);
    answerTestUspsAndGoogle()->validate($shipment);

    expect($shipment->validationAnswers()->count())->toBe(3)
        ->and($shipment->fresh()->validation_source)->toBe(AddressValidator::Google);
});

it('clears the source when a re-validation ends inconclusive', function (): void {
    $shipment = Shipment::factory()->create([
        'country' => 'US',
        'checked' => true,
        'deliverability' => Deliverability::Yes,
        'validation_source' => AddressValidator::Google,
    ]);

    Saloon::fake([
        '*oauth*' => answerTestToken(),
        Address::class => answerTestUspsNotFound(),
        GoogleValidateAddress::class => answerTestGoogle(['verdict' => ['addressComplete' => false], 'uspsData' => null]),
    ]);

    answerTestUspsAndGoogle()->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::Unverified)
        ->and($shipment->validation_source)->toBeNull()
        ->and($shipment->validationAnswers()->pluck('reason')->all())
        ->toBe([ValidationReason::NoMatch, ValidationReason::Incomplete]);
});

it('clears the source and keeps the answers when the address changes', function (): void {
    $shipment = Shipment::factory()->create([
        'country' => 'US',
        'checked' => true,
        'deliverability' => Deliverability::Yes,
        'validation_source' => AddressValidator::Usps,
    ]);
    AddressValidationAnswer::factory()->for($shipment)->create();

    $shipment->update(['address1' => '1 Infinite Loop']);

    expect($shipment->fresh()->validation_source)->toBeNull()
        ->and($shipment->validationAnswers()->count())->toBe(1);
});

it('removes the answers with their Shipment', function (): void {
    $answer = AddressValidationAnswer::factory()->by(AddressValidator::Google)->inconclusive()->create();

    $answer->shipment->delete();

    expect(AddressValidationAnswer::count())->toBe(0);
});

// Reasons

it('reports each validator\'s reason for an answer that is inconclusive or no', function (
    Closure $setUp,
    AddressValidationOutcome $outcome,
    ?Deliverability $deliverability,
    ValidationReason $reason,
): void {
    /** @var AddressValidationInterface $validator */
    $validator = $setUp();
    $shipment = Shipment::factory()->create(['country' => 'US']);

    $result = $validator->validate($shipment);

    expect($result->outcome)->toBe($outcome)
        ->and($result->reason)->toBe($reason);

    if ($deliverability !== null) {
        expect($shipment->fresh()->deliverability)->toBe($deliverability);
    }
})->with([
    'USPS not found' => [
        function (): UspsAddressValidator {
            Saloon::fake(['*oauth*' => answerTestToken(), Address::class => answerTestUspsNotFound()]);

            return new UspsAddressValidator;
        },
        AddressValidationOutcome::Inconclusive, null, ValidationReason::NoMatch,
    ],
    'USPS rejected request' => [
        function (): UspsAddressValidator {
            Saloon::fake(['*oauth*' => answerTestToken(), Address::class => MockResponse::make(['error' => ['message' => 'ZIPCode must be 5 digits.']], 400)]);

            return new UspsAddressValidator;
        },
        AddressValidationOutcome::Inconclusive, null, ValidationReason::RequestRejected,
    ],
    'USPS multiple addresses' => [
        function (): UspsAddressValidator {
            Saloon::fake(['*oauth*' => answerTestToken(), Address::class => MockResponse::make(['corrections' => [['code' => '22', 'text' => 'Multiple']]])]);

            return new UspsAddressValidator;
        },
        AddressValidationOutcome::Inconclusive, null, ValidationReason::MultipleCandidates,
    ],
    'USPS unknown correction' => [
        function (): UspsAddressValidator {
            Saloon::fake(['*oauth*' => answerTestToken(), Address::class => MockResponse::make(['corrections' => [['code' => '99', 'text' => 'Address Not Found']]])]);

            return new UspsAddressValidator;
        },
        AddressValidationOutcome::Inconclusive, null, ValidationReason::NoMatch,
    ],
    'USPS unexpected response' => [
        function (): UspsAddressValidator {
            Saloon::fake(['*oauth*' => answerTestToken(), Address::class => MockResponse::make(['something' => 'else'])]);

            return new UspsAddressValidator;
        },
        AddressValidationOutcome::Inconclusive, null, ValidationReason::UnexpectedResponse,
    ],
    'USPS DPV not confirmed' => [
        function (): UspsAddressValidator {
            Saloon::fake(['*oauth*' => answerTestToken(), Address::class => answerTestUspsMatch(dpv: 'N')]);

            return new UspsAddressValidator;
        },
        AddressValidationOutcome::Settled, Deliverability::No, ValidationReason::DpvNotConfirmed,
    ],
    'USPS phantom route' => [
        function (): UspsAddressValidator {
            Saloon::fake(['*oauth*' => answerTestToken(), Address::class => answerTestUspsMatch(carrierRoute: 'R777')]);

            return new UspsAddressValidator;
        },
        AddressValidationOutcome::Settled, Deliverability::No, ValidationReason::PhantomRoute,
    ],
    'Google incomplete' => [
        function (): GoogleAddressValidator {
            Saloon::fake([GoogleValidateAddress::class => answerTestGoogle(['verdict' => ['addressComplete' => false], 'uspsData' => null])]);

            return new GoogleAddressValidator;
        },
        AddressValidationOutcome::Inconclusive, null, ValidationReason::Incomplete,
    ],
    'Google suspicious component' => [
        function (): GoogleAddressValidator {
            Saloon::fake([GoogleValidateAddress::class => answerTestGoogle([
                'uspsData' => null,
                'address' => ['addressComponents' => [['confirmationLevel' => 'UNCONFIRMED_AND_SUSPICIOUS']]],
            ])]);

            return new GoogleAddressValidator;
        },
        AddressValidationOutcome::Settled, Deliverability::No, ValidationReason::SuspiciousComponent,
    ],
    'Google DPV not confirmed' => [
        function (): GoogleAddressValidator {
            Saloon::fake([GoogleValidateAddress::class => answerTestGoogle(['uspsData' => ['dpvConfirmation' => 'N']])]);

            return new GoogleAddressValidator;
        },
        AddressValidationOutcome::Settled, Deliverability::No, ValidationReason::DpvNotConfirmed,
    ],
    'Google phantom route' => [
        function (): GoogleAddressValidator {
            Saloon::fake([GoogleValidateAddress::class => answerTestGoogle(['uspsData' => ['dpvConfirmation' => 'Y', 'carrierRoute' => 'R778']])]);

            return new GoogleAddressValidator;
        },
        AddressValidationOutcome::Settled, Deliverability::No, ValidationReason::PhantomRoute,
    ],
    'FedEx unresolved' => [
        function (): FedexAddressValidator {
            createFedexAccount();
            Saloon::fake(['*oauth*' => answerTestToken(), FedexValidateAddress::class => MockResponse::make([
                'output' => ['resolvedAddresses' => [['attributes' => ['Resolved' => 'false']]]],
            ])]);

            return new FedexAddressValidator;
        },
        AddressValidationOutcome::Inconclusive, null, ValidationReason::NoMatch,
    ],
    'FedEx no delivery point' => [
        function (): FedexAddressValidator {
            createFedexAccount();
            Saloon::fake(['*oauth*' => answerTestToken(), FedexValidateAddress::class => MockResponse::make([
                'output' => ['resolvedAddresses' => [['attributes' => ['Resolved' => 'true', 'DPV' => 'false']]]],
            ])]);

            return new FedexAddressValidator;
        },
        AddressValidationOutcome::Inconclusive, null, ValidationReason::NotDeliveryPoint,
    ],
    'FedEx error' => [
        function (): FedexAddressValidator {
            createFedexAccount();
            Saloon::fake(['*oauth*' => answerTestToken(), FedexValidateAddress::class => MockResponse::make([
                'errors' => [['code' => 'STATE.CODE.INVALID', 'message' => 'Invalid state code']],
            ])]);

            return new FedexAddressValidator;
        },
        AddressValidationOutcome::Inconclusive, null, ValidationReason::RequestRejected,
    ],
    'FedEx unexpected response' => [
        function (): FedexAddressValidator {
            createFedexAccount();
            Saloon::fake(['*oauth*' => answerTestToken(), FedexValidateAddress::class => MockResponse::make(['output' => []])]);

            return new FedexAddressValidator;
        },
        AddressValidationOutcome::Inconclusive, null, ValidationReason::UnexpectedResponse,
    ],
    'UPS ambiguous' => [
        function (): UpsAddressValidator {
            createUpsAccount();
            Saloon::fake(['*oauth*' => answerTestToken(), UpsValidateAddress::class => MockResponse::make([
                'XAVResponse' => ['AmbiguousAddressIndicator' => '', 'Candidate' => [['AddressKeyFormat' => []], ['AddressKeyFormat' => []]]],
            ])]);

            return new UpsAddressValidator;
        },
        AddressValidationOutcome::Inconclusive, null, ValidationReason::MultipleCandidates,
    ],
    'UPS no candidates' => [
        function (): UpsAddressValidator {
            createUpsAccount();
            Saloon::fake(['*oauth*' => answerTestToken(), UpsValidateAddress::class => MockResponse::make([
                'XAVResponse' => ['NoCandidatesIndicator' => ''],
            ])]);

            return new UpsAddressValidator;
        },
        AddressValidationOutcome::Inconclusive, null, ValidationReason::NoMatch,
    ],
    'UPS error' => [
        function (): UpsAddressValidator {
            createUpsAccount();
            Saloon::fake(['*oauth*' => answerTestToken(), UpsValidateAddress::class => MockResponse::make([
                'errors' => [['code' => '264002', 'message' => 'Country code is invalid']],
            ])]);

            return new UpsAddressValidator;
        },
        AddressValidationOutcome::Inconclusive, null, ValidationReason::RequestRejected,
    ],
    'UPS unexpected response' => [
        function (): UpsAddressValidator {
            createUpsAccount();
            Saloon::fake(['*oauth*' => answerTestToken(), UpsValidateAddress::class => MockResponse::make(['XAVResponse' => []])]);

            return new UpsAddressValidator;
        },
        AddressValidationOutcome::Inconclusive, null, ValidationReason::UnexpectedResponse,
    ],
]);

it('reports no reason for a settled answer that is not no', function (): void {
    Saloon::fake(['*oauth*' => answerTestToken(), Address::class => answerTestUspsMatch()]);

    $result = (new UspsAddressValidator)->validate(Shipment::factory()->create(['country' => 'US']));

    expect($result->outcome)->toBe(AddressValidationOutcome::Settled)
        ->and($result->reason)->toBeNull();
});

// Triggers and where the source shows

it('records a run from shipments:validate as scheduled and summarizes it', function (): void {
    Saloon::fake([
        '*oauth*' => answerTestToken(),
        Address::class => answerTestUspsMatch(),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US', 'checked' => false]);

    $this->artisan('shipments:validate')
        ->assertSuccessful()
        ->expectsTable(['Metric', 'Count'], [
            ['Validated', 1],
            ['Attempted, still unsettled', 0],
            ['Skipped (validators unavailable)', 0],
            ['Errors', 0],
            ['  - yes', 1],
            ['  - settled by USPS', 1],
            ['Paid validator requests', 1],
        ]);

    expect($shipment->validationAnswers()->sole()->trigger)->toBe(ValidationTrigger::Scheduled);
});

it('does not count an unavailable paid validator as a request', function (): void {
    Saloon::fake([
        '*oauth*' => answerTestToken(),
        Address::class => MockResponse::make('', 503),
    ]);

    Shipment::factory()->create(['country' => 'US', 'checked' => false]);

    $this->artisan('shipments:validate')
        ->assertFailed()
        ->expectsTable(['Metric', 'Count'], [
            ['Validated', 0],
            ['Attempted, still unsettled', 0],
            ['Skipped (validators unavailable)', 1],
            ['Errors', 0],
            ['Paid validator requests', 0],
        ]);
});

it('records validation from View Shipment as manual and shows the source', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    Saloon::fake([
        '*oauth*' => answerTestToken(),
        Address::class => answerTestUspsMatch(),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    Livewire::test(ViewShipment::class, ['record' => $shipment->id])
        ->callAction('validateAddress')
        ->assertSee('Validated by')
        ->assertSee('USPS');

    expect($shipment->validationAnswers()->sole()->trigger)->toBe(ValidationTrigger::Manual);
});
