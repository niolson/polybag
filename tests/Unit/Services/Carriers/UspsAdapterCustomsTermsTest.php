<?php

use App\DataTransferObjects\Customs\RecipientTaxId;
use App\DataTransferObjects\Customs\ResolvedCustomsTerms;
use App\DataTransferObjects\Customs\SellerTaxRegistration;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\CustomsTermsOrigin;
use App\Enums\DutiesTerms;
use App\Enums\RecipientTaxIdType;
use App\Enums\TaxRegistrationRegime;
use App\Http\Integrations\USPS\Requests\InternationalLabel;
use App\Http\Integrations\USPS\Requests\PaymentAuthorization;
use App\Logging\PiiRedactor;
use App\Models\CarrierAccount;
use App\Models\User;
use App\Services\Carriers\UspsAdapter;
use App\Services\Customs\CustomsTermsSnapshot;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;

/**
 * `international-customs-terms/08`: USPS prepays duties on DDP, declares the
 * seller registration and the recipient tax ID in the customs form's
 * references, and never sends a registration together with the prepayment.
 */
beforeEach(function (): void {
    $this->adapter = new UspsAdapter;
    $this->account = createUspsAccount();
    $this->account->recordDdpTermsAcceptance(User::factory()->create());
});

function uspsTermsAddress(string $country = 'DE', ?string $company = null): AddressData
{
    return new AddressData(firstName: 'Anna', lastName: 'Schmidt', streetAddress: 'Hauptstrasse 1', city: 'Berlin', stateOrProvince: null, postalCode: '10115', country: $country, company: $company);
}

function uspsTermsFor(string $country, ?DutiesTerms $term, ?SellerTaxRegistration $registration = null): ResolvedCustomsTerms
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

function uspsIoss(string $number = 'IM2760000742'): SellerTaxRegistration
{
    return new SellerTaxRegistration(TaxRegistrationRegime::Ioss, $number, CustomsTermsOrigin::Client);
}

function uspsTermsRequest(?ResolvedCustomsTerms $terms, ?AddressData $to = null, ?RecipientTaxId $taxId = null, ?string $itn = null): ShipRequest
{
    return new ShipRequest(
        fromAddress: new AddressData(firstName: 'Shipping', lastName: 'Center', streetAddress: '123 Warehouse St', city: 'Seattle', stateOrProvince: 'WA', postalCode: '98072'),
        toAddress: $to ?? uspsTermsAddress(),
        packageData: new PackageData(weight: 2.5, length: 10, width: 8, height: 6),
        selectedRate: new RateResponse(
            carrier: 'USPS',
            serviceCode: 'PRIORITY_MAIL_INTERNATIONAL',
            serviceName: 'Priority Mail International',
            price: 42.50,
            metadata: ['mailClass' => 'PRIORITY_MAIL_INTERNATIONAL', 'processingCategory' => 'MACHINABLE', 'rateIndicator' => 'SP'],
        ),
        customsItems: [new CustomsItem(description: 'Blue Widget', quantity: 2, unitValue: 19.99, weight: 0.5, countryOfOrigin: 'US')],
        exportItn: $itn,
        customsTerms: $terms,
        recipientTaxId: $taxId,
    );
}

/**
 * @param  array<string, mixed>  $metadata  what the label's JSON part adds to the tracking number and postage
 */
function fakeUspsTermsLabel(array $metadata = []): void
{
    $json = json_encode(['trackingNumber' => 'LN123456789US', 'internationalTrackingNumber' => 'LN123456789US', 'postage' => 42.50, ...$metadata]);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        InternationalLabel::class => MockResponse::make(
            body: "--boundary\r\nContent-Type: application/json\r\n\r\n{$json}\r\n--boundary\r\nContent-Type: application/pdf\r\n\r\nJVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh\r\n--boundary--",
            headers: ['Content-Type' => 'multipart/mixed; boundary=boundary'],
        ),
    ]);
}

/**
 * @return array<string, mixed> the international label body USPS was sent
 */
