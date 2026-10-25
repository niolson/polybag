<?php

namespace App\Services\Carriers;

use App\Contracts\CreatesCarrierManifest;
use App\Contracts\DirectCarrierAdapter;
use App\Contracts\UsesCarrierAccount;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\ManifestResponse;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\ServiceCapability;
use App\Models\Carrier;
use App\Models\Package;
use App\Services\Carriers\Concerns\ConsultsCarrierPolicyForOffers;
use App\Services\Carriers\Concerns\HasDefaultServiceCapabilities;
use App\Services\Carriers\Concerns\IdentifiesCatalogServices;
use App\Services\Carriers\Concerns\ResolvesCarrierAccount;
use App\Services\Carriers\Samples\SampleCanvas;
use App\Services\Carriers\Samples\SampleDocuments;
use App\Services\Carriers\Samples\SamplePdfWriter;
use App\Services\Carriers\Samples\SampleZplWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Saloon\Http\Response;

/**
 * A carrier that quotes, sells, voids and manifests without calling anyone,
 * for `FAKE_CARRIERS=true` demos and for tests that register it themselves.
 *
 * What it prints is built from the order at purchase time and is plainly a
 * sample: see {@see SampleDocuments} for what keeps it from passing as
 * postage.
 */
class FakeCarrierAdapter implements CreatesCarrierManifest, DirectCarrierAdapter, UsesCarrierAccount
{
    use ConsultsCarrierPolicyForOffers;
    use HasDefaultServiceCapabilities;
    use IdentifiesCatalogServices;

    // For `resolveAccount()` only: the `isConfigured()` below wins over the
    // trait's, because fake mode quotes with or without an account.
    use ResolvesCarrierAccount;

    // In test/fake mode, report everything as Supported so capability checks don't filter rates.
    public function serviceCapability(string $serviceCode): ServiceCapability
    {
        return ServiceCapability::Supported;
    }

    /**
     * Every `code` here must be a real `CarrierService.service_code` as seeded
     * by `CarrierSeeder`, because `ShippingRateService` hands
     * {@see self::getRates()} the codes the shipping method allows and the
     * filter below drops anything that does not match. A made-up code is
     * not a harmless placeholder — no shipping method can list it, so it
     * silently vanishes and fake mode shows fewer rates than it should with
     * nothing to say why.
     *
     * The UPS codes are numeric on purpose. That is what UPS itself uses and
     * what the catalog stores; spelling them `UPS_GROUND` reads better and
     * matches nothing.
     *
     * @var array<string, array<int, array{code: string, name: string, price: float, transit: string, days: int}>>
     */
    private const RATES = [
        Carrier::USPS => [
            ['code' => 'USPS_GROUND_ADVANTAGE', 'name' => 'Ground Advantage', 'price' => 8.50, 'transit' => '2-5 Business Days', 'days' => 5],
            ['code' => 'PRIORITY_MAIL', 'name' => 'Priority Mail', 'price' => 12.75, 'transit' => '1-3 Business Days', 'days' => 3],
            ['code' => 'PRIORITY_MAIL_EXPRESS', 'name' => 'Priority Mail Express', 'price' => 28.40, 'transit' => '1-2 Days', 'days' => 1],
            // Offered only to a Package that qualifies: the catalog row requires
            // media contents, and the shared filter drops it otherwise.
            ['code' => 'MEDIA_MAIL', 'name' => 'Media Mail', 'price' => 4.25, 'transit' => '2-8 Business Days', 'days' => 8],
        ],
        Carrier::FEDEX => [
            ['code' => 'FEDEX_GROUND', 'name' => 'FedEx Ground', 'price' => 10.25, 'transit' => '1-5 Business Days', 'days' => 5],
            ['code' => 'FEDEX_EXPRESS_SAVER', 'name' => 'FedEx Express Saver', 'price' => 18.90, 'transit' => '3 Business Days', 'days' => 3],
            ['code' => 'FEDEX_2_DAY', 'name' => 'FedEx 2Day', 'price' => 24.50, 'transit' => '2 Business Days', 'days' => 2],
        ],
        Carrier::UPS => [
            ['code' => '03', 'name' => 'UPS Ground', 'price' => 11.00, 'transit' => '1-5 Business Days', 'days' => 5],
            ['code' => '12', 'name' => 'UPS 3 Day Select', 'price' => 19.75, 'transit' => '3 Business Days', 'days' => 3],
            ['code' => '02', 'name' => 'UPS 2nd Day Air', 'price' => 26.30, 'transit' => '2 Business Days', 'days' => 2],
        ],
    ];

