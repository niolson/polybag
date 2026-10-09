<?php

use App\Contracts\SendsCustomsTerms;
use App\DataTransferObjects\Customs\RecipientTaxId;
use App\DataTransferObjects\Customs\ResolvedCustomsTerms;
use App\DataTransferObjects\Customs\SellerTaxRegistration;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\CustomsTermsOrigin;
use App\Enums\DutiesTerms;
use App\Enums\RecipientTaxIdType;
use App\Enums\TaxRegistrationRegime;
use App\Http\Integrations\Fedex\Requests\CreateShipment;
use App\Http\Integrations\Fedex\Requests\Rates;
use App\Services\Carriers\FedexAdapter;
use App\Services\Customs\CustomsTermsSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * `international-customs-terms/07`: FedEx sends the resolved duties term, the
 * seller registration, the recipient tax ID and the export filing, and reports
 * what it put on the wire so the Label's snapshot records only that.
 */
beforeEach(function (): void {
    $this->adapter = new FedexAdapter;
    createFedexAccount();
});

function fedexCustomsOrigin(): AddressData
{
    return new AddressData(
        firstName: 'Shipping',
        lastName: 'Center',
        streetAddress: '123 Warehouse St',
        city: 'Seattle',
        stateOrProvince: 'WA',
        postalCode: '98072',
        phone: '5551234567',
    );
}

function fedexCustomsGermany(?string $email = null, ?string $company = null): AddressData
{
    return new AddressData(
        firstName: 'Anna',
        lastName: 'Schmidt',
        streetAddress: 'Hauptstrasse 1',
        city: 'Berlin',
        stateOrProvince: null,
        postalCode: '10115',
        country: 'DE',
        company: $company,
        phone: '4930123456',
        email: $email,
    );
}

function fedexCustomsBrazil(?string $company = null): AddressData
{
    return new AddressData(
        firstName: 'Joao',
        lastName: 'Silva',
        streetAddress: 'Rua Augusta 100',
        city: 'Sao Paulo',
        stateOrProvince: 'SP',
        postalCode: '01310100',
        country: 'BR',
        company: $company,
        phone: '551131234567',
    );
}

function fedexCustomsDomestic(): AddressData
{
    return new AddressData(
        firstName: 'John',
        lastName: 'Doe',
        streetAddress: '456 Main St',
        city: 'Los Angeles',
        stateOrProvince: 'CA',
        postalCode: '90210',
        phone: '5559876543',
        email: 'john@example.com',
    );
}

function fedexCustomsTerms(string $country, ?DutiesTerms $term, ?SellerTaxRegistration $registration = null): ResolvedCustomsTerms
{
    return new ResolvedCustomsTerms(
        applies: true,
        destinationCountry: $country,
        dutiesTerms: $term,
        dutiesTermsOrigin: $term === null ? null : CustomsTermsOrigin::Order,
        registration: $registration,
        applicableRegistration: $registration,
    );
}

/**
 * @param  list<CustomsItem>|null  $customsItems
 */
function fedexCustomsShipRequest(AddressData $to, ?ResolvedCustomsTerms $terms, ?RecipientTaxId $taxId = null, ?string $itn = null, ?string $ein = null, ?array $customsItems = null): ShipRequest
{
    return new ShipRequest(
        fromAddress: fedexCustomsOrigin(),
        toAddress: $to,
        packageData: new PackageData(weight: 2.0, length: 8, width: 6, height: 4),
        selectedRate: new RateResponse(
            carrier: 'FedEx',
            serviceCode: 'INTERNATIONAL_PRIORITY',
            serviceName: 'FedEx International Priority',
            price: 48.10,
            metadata: ['serviceType' => 'INTERNATIONAL_PRIORITY'],
        ),
        customsItems: $customsItems ?? [new CustomsItem(description: 'Ceramic Mug', quantity: 1, unitValue: 12.0, weight: 0.8)],
        shipDate: CarbonImmutable::parse('2026-10-12'),
        exportItn: $itn,
        customsTerms: $terms,
        recipientTaxId: $taxId,
        exporterEin: $ein,
    );
}