function sentUspsTermsBody(): array
{
    $body = null;

    Saloon::assertSent(function (Request $sent) use (&$body): bool {
        if (! $sent instanceof InternationalLabel) {
            return false;
        }

        $body = $sent->body()->all();
        assertMatchesUspsSchema($body, 'InternationalLabelRequest');

        return true;
    });

    return $body;
}

it('asks USPS to prepay duties on a DDP label and stores the prepaid total', function (): void {
    fakeUspsTermsLabel(['prepaidDutiesTaxesFees' => [
        'packageFee' => 2.5,
        'itemCosts' => [
            ['dutyPrice' => 5.0, 'taxPrice' => 0.0, 'itemDescription' => 'Hats'],
            ['dutyPrice' => 0.0, 'taxPrice' => 10.25, 'itemDescription' => 'Shoes'],
        ],
    ]]);

    $response = $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('DE', DutiesTerms::Ddp)));

    expect($response->success)->toBeTrue()
        ->and($response->cost)->toBe(42.5)
        ->and($response->dutiesCost)->toBe(17.75);

    $body = sentUspsTermsBody();

    expect($body['packageDescription']['prepayDutiesTaxesFees'])->toBeTrue()
        ->and($body['customsForm'])->not->toHaveKey('exportersReference');
});

it('does not send the prepayment flag on a DDU label and stores no duties cost', function (): void {
    fakeUspsTermsLabel();

    $response = $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('PL', DutiesTerms::Ddu)));

    expect($response->success)->toBeTrue()
        ->and($response->dutiesCost)->toBeNull()
        ->and(sentUspsTermsBody()['packageDescription'])->not->toHaveKey('prepayDutiesTaxesFees');
});

it('sends no flag when the request resolved no terms', function (): void {
    fakeUspsTermsLabel();

    $this->adapter->createShipment(uspsTermsRequest(null));

    expect(sentUspsTermsBody()['packageDescription'])->not->toHaveKey('prepayDutiesTaxesFees');
});

it('stores a null duties cost when a DDP label comes back without prepaid duties', function (): void {
    fakeUspsTermsLabel();

    $response = $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('DE', DutiesTerms::Ddp)));

    expect($response->success)->toBeTrue()
        ->and($response->dutiesCost)->toBeNull();
});

it('sums the package fee alone when no item carries a cost', function (): void {
    fakeUspsTermsLabel(['prepaidDutiesTaxesFees' => ['packageFee' => 1.25]]);

    $response = $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('DE', DutiesTerms::Ddp)));

    expect($response->dutiesCost)->toBe(1.25);
});

it('declares a registration as the exporter reference on a DDU label', function (): void {
    fakeUspsTermsLabel();

    $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('PL', DutiesTerms::Ddu, uspsIoss())));

    $body = sentUspsTermsBody();

    expect($body['customsForm']['exportersReference'])->toBe(['referenceType' => 'VAT_NUMBER', 'reference' => 'IM2760000742'])
        ->and($body['packageDescription'])->not->toHaveKey('prepayDutiesTaxesFees');
});

it('declares any registration regime as a VAT number', function (TaxRegistrationRegime $regime): void {
    fakeUspsTermsLabel();

    $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('NO', DutiesTerms::Ddu, new SellerTaxRegistration($regime, '123456789', CustomsTermsOrigin::Order))));

    expect(sentUspsTermsBody()['customsForm']['exportersReference']['referenceType'])->toBe('VAT_NUMBER');
})->with([TaxRegistrationRegime::Ioss, TaxRegistrationRegime::UkVat, TaxRegistrationRegime::Voec, TaxRegistrationRegime::Arn]);

it('refuses a registration with DDP before any request reaches USPS', function (): void {
    fakeUspsTermsLabel();

    $response = $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('DE', DutiesTerms::Ddp, uspsIoss())));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('exporter reference')
        ->and($response->errorMessage)->toContain('Nothing was bought')
        ->and($response->errorMessage)->toContain('Germany');

    Saloon::assertNothingSent();
});

