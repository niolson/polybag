<?php

namespace App\Services\Carriers;

use App\Http\Integrations\Amazon\Requests\CancelAmazonShipment;
use App\Http\Integrations\Amazon\Requests\ConfirmShipment;
use App\Http\Integrations\Amazon\Requests\GetAdditionalInputsSchema;
use App\Http\Integrations\Amazon\Requests\GetMarketplaceParticipations;
use App\Http\Integrations\Amazon\Requests\GetShipmentDocuments;
use App\Http\Integrations\Amazon\Requests\GetShipmentTracking;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Http\Integrations\Amazon\Requests\PurchaseShipment;
use App\Http\Integrations\Amazon\Requests\SearchCatalogItems;
use App\Http\Integrations\Amazon\Requests\SearchListingsItems;
use App\Http\Integrations\Amazon\Requests\SearchOrders;
use App\Models\DataSource;
use App\Services\Carriers\Samples\SampleCanvas;
use App\Services\Carriers\Samples\SamplePdfWriter;
use App\Services\Carriers\Samples\SampleZplWriter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

/**
 * An Amazon seller account that exists only on this install, for demos and
 * screenshots: Amazon has no test store, and its sandbox answers every rate
 * request with the same single offer.
 *
 * Only a connection marked as a fixture ({@see self::SETTING}) is answered
 * here, and only while `FAKE_CARRIERS` is on. A real connection is never
 * faked, and a fixture connection never reaches Amazon: with the flag off its
 * connector refuses to send at all, so its placeholder credentials cannot.
 *
 * Every response is shaped like a captured production one, so everything after
 * the connector — order mapping, offers, benefits, purchase, Pack and Ship —
 * runs as it does against Amazon:
 *
 * - orders are read from a file loaded by `demo:amazon-fixture`;
 * - rates are three Buy Shipping offers under real, mapped service IDs. Two
 *   are OTDR-protected; the cheapest is not, for late-delivery risk, and
 *   promises a later delivery, as Amazon's own exclusions do;
 * - labels are {@see SampleCanvas} pages: SAMPLE banners, tracking as text,
 *   no barcode.
 */
class FakeAmazonSpApi
{
    /** The connection setting that marks a fixture connection. */
    public const SETTING = 'fixture';

    /** Where a fixture connection's orders live on the local disk. */
    private const ORDERS_PATH = 'amazon-fixture/orders-%d.json';

    /**
     * The offers every rate request gets, as `carrierId:serviceId` pairs that
     * `AmazonServiceMappingSeeder` maps to catalog services.
     *
     * @var list<array{carrierId: string, carrierName: string, serviceId: string, serviceName: string, price: float, days: int, protected: bool}>
     */
    private const OFFERS = [
        ['carrierId' => 'AMZN_US', 'carrierName' => 'Amazon Shipping', 'serviceId' => 'std-us-swa-mfn', 'serviceName' => 'Amazon Shipping Ground', 'price' => 8.95, 'days' => 3, 'protected' => true],
        ['carrierId' => 'UPS', 'carrierName' => 'UPS', 'serviceId' => 'UPS_PTP_GND', 'serviceName' => 'UPS® Ground', 'price' => 10.40, 'days' => 3, 'protected' => true],
        ['carrierId' => 'USPS', 'carrierName' => 'USPS', 'serviceId' => 'USPS_PTP_GAL', 'serviceName' => 'USPS Ground Advantage (less than 1lb)', 'price' => 7.95, 'days' => 6, 'protected' => false],
    ];

    /**
     * @param  array<string, mixed>  $settings  A connection's merged settings
     */
    public static function isFixture(array $settings): bool
    {
        return (bool) ($settings[self::SETTING] ?? false);
    }

    /**
     * The mock client a fixture connection's connector sends through.
     *
     * @throws RuntimeException when fake carriers are off
     */
    public static function mockClientFor(?int $dataSourceId): MockClient
    {
        if (! config('app.fake_carriers')) {
            throw new RuntimeException('This Amazon connection is a demo fixture. It answers only while FAKE_CARRIERS is on, and is never sent to Amazon.');
        }

        $fake = new self;

        return new MockClient([
            SearchOrders::class => fn (): MockResponse => MockResponse::make(['orders' => $fake->orders($dataSourceId)]),
            GetShippingRates::class => fn (PendingRequest $pending): MockResponse => $fake->rates($pending),
            PurchaseShipment::class => fn (PendingRequest $pending): MockResponse => $fake->purchase($pending),
            GetShipmentDocuments::class => fn (PendingRequest $pending): MockResponse => $fake->documents($pending),
            GetShipmentTracking::class => fn (PendingRequest $pending): MockResponse => $fake->tracking($pending),
            CancelAmazonShipment::class => MockResponse::make(['payload' => []]),
            ConfirmShipment::class => MockResponse::make([], 204),
            GetAdditionalInputsSchema::class => MockResponse::make(['payload' => []]),
            SearchCatalogItems::class => MockResponse::make(['numberOfResults' => 0, 'items' => []]),
            SearchListingsItems::class => MockResponse::make(['numberOfResults' => 0, 'items' => []]),
            GetMarketplaceParticipations::class => MockResponse::make(['payload' => [[
                'marketplace' => ['id' => 'ATVPDKIKX0DER', 'countryCode' => 'US', 'name' => 'Amazon.com', 'defaultCurrencyCode' => 'USD', 'defaultLanguageCode' => 'en_US', 'domainName' => 'www.amazon.com'],
                'participation' => ['isParticipating' => true, 'hasSuspendedListings' => false],
            ]]]),
        ]);
    }