function fakeFedexCustomsShipEndpoints(): void
{
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => ['transactionShipments' => [[
                'masterTrackingNumber' => '794644790138',
                'completedShipmentDetail' => ['shipmentRating' => ['shipmentRateDetails' => [['totalNetCharge' => 48.10]]]],
                'pieceResponses' => [[
                    'trackingNumber' => '794644790138',
                    'packageDocuments' => [['encodedLabel' => 'JVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh']],
                ]],
            ]]],
        ]),
    ]);
}

/**
 * The requestedShipment of the one CreateShipment sent, checked against our
 * schema.
 *
 * @return array<string, mixed>
 */
function sentFedexCustomsShipment(): array
{
    $shipment = null;

    Saloon::assertSent(function ($request) use (&$shipment): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        assertMatchesFedexSchema($request->body()->all(), 'CreateShipmentRequest');
        $shipment = $request->body()->all()['requestedShipment'];

        return true;
    });

    return $shipment;
}

/**
 * The customs facts a built FedEx body carries, in the vocabulary of
 * DeclaredCustomsTerms, read out of the body and not out of the adapter.
 *
 * @param  array<string, mixed>  $shipment
 * @return array{duties_terms: string|null, registration: string|null, recipient_tax_id: bool, export_itn: string|null}
 */
function fedexCustomsFactsOf(array $shipment): array
{
    $customs = $shipment['customsClearanceDetail'] ?? null;
    $statement = $customs['exportDetail']['exportComplianceStatement'] ?? null;

    return [
        'duties_terms' => $customs === null ? null : strtolower($customs['commercialInvoice']['termsOfSale']),
        'registration' => collect($shipment['shipper']['tins'] ?? [])->firstWhere('tinType', '!=', 'FEDERAL')['number'] ?? null,
        'recipient_tax_id' => isset($shipment['recipients'][0]['tins']),
        'export_itn' => $statement === null ? null : substr($statement, 3),
    ];
}

it('is marked as sending customs terms, so the workflow snapshots its labels', function (): void {
    expect($this->adapter)->toBeInstanceOf(SendsCustomsTerms::class);
});

it('bills DDP duties to the sender account and says DDP on the invoice', function (): void {
    fakeFedexCustomsShipEndpoints();

    $this->adapter->createShipment(fedexCustomsShipRequest(fedexCustomsGermany(), fedexCustomsTerms('DE', DutiesTerms::Ddp)));

    $customs = sentFedexCustomsShipment()['customsClearanceDetail'];

    expect($customs['dutiesPayment'])->toBe([
        'paymentType' => 'SENDER',
        'payor' => ['responsibleParty' => ['accountNumber' => ['value' => 'test_account']]],
    ])->and($customs['commercialInvoice']['termsOfSale'])->toBe('DDP');
});

it('bills DDU duties to the recipient with no payor and says DDU on the invoice', function (?ResolvedCustomsTerms $terms): void {
    fakeFedexCustomsShipEndpoints();

    $this->adapter->createShipment(fedexCustomsShipRequest(fedexCustomsGermany(), $terms));

    $customs = sentFedexCustomsShipment()['customsClearanceDetail'];

    expect($customs['dutiesPayment'])->toBe(['paymentType' => 'RECIPIENT'])
        ->and($customs['commercialInvoice']['termsOfSale'])->toBe('DDU');
})->with([
    'resolved DDU' => [fn (): ResolvedCustomsTerms => fedexCustomsTerms('DE', DutiesTerms::Ddu)],
    'no terms resolved, the default FedEx has always declared' => [null],
]);

it('keeps rate requests on the only duties payment type FedEx rating accepts, whatever the term', function (DutiesTerms $term): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rates::class => MockResponse::make(['output' => ['rateReplyDetails' => []]]),
    ]);

    $this->adapter->getRates(new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '10115',
        destinationCountry: 'DE',
        packages: [new PackageData(weight: 2.0, length: 8, width: 6, height: 4)],
        customsTerms: fedexCustomsTerms('DE', $term),
    ), ['INTERNATIONAL_PRIORITY']);

    Saloon::assertSent(fn ($request): bool => $request instanceof Rates
        && ($request->body()->all()['requestedShipment']['customsClearanceDetail']['dutiesPayment'] ?? null) === ['paymentType' => 'SENDER']);
})->with([DutiesTerms::Ddp, DutiesTerms::Ddu]);