it('refuses a registration with DDP to Northern Ireland, which the country table cannot express', function (): void {
    fakeUspsTermsLabel();

    $to = new AddressData(firstName: 'Aoife', lastName: 'Byrne', streetAddress: '1 High St', city: 'Belfast', stateOrProvince: 'NIR', postalCode: 'BT1 1AA', country: 'GB');
    $response = $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('GB', DutiesTerms::Ddp, uspsIoss()), $to));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('exporter reference');

    Saloon::assertNothingSent();
});

it('sends DDP without any exporter reference when there is no registration', function (): void {
    fakeUspsTermsLabel();

    $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('DE', DutiesTerms::Ddp)));

    expect(sentUspsTermsBody()['customsForm'])->not->toHaveKey('exportersReference');
});

it('buys DDP when a registration is too long to send, because none is declared', function (): void {
    fakeUspsTermsLabel();

    $response = $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('DE', DutiesTerms::Ddp, uspsIoss(str_repeat('9', 29)))));

    expect($response->success)->toBeTrue()
        ->and($this->adapter->declaredCustomsTerms(uspsTermsRequest(uspsTermsFor('DE', DutiesTerms::Ddp, uspsIoss(str_repeat('9', 29)))))->registration)->toBeNull();

    $body = sentUspsTermsBody();

    expect($body['packageDescription']['prepayDutiesTaxesFees'])->toBeTrue()
        ->and($body['customsForm'])->not->toHaveKey('exportersReference');
});

it('sends a Brazilian CPF as the importer reference without disturbing DDP', function (): void {
    fakeUspsTermsLabel();

    $this->adapter->createShipment(uspsTermsRequest(
        uspsTermsFor('BR', DutiesTerms::Ddp),
        uspsTermsAddress('BR'),
        new RecipientTaxId(RecipientTaxIdType::Cpf, '12345678909'),
    ));

    $body = sentUspsTermsBody();

    expect($body['customsForm']['importersReference'])->toBe(['referenceType' => 'TAX_CODE', 'reference' => '12345678909'])
        ->and($body['packageDescription']['prepayDutiesTaxesFees'])->toBeTrue();
});

it('sends a recipient VAT number as a VAT number and leaves out one too long for the field', function (): void {
    fakeUspsTermsLabel();
    $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('DE', DutiesTerms::Ddu), null, new RecipientTaxId(RecipientTaxIdType::Vat, 'DE123456789')));

    expect(sentUspsTermsBody()['customsForm']['importersReference'])->toBe(['referenceType' => 'VAT_NUMBER', 'reference' => 'DE123456789']);

    fakeUspsTermsLabel();
    $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('DE', DutiesTerms::Ddu), null, new RecipientTaxId(RecipientTaxIdType::Other, str_repeat('7', 29))));

    expect(sentUspsTermsBody()['customsForm'])->not->toHaveKey('importersReference');
});

it('refuses a DDP label on an account that has not accepted the terms, before any request', function (): void {
    CarrierAccount::query()->update(['ddp_terms_accepted_at' => null, 'ddp_terms_accepted_by' => null]);
    fakeUspsTermsLabel();

    $response = $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('DE', DutiesTerms::Ddp)));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('USPS DDP: account terms not accepted');

    Saloon::assertNothingSent();
});

it('still buys a DDU label on an account that has not accepted the terms', function (): void {
    CarrierAccount::query()->update(['ddp_terms_accepted_at' => null, 'ddp_terms_accepted_by' => null]);
    fakeUspsTermsLabel();

    expect($this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('PL', DutiesTerms::Ddu)))->success)->toBeTrue();
});

it('reports USPS refusing to prepay as a decline naming the destination', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        InternationalLabel::class => MockResponse::make([
            'apiVersion' => '3.3.11',
            'error' => [
                'code' => '400',
                'message' => 'Bad Request',
                'errors' => [['code' => '030031', 'detail' => 'DDP is not available for the provided country and product options.']],
            ],
        ], 400),
    ]);

    $response = $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('DE', DutiesTerms::Ddp)));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('cannot prepay duties and taxes to Germany')
        ->and($response->errorMessage)->toContain('Nothing was bought');
});