    /**
     * Quoted instead of {@see self::RATES} when the parcel leaves its origin
     * country, so an international shipping method has something to show and
     * a domestic one never offers a lane it cannot ship. The same rule about
     * real catalog codes applies.
     *
     * @var array<string, array<int, array{code: string, name: string, price: float, transit: string, days: int}>>
     */
    private const INTERNATIONAL_RATES = [
        Carrier::USPS => [
            ['code' => 'FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE', 'name' => 'First-Class Package International Service', 'price' => 18.60, 'transit' => '7-21 Business Days', 'days' => 15],
            ['code' => 'PRIORITY_MAIL_INTERNATIONAL', 'name' => 'Priority Mail International', 'price' => 46.20, 'transit' => '6-10 Business Days', 'days' => 8],
            ['code' => 'PRIORITY_MAIL_EXPRESS_INTERNATIONAL', 'name' => 'Priority Mail Express International', 'price' => 68.90, 'transit' => '3-5 Business Days', 'days' => 4],
        ],
        Carrier::FEDEX => [
            ['code' => 'FEDEX_INTERNATIONAL_CONNECT_PLUS', 'name' => 'FedEx International Connect Plus', 'price' => 39.80, 'transit' => '2-5 Business Days', 'days' => 5],
            ['code' => 'INTERNATIONAL_ECONOMY', 'name' => 'FedEx International Economy', 'price' => 58.40, 'transit' => '4-6 Business Days', 'days' => 5],
            ['code' => 'FEDEX_INTERNATIONAL_PRIORITY', 'name' => 'FedEx International Priority', 'price' => 84.10, 'transit' => '1-3 Business Days', 'days' => 3],
        ],
        Carrier::UPS => [
            ['code' => '11', 'name' => 'UPS Standard', 'price' => 41.50, 'transit' => '3-7 Business Days', 'days' => 6],
            ['code' => '08', 'name' => 'UPS Worldwide Expedited', 'price' => 71.25, 'transit' => '2-5 Business Days', 'days' => 4],
            ['code' => '65', 'name' => 'UPS Worldwide Saver', 'price' => 89.60, 'transit' => '1-3 Business Days', 'days' => 3],
        ],
    ];

    /** @var array<string, string> */
    private const TRACKING_PREFIXES = [
        Carrier::USPS => '9400',
        Carrier::FEDEX => '7489',
        Carrier::UPS => '1Z99',
    ];

    /**
     * How each carrier's real adapter delivers the customs declaration for a
     * lane that carries one, mirrored so a demo shows the same paperwork.
     *
     * @var array<string, CustomsDocumentDelivery>
     */
    private const CUSTOMS_DOCUMENT_DELIVERY = [
        Carrier::USPS => CustomsDocumentDelivery::FusedIntoLabel,
        Carrier::UPS => CustomsDocumentDelivery::Separate,
        Carrier::FEDEX => CustomsDocumentDelivery::NotRequested,
    ];

    /**
     * @param  CustomsDocumentDelivery|null  $customsDocumentDelivery  A fixed answer for a test to prove the report printer gate with, for every lane. Null mirrors the real carrier.
     */
    public function __construct(
        private readonly string $carrierName,
        public ?CustomsDocumentDelivery $customsDocumentDelivery = null,
    ) {}

    public function getCarrierName(): string
    {
        return $this->carrierName;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
    {
        return null;
    }

    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        $rates = $request->destinationCountry === $request->originCountry
            ? self::RATES[$this->carrierName] ?? []
            : self::INTERNATIONAL_RATES[$this->carrierName] ?? [];

        // Taken the way a real adapter takes it, so the offer the rate service
        // issues records the account — and the purchase's account check
        // exercises the same path fake mode is standing in for.
        $account = $this->ratingAccount($request);

        app(FakeCarrierLatency::class)->waitForRates();

        return $this->withCatalogIdentity(collect($rates)
            ->when(! empty($serviceCodes), fn (Collection $c) => $c->whereIn('code', $serviceCodes))
            ->map(fn (array $rate): RateResponse => new RateResponse(
                carrier: $this->carrierName,
                serviceCode: $rate['code'],
                serviceName: $rate['name'],
                price: $rate['price'],
                deliveryCommitment: $rate['transit'],
                deliveryDate: now()->addWeekdays($rate['days'])->toDateString(),
                transitTime: $rate['transit'],
                packagingRequirement: PackagingRequirement::shipperPackaging(),
                carrierAccountId: $account?->id,
            ))
            ->values());
    }

    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
    {
        return collect();
    }