it('sends the seller registration as the first shipper TIN, with its type', function (TaxRegistrationRegime $regime, string $number, string $tinType): void {
    fakeFedexCustomsShipEndpoints();
    $to = match ($regime) {
        TaxRegistrationRegime::Ioss => fedexCustomsGermany(),
        default => fedexCustomsBrazil(),
    };

    $this->adapter->createShipment(fedexCustomsShipRequest(
        $to,
        fedexCustomsTerms($to->country, DutiesTerms::Ddp, new SellerTaxRegistration($regime, $number, CustomsTermsOrigin::Order)),
    ));

    expect(sentFedexCustomsShipment()['shipper']['tins'])->toBe([['number' => $number, 'tinType' => $tinType]]);
})->with([
    // UNCONFIRMED with FedEx: IOSS is sent as BUSINESS_UNION, one constant.
    'IOSS' => [TaxRegistrationRegime::Ioss, 'IM2760000742', FedexAdapter::IOSS_TIN_TYPE],
    'UK VAT' => [TaxRegistrationRegime::UkVat, 'GB123456789', 'BUSINESS_NATIONAL'],
    'VOEC' => [TaxRegistrationRegime::Voec, '1234567', 'BUSINESS_NATIONAL'],
    'ARN' => [TaxRegistrationRegime::Arn, '123456789012', 'BUSINESS_NATIONAL'],
]);

it('sends IOSS as BUSINESS_UNION until FedEx names another type', function (): void {
    expect(FedexAdapter::IOSS_TIN_TYPE)->toBe('BUSINESS_UNION');
});

it('sends no shipper or recipient TIN and no export detail when nothing was resolved', function (): void {
    fakeFedexCustomsShipEndpoints();

    $this->adapter->createShipment(fedexCustomsShipRequest(fedexCustomsGermany(), fedexCustomsTerms('DE', DutiesTerms::Ddu), ein: '123456789'));

    $shipment = sentFedexCustomsShipment();

    expect($shipment['shipper'])->not->toHaveKey('tins')
        ->and($shipment['recipients'][0])->not->toHaveKey('tins')
        ->and($shipment['customsClearanceDetail'])->not->toHaveKey('exportDetail');
});

it('leaves a registration out, and does not record it, when its number does not fit a FedEx TIN', function (): void {
    fakeFedexCustomsShipEndpoints();
    $request = fedexCustomsShipRequest(
        fedexCustomsBrazil(),
        fedexCustomsTerms('BR', DutiesTerms::Ddu, new SellerTaxRegistration(TaxRegistrationRegime::Arn, '1234567890123456789', CustomsTermsOrigin::Order)),
    );

    $this->adapter->createShipment($request);

    expect(sentFedexCustomsShipment()['shipper'])->not->toHaveKey('tins')
        ->and($this->adapter->declaredCustomsTerms($request)->registration)->toBeNull();
});

it('sends the recipient tax ID as the recipient TIN', function (RecipientTaxIdType $type, string $number, ?string $company, string $tinType): void {
    fakeFedexCustomsShipEndpoints();

    $this->adapter->createShipment(fedexCustomsShipRequest(
        fedexCustomsBrazil($company),
        fedexCustomsTerms('BR', DutiesTerms::Ddu),
        new RecipientTaxId($type, $number),
    ));

    expect(sentFedexCustomsShipment()['recipients'][0]['tins'])->toBe([['number' => $number, 'tinType' => $tinType]]);
})->with([
    'CPF' => [RecipientTaxIdType::Cpf, '12345678909', null, 'PERSONAL_NATIONAL'],
    'CNPJ' => [RecipientTaxIdType::Cnpj, '12ABC34501DE35', 'Acme Ltda', 'BUSINESS_NATIONAL'],
    'PCCC' => [RecipientTaxIdType::Pccc, 'P123456789012', null, 'PERSONAL_NATIONAL'],
    'a VAT number, whatever the company name' => [RecipientTaxIdType::Vat, 'BR123456', null, 'BUSINESS_NATIONAL'],
    'a VAT number of a company' => [RecipientTaxIdType::Vat, 'BR123456', 'Acme Ltda', 'BUSINESS_NATIONAL'],
    'another ID of a company' => [RecipientTaxIdType::Other, 'ABC123', 'Acme Ltda', 'BUSINESS_NATIONAL'],
    'another ID of a person' => [RecipientTaxIdType::Other, 'ABC123', null, 'PERSONAL_NATIONAL'],
]);