it('leaves other USPS errors on a DDP label to the ordinary description', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        InternationalLabel::class => MockResponse::make([
            'error' => ['code' => '400', 'message' => 'Bad Request', 'errors' => [['code' => '160138', 'detail' => 'ZIP out of service']]],
        ], 400),
    ]);

    $response = $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('DE', DutiesTerms::Ddp)));

    expect($response->errorMessage)->toContain('no longer in service');
});

it('reports what it sent so the snapshot records the wire, never the recipient tax ID', function (): void {
    $request = uspsTermsRequest(
        uspsTermsFor('BR', DutiesTerms::Ddu, new SellerTaxRegistration(TaxRegistrationRegime::Ioss, 'IM2760000742', CustomsTermsOrigin::Client)),
        uspsTermsAddress('BR'),
        new RecipientTaxId(RecipientTaxIdType::Cpf, '12345678909'),
        'X20261008123456',
    );

    $declared = $this->adapter->declaredCustomsTerms($request);
    $snapshot = app(CustomsTermsSnapshot::class)->forRequest($request, $this->adapter);

    expect($declared->dutiesTerms)->toBe(DutiesTerms::Ddu)
        ->and($declared->registration?->number)->toBe('IM2760000742')
        ->and($declared->recipientTaxIdType)->toBe(RecipientTaxIdType::Cpf)
        ->and($declared->exportItn)->toBe('X20261008123456')
        ->and($snapshot)->toMatchArray([
            'duties_terms' => 'ddu',
            'duties_terms_source' => 'order',
            'registration' => ['regime' => 'ioss', 'number' => 'IM2760000742', 'source' => 'client'],
            'recipient_tax_id' => ['type' => 'cpf'],
            'export_itn' => 'X20261008123456',
        ])
        ->and(json_encode($snapshot))->not->toContain('12345678909');
});

it('declares nothing for a domestic label, and no ITN when an exemption was sent', function (): void {
    $domestic = uspsTermsRequest(uspsTermsFor('US', DutiesTerms::Ddu), new AddressData(firstName: 'John', lastName: 'Doe', streetAddress: '456 Main St', city: 'Los Angeles', stateOrProvince: 'CA', postalCode: '90210'));
    $withoutItn = $this->adapter->declaredCustomsTerms(uspsTermsRequest(uspsTermsFor('DE', DutiesTerms::Ddp)));

    expect($this->adapter->declaredCustomsTerms($domestic)->isEmpty())->toBeTrue()
        ->and($withoutItn->dutiesTerms)->toBe(DutiesTerms::Ddp)
        ->and($withoutItn->exportItn)->toBeNull();
});

it('redacts the importer and exporter references from a logged payload', function (): void {
    $redacted = PiiRedactor::redact([
        'customsForm' => [
            'importersReference' => ['referenceType' => 'TAX_CODE', 'reference' => '12345678909'],
            'exportersReference' => ['referenceType' => 'VAT_NUMBER', 'reference' => 'IM2760000742'],
            'customsContentType' => 'MERCHANDISE',
        ],
    ]);

    expect($redacted)->toBe([
        'customsForm' => [
            'importersReference' => '[REDACTED]',
            'exportersReference' => '[REDACTED]',
            'customsContentType' => 'MERCHANDISE',
        ],
    ]);
});

it('refuses DDP and drops the rate once the CRID changes after acceptance', function (): void {
    $this->account->update(['credentials' => [...$this->account->fresh()->credentials, 'crid' => '11111111']]);
    fakeUspsTermsLabel();

    $response = $this->adapter->createShipment(uspsTermsRequest(uspsTermsFor('DE', DutiesTerms::Ddp)));

    expect($this->account->fresh()->hasAcceptedDdpTerms())->toBeFalse()
        ->and($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('USPS DDP: account terms not accepted');

    Saloon::assertNothingSent();
});