    /**
     * Replace a fixture connection's orders.
     *
     * @param  list<array<string, mixed>>  $orders  Orders v2026-01-01 `Order` objects
     */
    public static function storeOrders(DataSource $dataSource, array $orders): void
    {
        Storage::disk('local')->put(sprintf(self::ORDERS_PATH, $dataSource->id), json_encode($orders, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function orders(?int $dataSourceId): array
    {
        $path = sprintf(self::ORDERS_PATH, (int) $dataSourceId);

        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        return json_decode((string) Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function rates(PendingRequest $pending): MockResponse
    {
        $shipDate = now()->startOfDay();

        return MockResponse::make(['payload' => [
            'requestToken' => 'amzn1.rq.fixture.'.Str::lower(Str::random(16)),
            'rates' => array_map(fn (array $offer): array => [
                'rateId' => 'fixture.'.$offer['carrierId'].'.'.$offer['serviceId'].'.'.Str::lower(Str::random(12)),
                'carrierId' => $offer['carrierId'],
                'carrierName' => $offer['carrierName'],
                'serviceId' => $offer['serviceId'],
                'serviceName' => $offer['serviceName'],
                'totalCharge' => ['value' => $offer['price'], 'unit' => 'USD'],
                'promise' => [
                    'deliveryWindow' => [
                        'start' => $shipDate->copy()->addWeekdays($offer['days'])->setTime(15, 0)->toIso8601ZuluString(),
                        'end' => $shipDate->copy()->addWeekdays($offer['days'])->setTime(23, 59, 59)->toIso8601ZuluString(),
                    ],
                ],
                'supportedDocumentSpecifications' => [
                    $this->documentSpecification('PDF', []),
                    $this->documentSpecification('ZPL', [203, 300]),
                ],
                'availableValueAddedServiceGroups' => [],
                'requiresAdditionalInputs' => false,
                'rateItemList' => [[
                    'rateItemID' => 'BASE_RATE',
                    'rateItemType' => 'MANDATORY',
                    'rateItemCharge' => ['value' => $offer['price'], 'unit' => 'USD'],
                    'rateItemNameLocalization' => 'Base Rate',
                ]],
                'benefits' => $offer['protected']
                    ? ['includedBenefits' => ['OTDR_PROTECTED', 'CLAIMS_PROTECTED'], 'excludedBenefits' => []]
                    : ['includedBenefits' => [], 'excludedBenefits' => [
                        ['benefit' => 'CLAIMS_PROTECTED', 'reasonCodes' => ['LATE_DELIVERY_RISK']],
                        ['benefit' => 'OTDR_PROTECTED', 'reasonCodes' => ['LATE_DELIVERY_RISK']],
                    ]],
            ], self::OFFERS),
            'ineligibleRates' => [],
        ]]);
    }

    private function purchase(PendingRequest $pending): MockResponse
    {
        /** @var array<string, mixed> $body */
        $body = $pending->body()?->all() ?? [];
        $offer = $this->offerForRateId((string) ($body['rateId'] ?? ''));
        $specification = $body['requestedDocumentSpecification'] ?? [];
        $trackingId = $this->trackingIdFor($offer['carrierId']);
        $shipmentId = 'amzn1.sid.fixture.'.Str::lower(Str::random(16));

        return MockResponse::make(['payload' => [
            'shipmentId' => $shipmentId,
            'packageDocumentDetails' => [$this->packageDocumentDetail($offer, $trackingId, $specification)],
            'promise' => [
                'deliveryWindow' => ['start' => now()->addWeekdays($offer['days'])->toIso8601ZuluString(), 'end' => now()->addWeekdays($offer['days'])->toIso8601ZuluString()],
                'pickupWindow' => ['start' => now()->toIso8601ZuluString(), 'end' => now()->addHours(8)->toIso8601ZuluString()],
            ],
            'totalCharge' => ['value' => $offer['price'], 'unit' => 'USD'],
        ]]);
    }

    /**
     * The documents of a shipment bought here. The offer is not recorded, so
     * a reprint carries the first offer's carrier; the page is a sample either way.
     */
    private function documents(PendingRequest $pending): MockResponse
    {
        $query = $pending->query()->all();

        return MockResponse::make(['payload' => [
            'shipmentId' => 'amzn1.sid.fixture.reprint',
            'packageDocumentDetail' => $this->packageDocumentDetail(
                self::OFFERS[0],
                $this->trackingIdFor(self::OFFERS[0]['carrierId']),
                ['format' => $query['format'] ?? 'PDF', 'dpi' => isset($query['dpi']) ? (int) $query['dpi'] : null],
            ),
        ]]);
    }

    private function tracking(PendingRequest $pending): MockResponse
    {
        $trackingId = (string) ($pending->query()->get('trackingId') ?? 'fixture');

        return MockResponse::make(['payload' => [
            'trackingId' => $trackingId,
            'alternateLegTrackingId' => $trackingId,
            'eventHistory' => [[
                'eventCode' => 'ReadyForReceive',
                'eventTime' => now()->toIso8601ZuluString(),
                'location' => ['city' => 'Seattle', 'stateOrRegion' => 'WA', 'countryCode' => 'US', 'postalCode' => '98101'],
            ]],
            'promisedDeliveryDate' => now()->addWeekdays(3)->toIso8601ZuluString(),
            'summary' => ['status' => 'PreTransit'],
        ]]);
    }

    /**
     * @param  array{carrierId: string, carrierName: string, serviceId: string, serviceName: string, price: float, days: int, protected: bool}  $offer
     * @param  array<string, mixed>  $specification
     * @return array<string, mixed>
     */
    private function packageDocumentDetail(array $offer, string $trackingId, array $specification): array
    {
        $format = strtoupper((string) ($specification['format'] ?? 'PDF')) === 'ZPL' ? 'ZPL' : 'PDF';
        $pages = [$this->label($offer, $trackingId)];
        $contents = $format === 'ZPL'
            ? (new SampleZplWriter)->render($pages, (int) ($specification['dpi'] ?? 203))
            : (new SamplePdfWriter)->render($pages);

        return [
            'packageClientReferenceId' => '1',
            'trackingId' => $trackingId,
            'packageDocuments' => [[
                'type' => 'LABEL',
                'format' => $format,
                'contents' => base64_encode($contents),
            ]],
        ];
    }

    /**
     * @param  array{carrierId: string, carrierName: string, serviceId: string, serviceName: string, price: float, days: int, protected: bool}  $offer
     */
    private function label(array $offer, string $trackingId): SampleCanvas
    {
        $label = SampleCanvas::label();
        $label->banner(0, 16, 8, 'SAMPLE - NOT VALID POSTAGE - DO NOT SHIP');
        $label->text(10, 30, 16, mb_strtoupper($offer['carrierName']), bold: true);
        $label->text(10, 52, 10, $offer['serviceName']);
        $label->text(10, 68, 8, 'Bought through Amazon Buy Shipping (demo fixture)');
        $label->box(10, 120, 268, 140, 1.5);
        $label->text(10, 175, 28, 'SAMPLE', bold: true, width: 268, center: true);
        $label->text(10, 280, 9, 'TRACKING #', bold: true);
        $label->text(10, 294, 11, $trackingId);
        $label->banner(416, 16, 8, 'SAMPLE - NOT VALID POSTAGE - DO NOT SHIP');

        return $label;
    }

    /**
     * @return array{carrierId: string, carrierName: string, serviceId: string, serviceName: string, price: float, days: int, protected: bool}
     */
    private function offerForRateId(string $rateId): array
    {
        foreach (self::OFFERS as $offer) {
            if (str_starts_with($rateId, 'fixture.'.$offer['carrierId'].'.'.$offer['serviceId'].'.')) {
                return $offer;
            }
        }

        return self::OFFERS[0];
    }

    /**
     * Shaped like each carrier's own numbers, and prefixed so none can match a real parcel.
     */
    private function trackingIdFor(string $carrierId): string
    {
        $digits = fn (int $length): string => implode('', array_map(fn (): int => random_int(0, 9), range(1, $length)));

        return match ($carrierId) {
            'UPS' => '1Z999'.strtoupper(Str::random(3)).$digits(10),
            'USPS' => '9400199'.$digits(15),
            default => 'TBA999'.$digits(9),
        };
    }

    /**
     * @param  list<int>  $dpis
     * @return array<string, mixed>
     */
    private function documentSpecification(string $format, array $dpis): array
    {
        return [
            'format' => $format,
            'size' => ['width' => 4.0, 'length' => 6.0, 'unit' => 'INCH'],
            'printOptions' => [[
                'supportedDPIs' => $dpis,
                'supportedPageLayouts' => ['LEFT'],
                'supportedFileJoiningOptions' => [true],
                'supportedDocumentDetails' => [['name' => 'LABEL', 'isMandatory' => true]],
            ]],
        ];
    }
}