it('leaves out a recipient tax ID longer than FedEx takes, and does not record it', function (): void {
    fakeFedexCustomsShipEndpoints();
    $request = fedexCustomsShipRequest(
        fedexCustomsBrazil(),
        fedexCustomsTerms('BR', DutiesTerms::Ddu),
        new RecipientTaxId(RecipientTaxIdType::Other, '1234567890123456789'),
    );

    $this->adapter->createShipment($request);

    expect(sentFedexCustomsShipment()['recipients'][0])->not->toHaveKey('tins')
        ->and($this->adapter->declaredCustomsTerms($request)->recipientTaxIdType)->toBeNull();
});

it('sends an ITN as the export compliance statement, with the EIN after the registration', function (): void {
    fakeFedexCustomsShipEndpoints();

    $this->adapter->createShipment(fedexCustomsShipRequest(
        fedexCustomsGermany(),
        fedexCustomsTerms('DE', DutiesTerms::Ddu, new SellerTaxRegistration(TaxRegistrationRegime::Ioss, 'IM2760000742', CustomsTermsOrigin::Client)),
        itn: 'X20261008123456',
        ein: '123456789',
    ));

    $shipment = sentFedexCustomsShipment();

    expect($shipment['customsClearanceDetail']['exportDetail'])->toBe(['exportComplianceStatement' => 'AESX20261008123456'])
        ->and($shipment['shipper']['tins'])->toBe([
            ['number' => 'IM2760000742', 'tinType' => FedexAdapter::IOSS_TIN_TYPE],
            ['number' => '123456789', 'tinType' => 'FEDERAL'],
        ]);
});

it('never sends an exemption, and sends the EIN only with an ITN', function (): void {
    fakeFedexCustomsShipEndpoints();

    $this->adapter->createShipment(fedexCustomsShipRequest(fedexCustomsGermany(), fedexCustomsTerms('DE', DutiesTerms::Ddu), ein: '123456789'));

    $shipment = sentFedexCustomsShipment();

    expect($shipment['customsClearanceDetail'])->not->toHaveKey('exportDetail')
        ->and($shipment['shipper'])->not->toHaveKey('tins');
});

it('sends the recipient email whenever the Shipment has one, so FedEx can collect DDU duties', function (?string $email, bool $sent): void {
    fakeFedexCustomsShipEndpoints();

    $this->adapter->createShipment(fedexCustomsShipRequest(fedexCustomsGermany($email), fedexCustomsTerms('DE', DutiesTerms::Ddu)));

    $contact = sentFedexCustomsShipment()['recipients'][0]['contact'];

    if ($sent) {
        expect($contact['emailAddress'])->toBe($email);
    } else {
        expect($contact)->not->toHaveKey('emailAddress');
    }
})->with([
    'an email' => ['anna@example.com', true],
    'none' => [null, false],
    'blank' => ['  ', false],
    'one longer than FedEx takes' => [str_repeat('a', 75).'@x.com', false],
]);

it('sends the recipient email on a domestic label too, and no customs data', function (): void {
    fakeFedexCustomsShipEndpoints();

    $request = new ShipRequest(
        fromAddress: fedexCustomsOrigin(),
        toAddress: fedexCustomsDomestic(),
        packageData: new PackageData(weight: 2.0, length: 8, width: 6, height: 4),
        selectedRate: new RateResponse(carrier: 'FedEx', serviceCode: 'FEDEX_GROUND', serviceName: 'FedEx Ground', price: 9.8, metadata: ['serviceType' => 'FEDEX_GROUND']),
        customsTerms: ResolvedCustomsTerms::notApplicable('US'),
        recipientTaxId: new RecipientTaxId(RecipientTaxIdType::Other, 'ABC123'),
        exporterEin: '123456789',
    );

    $this->adapter->createShipment($request);

    $shipment = sentFedexCustomsShipment();

    expect($shipment['recipients'][0]['contact']['emailAddress'])->toBe('john@example.com')
        ->and($shipment)->not->toHaveKey('customsClearanceDetail')
        ->and($shipment['recipients'][0])->not->toHaveKey('tins')
        ->and($this->adapter->declaredCustomsTerms($request)->isEmpty())->toBeTrue();
});