    /**
     * Sell a sample label for the order: ZPL when the workstation asked for
     * ZPL, at its print density, and a PDF otherwise.
     *
     * International paperwork comes back the way this carrier's real adapter
     * returns it — inside the label for USPS, as a separate Letter invoice for
     * UPS, and not at all for FedEx, which never requests one.
     */
    public function createShipment(ShipRequest $request): ShipResponse
    {
        app(FakeCarrierLatency::class)->waitForLabel();

        $prefix = self::TRACKING_PREFIXES[$this->carrierName] ?? 'FAKE';
        $tracking = $prefix.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT).random_int(100, 999);
        $service = $request->selectedRate->serviceName;

        $documents = new SampleDocuments;
        $pages = [$documents->shippingLabel($request, $this->carrierName, $service, $tracking)];
        $customsForm = null;

        $delivery = $request->customsItems === []
            ? CustomsDocumentDelivery::None
            : $this->customsDocumentDelivery($request->fromAddress, $request->toAddress, $request->selectedRate);

        if ($delivery === CustomsDocumentDelivery::FusedIntoLabel) {
            $pages[] = $documents->customsDeclaration($request, $this->carrierName, $tracking);
        } elseif ($delivery === CustomsDocumentDelivery::Separate) {
            $customsForm = $this->pdf([$documents->commercialInvoice($request, $this->carrierName, $tracking)]);
        }

        $isZpl = $request->labelFormat === 'zpl';
        $dpi = in_array($request->labelDpi, SampleZplWriter::SUPPORTED_DPI, true) ? $request->labelDpi : 203;

        return ShipResponse::success(
            trackingNumber: $tracking,
            cost: $request->selectedRate->price,
            carrier: $this->carrierName,
            service: $service,
            labelData: $isZpl ? base64_encode((new SampleZplWriter)->render($pages, $dpi)) : $this->pdf($pages),
            labelFormat: $isZpl ? 'zpl' : 'pdf',
            labelDpi: $isZpl ? $dpi : null,
            shipDate: $request->shipDate,
            appliedServices: $request->specialServiceCodes,
            customsFormData: $customsForm,
        );
    }

    /**
     * A sample end-of-day form listing the Packages, numbered so it cannot
     * collide with a real one. Nothing is sent anywhere.
     */
    public function createCarrierManifest(Collection $packages, AddressData $fromAddress, CarbonImmutable $mailingDate): ManifestResponse
    {
        $manifestNumber = sprintf('SAMPLE-%s-%04d', $mailingDate->format('Ymd'), random_int(0, 9999));

        return ManifestResponse::success(
            manifestNumber: $manifestNumber,
            carrier: $this->carrierName,
            image: $this->pdf((new SampleDocuments)->manifest($this->carrierName, $manifestNumber, $fromAddress, $mailingDate, $packages)),
        );
    }

    /**
     * @param  list<SampleCanvas>  $pages
     * @return string Base64 of the PDF
     */
    private function pdf(array $pages): string
    {
        return base64_encode((new SamplePdfWriter)->render($pages));
    }

    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
    {
        return CancelResponse::success("Sample {$this->carrierName} label {$trackingNumber} voided.");
    }

    public function supportsTracking(): bool
    {
        return false;
    }

    public function trackShipment(Package $package): TrackShipmentResponse
    {
        return TrackShipmentResponse::unsupported();
    }

    public function supportsMultiPackage(): bool
    {
        return false;
    }

    /**
     * Only USPS runs a manifest program, as for the real carriers, so End of
     * Day offers the sample form where it would offer a SCAN form.
     */
    public function supportsCarrierManifest(): bool
    {
        return $this->carrierName === Carrier::USPS;
    }

    public function packagingRequirementFor(RateResponse $rate): PackagingRequirement
    {
        return PackagingRequirement::shipperPackaging();
    }

    /**
     * What the real carrier would answer for this lane, unless a test fixed
     * the answer — so the report printer gate can be proved in both
     * directions, a fused carrier not blocked and a separate one blocked.
     */
    public function customsDocumentDelivery(AddressData $from, AddressData $to, ?RateResponse $rate = null): CustomsDocumentDelivery
    {
        if ($this->customsDocumentDelivery !== null) {
            return $this->customsDocumentDelivery;
        }

        return $from->sharesCustomsZoneWith($to)
            ? CustomsDocumentDelivery::None
            : self::CUSTOMS_DOCUMENT_DELIVERY[$this->carrierName] ?? CustomsDocumentDelivery::None;
    }
}
