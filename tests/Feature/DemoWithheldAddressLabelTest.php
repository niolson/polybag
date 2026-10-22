<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Http\Integrations\Fedex\Requests\CreateShipment as FedexCreateShipment;
use App\Http\Integrations\Fedex\Requests\Rates as FedexRates;
use App\Http\Integrations\Ups\Requests\CreateShipment as UpsCreateShipment;
use App\Http\Integrations\USPS\Requests\Label;
use App\Http\Integrations\USPS\Requests\PaymentAuthorization;
use App\Models\Shipment;
use App\Services\Carriers\FedexAdapter;
use App\Services\Carriers\UpsAdapter;
use App\Services\Carriers\UspsAdapter;
use App\Services\SettingsService;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * `live-store-demo/03`: a Demo import keeps no street, so its Shipment reads
 * "Address withheld". Each direct carrier's sandbox prints a Label for it:
 * USPS when told to ignore the bad address, UPS because it never validates,
 * and FedEx as Home Delivery to a residence.
 */
beforeEach(function (): void {
    $this->app['env'] = 'demo';
});

function withheldShipment(): Shipment
{
    return Shipment::factory()->create([
        'first_name' => null,
        'last_name' => null,
        'company' => null,
        'address1' => Shipment::WITHHELD_ADDRESS_LINE,
        'address2' => null,
        'city' => 'Lemont',
        'state_or_province' => 'IL',
        'postal_code' => '60439',
        'country' => 'US',
        'phone' => null,
        'phone_e164' => null,
        'email' => null,
        'residential' => null,
        ...Shipment::UNVALIDATED,
    ]);
}

function withheldShipRequest(AddressData $to, string $carrier, string $serviceCode, array $metadata = []): ShipRequest
{
    return new ShipRequest(
        fromAddress: new AddressData(firstName: 'Shipping', lastName: 'Center', streetAddress: '123 Warehouse St', city: 'Seattle', stateOrProvince: 'WA', postalCode: '98072', phone: '2065550100'),
        toAddress: $to,
        packageData: new PackageData(weight: 2.0, length: 10, width: 8, height: 4),
        selectedRate: new RateResponse(carrier: $carrier, serviceCode: $serviceCode, serviceName: $serviceCode, price: 9.5, metadata: $metadata),
    );
}

function fakeWithheldUspsLabel(): void
{
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        Label::class => MockResponse::make(
            body: "--boundary\r\nContent-Type: application/json\r\n\r\n{\"trackingNumber\":\"9234690380793700051765\",\"postage\":8.50}\r\n--boundary\r\nContent-Type: application/pdf\r\n\r\nJVBERi0xLjQK\r\n--boundary--",
            headers: ['Content-Type' => 'multipart/mixed; boundary=boundary'],
        ),
    ]);
}

/**
 * @return array<string, mixed>
 */
function sentWithheldUspsToAddress(): array
{
    $toAddress = null;

    Saloon::assertSent(function ($request) use (&$toAddress): bool {
        if (! $request instanceof Label) {
            return false;
        }

        assertMatchesUspsSchema($request->body()->all(), 'LabelRequest');
        $toAddress = $request->body()->all()['toAddress'];

        return true;
    });

    return $toAddress;
}

const USPS_GROUND_ADVANTAGE_METADATA = [
    'mailClass' => 'USPS_GROUND_ADVANTAGE',
    'processingCategory' => 'MACHINABLE',
    'rateIndicator' => 'SP',
];

it('asks the USPS sandbox to ignore a withheld address, and names no one', function (): void {
    createUspsAccount();
    app(SettingsService::class)->set('sandbox_mode', true);
    fakeWithheldUspsLabel();

    $response = (new UspsAdapter)->createShipment(withheldShipRequest(
        AddressData::fromShipment(withheldShipment()), 'USPS', 'USPS_GROUND_ADVANTAGE', USPS_GROUND_ADVANTAGE_METADATA,
    ));

    expect($response->success)->toBeTrue()
        ->and(sentWithheldUspsToAddress())->toMatchArray([
            'streetAddress' => 'Address withheld',
            'city' => 'Lemont',
            'state' => 'IL',
            'ZIPCode' => '60439',
            'firstName' => 'Name',
            'lastName' => 'withheld',
            'ignoreBadAddress' => true,
        ]);
});