it('reports exactly the customs terms it put on the wire', function (ShipRequest $request): void {
    fakeFedexCustomsShipEndpoints();

    $this->adapter->createShipment($request);

    $declared = $this->adapter->declaredCustomsTerms($request);
    $sent = fedexCustomsFactsOf(sentFedexCustomsShipment());

    expect([
        'duties_terms' => $declared->dutiesTerms?->value,
        'registration' => $declared->registration?->number,
        'recipient_tax_id' => $declared->recipientTaxIdType !== null,
        'export_itn' => $declared->exportItn,
    ])->toBe($sent);
})->with([
    'everything' => [fn (): ShipRequest => fedexCustomsShipRequest(
        fedexCustomsBrazil(),
        fedexCustomsTerms('BR', DutiesTerms::Ddp, new SellerTaxRegistration(TaxRegistrationRegime::Arn, '123456789012', CustomsTermsOrigin::Order)),
        new RecipientTaxId(RecipientTaxIdType::Cpf, '12345678909'),
        'X20261008123456',
        '123456789',
    )],
    'DDU alone' => [fn (): ShipRequest => fedexCustomsShipRequest(fedexCustomsGermany(), fedexCustomsTerms('DE', DutiesTerms::Ddu))],
    'an ITN with no EIN, which FedEx still takes' => [fn (): ShipRequest => fedexCustomsShipRequest(fedexCustomsGermany(), fedexCustomsTerms('DE', DutiesTerms::Ddu), itn: 'X20261008123456')],
    'nothing resolved' => [fn (): ShipRequest => fedexCustomsShipRequest(fedexCustomsGermany(), null)],
    'a registration too long for a TIN' => [fn (): ShipRequest => fedexCustomsShipRequest(
        fedexCustomsBrazil(),
        fedexCustomsTerms('BR', DutiesTerms::Ddu, new SellerTaxRegistration(TaxRegistrationRegime::Arn, '1234567890123456789', CustomsTermsOrigin::Order)),
    )],
    'a tax ID too long for a TIN' => [fn (): ShipRequest => fedexCustomsShipRequest(
        fedexCustomsBrazil(),
        fedexCustomsTerms('BR', DutiesTerms::Ddu),
        new RecipientTaxId(RecipientTaxIdType::Other, '1234567890123456789'),
    )],
]);

it('declares nothing for a request with no lines, which is refused before anything is sent', function (): void {
    Saloon::fake([]);
    $request = fedexCustomsShipRequest(fedexCustomsGermany(), fedexCustomsTerms('DE', DutiesTerms::Ddp), customsItems: []);

    $response = $this->adapter->createShipment($request);

    expect($response->success)->toBeFalse()
        ->and($this->adapter->declaredCustomsTerms($request)->isEmpty())->toBeTrue();
});

it('snapshots what a FedEx label declared, with the recipient tax ID by type only', function (): void {
    $request = fedexCustomsShipRequest(
        fedexCustomsBrazil(),
        fedexCustomsTerms('BR', DutiesTerms::Ddp, new SellerTaxRegistration(TaxRegistrationRegime::Arn, '123456789012', CustomsTermsOrigin::Client)),
        new RecipientTaxId(RecipientTaxIdType::Cpf, '12345678909'),
        'X20261008123456',
        '123456789',
    );

    $snapshot = app(CustomsTermsSnapshot::class)->forRequest($request, $this->adapter);

    expect($snapshot)->toMatchArray([
        'duties_terms' => 'ddp',
        'duties_terms_source' => 'order',
        'registration' => ['regime' => 'arn', 'number' => '123456789012', 'source' => 'client'],
        'recipient_tax_id' => ['type' => 'cpf'],
        'export_itn' => 'X20261008123456',
    ])->and(json_encode($snapshot))->not->toContain('12345678909');
});

it('scrubs the recipient tax ID and EIN from a FedEx error that echoes them', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'errors' => [['code' => 'TIN.INVALID', 'message' => 'Invalid tax ID 98765432100 for recipient; EIN 123456789 rejected']],
        ], 422),
    ]);
    $handler = new TestHandler;
    Log::extend('fedex-validation-scrub', fn (): MonologLogger => new MonologLogger('fedex-validation', [$handler]));
    config()->set('logging.channels.fedex-validation', ['driver' => 'fedex-validation-scrub']);
    Log::forgetChannel('fedex-validation');

    $response = $this->adapter->createShipment(fedexCustomsShipRequest(
        fedexCustomsBrazil(),
        fedexCustomsTerms('BR', DutiesTerms::Ddu),
        new RecipientTaxId(RecipientTaxIdType::Cpf, '98765432100'),
        'X20261008123456',
        '123456789',
    ));

    $logged = json_encode(collect($handler->getRecords())->filter(fn ($record): bool => $record->message !== 'LABEL REQUEST')->map(fn ($record): array => [$record->message, $record->context])->all());

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('Invalid tax ID [REDACTED]')
        ->and($response->errorMessage)->not->toContain('98765432100')
        ->and($response->errorMessage)->not->toContain('123456789')
        ->and($logged)->toContain('FedEx createShipment API error')
        ->and($logged)->not->toContain('98765432100')
        ->and($logged)->not->toContain('123456789');
});

it('keeps the tax IDs out of the log when a 5xx echoes them', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make(['errors' => [['code' => 'SYSTEM.UNAVAILABLE', 'message' => 'Failure for tax ID 98765432100 and EIN 123456789']]], 503),
    ]);
    $handler = new TestHandler;
    Log::extend('fedex-validation-scrub', fn (): MonologLogger => new MonologLogger('fedex-validation', [$handler]));
    config()->set('logging.channels.fedex-validation', ['driver' => 'fedex-validation-scrub']);
    Log::forgetChannel('fedex-validation');

    $response = $this->adapter->createShipment(fedexCustomsShipRequest(
        fedexCustomsBrazil(),
        fedexCustomsTerms('BR', DutiesTerms::Ddu),
        new RecipientTaxId(RecipientTaxIdType::Cpf, '98765432100'),
        'X20261008123456',
        '123456789',
    ));

    $logged = json_encode(collect($handler->getRecords())->filter(fn ($record): bool => $record->message !== 'LABEL REQUEST')->map(fn ($record): array => [$record->message, $record->context])->all());

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->not->toContain('98765432100')
        ->and($logged)->not->toContain('98765432100')
        ->and($logged)->not->toContain('123456789');
});

it('scrubs whole tokens only, so a number inside a longer identifier survives in a failure message', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'errors' => [['code' => 'TIN.INVALID', 'message' => 'EIN 030388962 rejected for reference 1Z14A6G90303889622']],
        ], 422),
    ]);

    $response = $this->adapter->createShipment(fedexCustomsShipRequest(
        fedexCustomsGermany(),
        fedexCustomsTerms('DE', DutiesTerms::Ddu),
        itn: 'X20261008123456',
        ein: '030388962',
    ));

    expect($response->errorMessage)->toContain('EIN [REDACTED] rejected')
        ->and($response->errorMessage)->toContain('1Z14A6G90303889622');
});

it('keeps the recipient tax ID out of the configured FedEx validation channel', function (): void {
    Log::forgetChannel('fedex-validation');
    $log = storage_path('logs/testing.log');
    $before = is_file($log) ? filesize($log) : 0;
    fakeFedexCustomsShipEndpoints();

    $this->adapter->createShipment(fedexCustomsShipRequest(
        fedexCustomsBrazil(),
        fedexCustomsTerms('BR', DutiesTerms::Ddu),
        new RecipientTaxId(RecipientTaxIdType::Cpf, '45678912300'),
    ));

    clearstatcache();
    $written = (string) file_get_contents($log, offset: $before);

    expect($written)->toContain('LABEL REQUEST')
        ->and($written)->toContain('customsClearanceDetail')
        ->and($written)->not->toContain('45678912300');
});

/**
 * Run $call against the configured fedex-validation channel (taps included,
 * writing to the testing log) and return what it wrote meanwhile.
 */
function fedexLogWrittenDuring(Closure $call): string
{
    Log::forgetChannel('fedex-validation');
    $log = storage_path('logs/testing.log');
    $before = is_file($log) ? filesize($log) : 0;

    $call();

    clearstatcache();

    return (string) file_get_contents($log, offset: $before);
}