it('never asks USPS to ignore a bad address in production, or a real one in sandbox', function (string $environment, bool $withheld): void {
    $this->app['env'] = $environment;
    createUspsAccount();
    app(SettingsService::class)->set('sandbox_mode', true);
    fakeWithheldUspsLabel();

    $shipment = withheldShipment();
    $shipment->forceFill(['first_name' => 'Jane', 'last_name' => 'Smith'])->save();

    $to = $withheld
        ? AddressData::fromShipment($shipment)
        : new AddressData(firstName: 'Jane', lastName: 'Smith', streetAddress: '456 Oak Ave', city: 'Lemont', stateOrProvince: 'IL', postalCode: '60439');

    (new UspsAdapter)->createShipment(withheldShipRequest($to, 'USPS', 'USPS_GROUND_ADVANTAGE', USPS_GROUND_ADVANTAGE_METADATA));

    expect(sentWithheldUspsToAddress())->not->toHaveKey('ignoreBadAddress');
})->with([
    'production, the withheld text typed' => ['production', true],
    'sandbox, a real street' => ['testing', false],
]);

it('sends UPS a withheld address with no address validation', function (): void {
    createUpsAccount();
    app(SettingsService::class)->set('sandbox_mode', true);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        UpsCreateShipment::class => MockResponse::make(['ShipmentResponse' => ['ShipmentResults' => [
            'ShipmentIdentificationNumber' => '1ZXXXXXXXXXXXXXXXX',
            'ShipmentCharges' => ['TotalCharges' => ['MonetaryValue' => '11.00']],
            'PackageResults' => ['TrackingNumber' => '1ZXXXXXXXXXXXXXXXX', 'ShippingLabel' => ['GraphicImage' => 'R0lGODlhAQABAAAAACw=']],
        ]]]),
    ]);

    $response = (new UpsAdapter)->createShipment(withheldShipRequest(
        AddressData::fromShipment(withheldShipment()), 'UPS', '03', ['serviceCode' => '03'],
    ));

    expect($response->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof UpsCreateShipment) {
            return false;
        }

        $body = $request->body()->all()['ShipmentRequest'];

        return $body['Request']['RequestOption'] === 'nonvalidate'
            && $body['Shipment']['ShipTo']['Name'] === 'Name withheld'
            && $body['Shipment']['ShipTo']['Address']['AddressLine'] === ['Address withheld']
            && array_key_exists('ResidentialAddressIndicator', $body['Shipment']['ShipTo']['Address']);
    });
});

it('rates and buys a withheld address from the FedEx sandbox as Home Delivery to a residence', function (): void {
    createFedexAccount();
    app(SettingsService::class)->set('sandbox_mode', true);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexRates::class => MockResponse::make(['output' => ['rateReplyDetails' => [[
            'serviceType' => 'FEDEX_GROUND',
            'serviceName' => 'FedEx Ground®',
            'ratedShipmentDetails' => [['totalNetCharge' => 19.21]],
        ]]]]),
        FedexCreateShipment::class => MockResponse::make(['output' => ['transactionShipments' => [[
            'masterTrackingNumber' => '794895160816',
            'completedShipmentDetail' => ['shipmentRating' => ['shipmentRateDetails' => [['totalNetCharge' => 19.21]]]],
            'pieceResponses' => [['trackingNumber' => '794895160816', 'packageDocuments' => [['encodedLabel' => 'JVBERi0xLjQK']]]],
        ]]]]),
    ]);

    $shipment = withheldShipment();
    // An order's own classification is withheld with the rest, so even one
    // stored as commercial reaches FedEx as a residence.
    $shipment->forceFill(['residential' => false])->save();
    $to = AddressData::fromShipment($shipment->fresh());

    $rate = (new FedexAdapter)->getRates(new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: $to->postalCode,
        residential: $to->isResidential(),
        packages: [new PackageData(weight: 2.0, length: 10, width: 8, height: 4)],
    ), ['FEDEX_GROUND', 'GROUND_HOME_DELIVERY'])->first();

    expect($rate->serviceCode)->toBe('GROUND_HOME_DELIVERY');

    $response = (new FedexAdapter)->createShipment(withheldShipRequest($to, 'FedEx', $rate->serviceCode, $rate->metadata));

    expect($response->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof FedexCreateShipment) {
            return false;
        }

        $shipment = $request->body()->all()['requestedShipment'];
        $recipient = $shipment['recipients'][0];

        return $shipment['serviceType'] === 'GROUND_HOME_DELIVERY'
            && $recipient['address']['residential'] === true
            && $recipient['address']['streetLines'] === ['Address withheld']
            && $recipient['contact']['personName'] === 'Name withheld';
    });
});