function fedexBrazilRequestWithCpf(array $specialServiceCodes = []): ShipRequest
{
    $request = fedexCustomsShipRequest(
        fedexCustomsBrazil(),
        fedexCustomsTerms('BR', DutiesTerms::Ddu),
        new RecipientTaxId(RecipientTaxIdType::Cpf, '98765432100'),
        'X20261008123456',
        '123456789',
    );

    return new ShipRequest(
        fromAddress: $request->fromAddress,
        toAddress: $request->toAddress,
        packageData: $request->packageData,
        selectedRate: $request->selectedRate,
        customsItems: $request->customsItems,
        specialServiceCodes: $specialServiceCodes,
        shipDate: $request->shipDate,
        exportItn: $request->exportItn,
        customsTerms: $request->customsTerms,
        recipientTaxId: $request->recipientTaxId,
        exporterEin: $request->exporterEin,
    );
}

it('keeps the tax ID out of the Saturday-retry log line, even when the retry succeeds', function (): void {
    $calls = 0;
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => function () use (&$calls): MockResponse {
            $calls++;

            return $calls === 1
                ? MockResponse::make(['errors' => [['code' => 'SHIPMENT.SATURDAY.NOTALLOWED', 'message' => 'Saturday delivery not allowed; invalid TIN 98765432100 and EIN 123456789', 'parameterList' => [['key' => 'tin', 'value' => '98765432100']]]]], 422)
                : MockResponse::make(['output' => ['transactionShipments' => [[
                    'masterTrackingNumber' => '794644790138',
                    'pieceResponses' => [['trackingNumber' => '794644790138', 'packageDocuments' => [['encodedLabel' => 'JVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh']]]],
                ]]]]);
        },
    ]);

    $response = null;
    $written = fedexLogWrittenDuring(function () use (&$response): void {
        $response = $this->adapter->createShipment(fedexBrazilRequestWithCpf(['saturday_delivery']));
    });

    expect($calls)->toBe(2)
        ->and($response->success)->toBeTrue()
        ->and($written)->toContain('retrying without')
        ->and($written)->not->toContain('98765432100')
        ->and($written)->not->toContain('123456789');
});

it('keeps the tax ID out of the LABEL RESPONSE log, and parses the reply untouched', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make(['output' => [
            // The EIN is a substring of the tracking number: a whole-token
            // scrub leaves it, and nothing parsed is scrubbed anyway.
            'alerts' => [['code' => 'TIN.WARNING', 'message' => 'Check tax ID 98765432100 for the recipient', 'parameterList' => [['key' => 'tin', 'value' => '98765432100']]]],
            'transactionShipments' => [[
                'masterTrackingNumber' => '794644790138',
                'pieceResponses' => [['trackingNumber' => '794644790138', 'packageDocuments' => [['encodedLabel' => 'JVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh']]]],
            ]],
        ]]),
    ]);

    $response = null;
    $written = fedexLogWrittenDuring(function () use (&$response): void {
        $response = $this->adapter->createShipment(fedexBrazilRequestWithCpf());
    });

    expect($response->success)->toBeTrue()
        ->and($response->trackingNumber)->toBe('794644790138')
        ->and($response->labelData)->toBe('JVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh')
        ->and($written)->toContain('LABEL RESPONSE')
        ->and($written)->not->toContain('98765432100');
});

it('keeps the tax ID out of the missing-data logs', function (array $output, string $log, string $failure): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make(['output' => $output]),
    ]);

    $response = null;
    $written = fedexLogWrittenDuring(function () use (&$response): void {
        $response = $this->adapter->createShipment(fedexBrazilRequestWithCpf());
    });

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toBe($failure)
        ->and($written)->toContain($log)
        ->and($written)->not->toContain('98765432100');
})->with([
    'no shipment' => [
        ['alerts' => [['code' => 'X', 'message' => 'tax ID 98765432100 unreadable']]],
        'FedEx createShipment missing shipment data',
        'FedEx response missing shipment data',
    ],
    'no tracking number' => [
        ['transactionShipments' => [['serviceName' => 'tax ID 98765432100', 'pieceResponses' => []]]],
        'FedEx createShipment missing tracking number',
        'FedEx response missing tracking number',
    ],
    'no label' => [
        ['transactionShipments' => [['masterTrackingNumber' => '794644790138', 'pieceResponses' => [['trackingNumber' => '794644790138', 'packageDocuments' => [['note' => 'tax ID 98765432100']]]]]]],
        'FedEx createShipment missing label data',
        'FedEx response missing label data',
    ],
]);
