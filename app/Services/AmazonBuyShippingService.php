<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\AmazonPurchasedLabel;
use App\DataTransferObjects\Shipping\AmazonShippingQuote;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\PostageSource;
use App\Exceptions\Carriers\AmazonLabelPurchaseException;
use App\Exceptions\MissingAmazonOrderItemsException;
use App\Http\Integrations\Amazon\AmazonSpApiConnector;
use App\Http\Integrations\Amazon\Requests\CancelAmazonShipment;
use App\Http\Integrations\Amazon\Requests\GetShipmentTracking;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Http\Integrations\Amazon\Requests\PurchaseShipment;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Services\PostageSources\OfferStore;
use App\Services\PostageSources\PostageSourceResolver;
use App\Services\ShipmentImport\AmazonOrderItems;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;

/**
 * Talks to Amazon Shipping v2 on behalf of an Amazon seller account.
 *
 * Everything here is Amazon's vocabulary — payloads, tokens, document
 * specifications, business IDs. What a rate *means* to PolyBag is
 * `AmazonBuyShippingAdapter`'s job, and what happens to a package after the
 * label exists is `AmazonPostageSource`'s. Splitting them is what keeps the
 * request-building testable against Amazon's own schema without a package, an
 * offer store or a carrier registry in the way.
 *
 * **Credentials come from the shipment's own Amazon `DataSource`, never a
 * `CarrierAccount`.** The order being rated lives in that seller's account, and
 * per-client data sources are already how 3PL scoping works. Amazon Shipping
 * sold against an account of *ours*, for orders that did not come from Amazon,
 * is the opposite arrangement and is not this.
 */
class AmazonBuyShippingService
{
    /**
     * Amazon's own purchase window, from the moment `getRates` replies.
     *
     * Not returned in the response — `01` looked — so it is tracked from our
     * side. Amazon documents ten minutes and answers `TOKEN_EXPIRED` after; the
     * offer expires early so that a packer is sent back for a fresh quote
     * rather than into a purchase that is about to be refused.
     */
    public const OFFER_WINDOW_SECONDS = 480;

    /**
     * Every marketplace this application supports is North America, and
     * `AmazonShipping_US` is the only Amazon shipping business there. It is
     * still sent explicitly on every request: omitted, Amazon defaults the
     * header to `AmazonShipping_UK`, which is wrong for all four of them.
     */
    public const BUSINESS_ID = 'AmazonShipping_US';

    /**
     * The formats to ask Amazon for, per workstation label format, in order.
     *
     * ZPL and PNG carry the label alone; the PDF is joined with a pack slip
     * and comes last. See {@see documentSpecification()}.
     *
     * @var array<string, array<int, string>>
     */
    private const FORMAT_PREFERENCE = [
        'zpl' => ['ZPL', 'PNG', 'PDF'],
        'pdf' => ['PNG', 'PDF'],
    ];

    /**
     * What a document Amazon returns is recorded as, in the vocabulary the
     * print path speaks: raster labels are `image`, as UPS's GIFs already are.
     *
     * @var array<string, string>
     */
    private const LABEL_FORMATS = ['pdf' => 'pdf', 'zpl' => 'zpl', 'png' => 'image'];

    public function __construct(
        private readonly PostageSourceResolver $postageSourceResolver,
        private readonly AmazonOrderItems $orderItems,
        private readonly OfferStore $offers,
    ) {}

    /**
     * The active Amazon data source this package's shipment was imported from.
     *
     * The binding is ADR-0002 decision 9's general rule for channel postage, so
     * it is asked of `PostageSourceResolver`. All that is particular here is
     * that a source of another driver is no answer: a Shopify account sells
     * postage too, and cannot sell it against an Amazon order.
     */
    public function dataSourceFor(Package $package): ?DataSource
    {
        $source = $this->postageSourceResolver->channelSourceFor($package);

        return $source?->source_type === AmazonSource::class ? $source : null;
    }

    /**
     * The source that actually bought this package's postage, for the
     * operations that come after.
     *
     * A shipped package answers from its recorded provenance, because a
     * shipment can be re-pointed at another source after its label was bought
     * and voiding through the new one would reach an account that never bought
     * it. An unshipped package has no provenance yet, so the purchase path
     * falls back to the import binding above — which is where the order it will
     * rate against lives.
     */
    public function postageSourceFor(Package $package): ?DataSource
    {
        if ($package->postage_source !== PostageSource::PostageDataSource) {
            return $this->dataSourceFor($package);
        }

        $package->loadMissing('postageDataSource');

        $source = $package->postageDataSource;

        return ($source && $source->active && $source->source_type === AmazonSource::class)
            ? $source
            : null;
    }

    /**
     * Whether Amazon could be asked to rate this package at all.
     *
     * Both halves of the identity have to be present — a live Amazon source and
     * the order the parcel belongs to. Neither is an error worth telling a
     * packer about: a shipment imported from a database query simply has no
     * Amazon offer, the same way it has no Shopify one.
     */
    public function canQuoteFor(Package $package): bool
    {
        return $this->dataSourceFor($package) !== null
            && $this->orderItems->orderIdFor($package) !== null;
    }

    public function marketplaceIdFor(DataSource $source): ?string
    {
        $marketplace = $source->settings['marketplace_id'] ?? null;

        return filled($marketplace) ? (string) $marketplace : null;
    }

    /**
     * Rate one package against the Amazon order it belongs to.
     *
     * Returns null when there is nothing to ask — no source, no order, no
     * dimensions — rather than raising, because rate shopping asks every
     * configured source about every package and most packages are not Amazon's.
     *
     * @throws MissingAmazonOrderItemsException when a packed item has no Amazon order item ID
     * @throws RequestException on a transport or HTTP failure
     */
    public function quote(Package $package, RateRequest $request): ?AmazonShippingQuote
    {
        $source = $this->dataSourceFor($package);
        $orderId = $this->orderItems->orderIdFor($package);

        if (! $source || ! $orderId || $request->packages === []) {
            return null;
        }

        $response = $this->connectorFor($source)->send(
            new GetShippingRates($this->buildRatePayload($package, $request, $orderId), self::BUSINESS_ID)
        );

        if (! $response->successful()) {
            logger()->warning('Amazon getRates failed', [
                'package_id' => $package->id,
                'status' => $response->status(),
                'errors' => $this->describeErrors($response),
            ]);

            return null;
        }

        return AmazonShippingQuote::fromPayload($response->json('payload', []));
    }

    /**
     * The `getRates` body for one package.
     *
     * Public so the schema test can validate it against Amazon's own
     * `GetRatesRequest` without sending anything. `shipDate` is deliberately
     * omitted: Amazon computes the promise from now, and the ship date we
     * record is our pickup policy's answer rather than a constraint on theirs.
     *
     * @return array<string, mixed>
     *
     * @throws MissingAmazonOrderItemsException
     */
    public function buildRatePayload(Package $package, RateRequest $request, string $orderId): array
    {
        $package->loadMissing(['shipment', 'location']);

        $from = $package->location
            ? AddressData::fromLocation($package->location)
            : AddressData::fromConfig();

        return [
            'shipTo' => $this->address(AddressData::fromShipment($package->shipment)),
            'shipFrom' => $this->address($from),
            'packages' => [$this->packagePayload($package, $request)],
            'channelDetails' => [
                'channelType' => 'AMAZON',
                'amazonOrderDetails' => ['orderId' => $orderId],
            ],
        ];
    }

    /**
     * Buy one offer.
     *
     * The idempotency key is the offer's public identifier, which is what makes
     * this safe to call twice: Amazon recognizes the retry and returns the
     * shipment it already created rather than buying a second label. That is
     * the whole recovery mechanism for a purchase whose reply never arrived —
     * Shipping v2 has no way to look a shipment up by rate ID, so the key is
     * the only handle on a purchase we did not hear about.
     *
     * @throws AmazonLabelPurchaseException when Amazon answers and refuses
     * @throws RequestException on a transport failure, which proves nothing and must leave the offer unresolved
     */
    public function purchase(Package $package, ShippingOffer $offer, ShipRequest $request): AmazonPurchasedLabel
    {
        $source = $this->sellingSourceFor($offer);
        $payload = $this->buildPurchasePayload($offer, $request);

        $response = $this->connectorFor($source)->send(
            new PurchaseShipment($payload, $offer->public_id, self::BUSINESS_ID)
        );

        if (! $response->successful()) {
            // A reply, so the offer is settled rather than ambiguous: Amazon
            // answered and did not sell anything.
            throw new AmazonLabelPurchaseException(
                'Amazon refused the label purchase: '.$this->describeErrors($response)
            );
        }

        $result = $response->json('payload', []);
        $shipmentId = $result['shipmentId'] ?? null;

        if (blank($shipmentId)) {
            throw new AmazonLabelPurchaseException('Amazon accepted the purchase but returned no shipment ID.');
        }

        // The moment the source confirms, and before anything can go wrong
        // reading the documents. Everything below this line happens with a
        // shipment that exists and has been paid for, so a later failure must
        // never be reported as "nothing was bought" — recording the reference
        // here is what makes that impossible, since `recordFailure()` only ever
        // fills a blank.
        $this->offers->recordPurchase($offer, (string) $shipmentId);

        // The shape of what came back, without the bytes. Which documents a
        // purchase returns — one joined file or several, and of what type —
        // is what `labelFrom()` below has to assume about, and this is the
        // only record of it once the label has been stored.
        logger()->info('Amazon Buy Shipping purchase documents', [
            'package_id' => $package->id,
            'amazon_shipment_id' => $shipmentId,
            'requested' => $payload['requestedDocumentSpecification'] ?? null,
            'details' => collect($result['packageDocumentDetails'] ?? [])->map(fn (array $detail): array => [
                'package_client_reference_id' => $detail['packageClientReferenceId'] ?? null,
                'tracking_id' => $detail['trackingId'] ?? null,
                'documents' => collect($detail['packageDocuments'] ?? [])->map(fn (array $document): array => [
                    'type' => $document['type'] ?? null,
                    'format' => $document['format'] ?? null,
                    'bytes' => strlen((string) ($document['contents'] ?? '')),
                ])->all(),
            ])->all(),
        ]);

        // The resolution Amazon was *asked* for, not the one Device Settings
        // prefers. They differ whenever the chosen rate did not publish the
        // configured DPI, and the label bytes are whatever Amazon generated —
        // recording the preference instead would print a 300 DPI label at 203
        // and size it wrong.
        return $this->labelFrom($result, $payload['requestedDocumentSpecification']['dpi'] ?? null);
    }

    /**
     * The Amazon account that quoted this offer, and the only one that may buy it.
     *
     * Not the shipment's *current* source. An offer is bound to the source
     * instance that issued it (ADR-0002 decision 4), and the two come apart
     * when a shipment is re-pointed at another Amazon account between quote and
     * purchase — at which point account A's `requestToken` and `rateId` would
     * be presented with account B's credentials, while the package went on
     * recording A as its provenance. Amazon would refuse, but a refusal that
     * only happens because Amazon happened to check is not the guarantee.
     *
     * @throws AmazonLabelPurchaseException which resolves the offer: nothing was bought
     */
    private function sellingSourceFor(ShippingOffer $offer): DataSource
    {
        $offer->loadMissing('postageDataSource');

        $source = $offer->postageDataSource;

        if (! $source || ! $source->active || $source->source_type !== AmazonSource::class) {
            throw new AmazonLabelPurchaseException(
                'The Amazon account this rate was quoted on is no longer available, so its postage cannot be bought. '
                .'Get rates again.'
            );
        }

        return $source;
    }

    /**
     * The `purchaseShipment` body.
     *
     * Public for the same reason as the rate payload — the schema test builds
     * one and validates it, and a body that only ever existed inside a mocked
     * HTTP call is a body nothing checks.
     *
     * @return array<string, mixed>
     */
    public function buildPurchasePayload(ShippingOffer $offer, ShipRequest $request): array
    {
        $context = $offer->purchase_context ?? [];
        $metadata = $offer->rate_metadata ?? [];

        $payload = [
            'requestToken' => (string) ($context['requestToken'] ?? ''),
            'rateId' => (string) ($context['rateId'] ?? ''),
            'requestedDocumentSpecification' => $this->documentSpecification(
                $metadata['supportedDocumentSpecifications'] ?? [],
                $request->labelFormat,
                $request->labelDpi,
            ),
        ];

        $valueAddedServices = $this->valueAddedServicesFor($metadata, $request);

        if ($valueAddedServices !== []) {
            $payload['requestedValueAddedServices'] = $valueAddedServices;
        }

        return $payload;
    }

    /**
     * Void a label Amazon sold us.
     *
     * Takes the shipment ID rather than the tracking number, because that is
     * what Amazon's cancel operation is keyed on and it is the only identifier
     * that survives a carrier we hold no account with.
     */
    public function cancel(Package $package, string $shipmentId): Response
    {
        $source = $this->postageSourceFor($package);

        if (! $source) {
            throw new AmazonLabelPurchaseException(
                'This label was bought through an Amazon data source that is no longer active, so it cannot be voided here.'
            );
        }

        return $this->connectorFor($source)->send(
            new CancelAmazonShipment($shipmentId, self::BUSINESS_ID)
        );
    }

    /**
     * Ask Amazon where the parcel is, under the entitlement Amazon holds.
     */
    public function track(Package $package, string $trackingId, string $carrierId): Response
    {
        $source = $this->postageSourceFor($package);

        if (! $source) {
            throw new AmazonLabelPurchaseException(
                'This label was bought through an Amazon data source that is no longer active, so it cannot be tracked here.'
            );
        }

        return $this->connectorFor($source)->send(
            new GetShipmentTracking($trackingId, $carrierId, self::BUSINESS_ID)
        );
    }

    public function connectorFor(DataSource $source): AmazonSpApiConnector
    {
        return AmazonSpApiConnector::fromSettings(array_merge(
            $source->settings ?? [],
            $source->secret_settings ?? [],
            ['_data_source_id' => $source->id],
        ));
    }

    /**
     * Pick the document specification to buy the label in.
     *
     * Validated against the *chosen rate's* offer rather than against the
     * carrier, because it varies per rate and a mismatch fails the purchase.
     * `01` found PDF offered twice for the same rate — 8.5x11 and 4x6 — so a
     * naive "first PDF" would print letter-size labels on a 4x6 printer; 4x6
     * INCH is preferred explicitly and anything else is a fallback.
     *
     * The whole specification has to match what the rate published, not just
     * the format and size — the first live purchase (`10`) was refused with
     * "an invalid documentation specification was provided" for a body that
     * asked for `LABEL` alone, unjoined. Every print option seen offers file
     * joining as `[true]` only, and every PDF one marks a `PACKSLIP` mandatory
     * beside the `LABEL`; ZPL and PNG carry the label alone. So the joining
     * flag and the document types are read off the chosen print option too.
     *
     * Which format to ask for is decided by what the workstation can print
     * *and* by what comes back. The joined PDF is what it says: one file, the
     * label on page one and Amazon's pack slip on page two, both 4x6 — the
     * second live purchase sent both pages to the label printer. There is no
     * way to ask Amazon for the PDF without the pack slip, and nothing in this
     * stack splits a PDF, so the PDF is the last resort behind the two formats
     * that carry the label alone: ZPL, where the rate publishes it at the
     * resolution the device prints, and PNG, which the pixel path rasterizes
     * for any printer. ZPL at any other resolution is never bought: raw ZPL
     * comes out the wrong size and the print path refuses it, which is a
     * label paid for that nobody can print.
     *
     * @param  array<int, array<string, mixed>>  $supported  The rate's own `supportedDocumentSpecifications`
     * @return array<string, mixed>
     *
     * @throws AmazonLabelPurchaseException when the rate cannot produce a label we can print
     */
    public function documentSpecification(array $supported, string $labelFormat, ?int $labelDpi): array
    {
        $preference = self::FORMAT_PREFERENCE[strtolower($labelFormat)] ?? null;

        if ($preference === null) {
            throw new AmazonLabelPurchaseException(
                "Amazon Buy Shipping cannot produce a {$labelFormat} label. Choose PDF or ZPL in Device Settings."
            );
        }

        $chosen = null;

        foreach ($preference as $format) {
            $candidates = array_values(array_filter(
                $supported,
                fn (array $spec): bool => ($spec['format'] ?? null) === $format,
            ));

            if ($candidates === []) {
                continue;
            }

            $spec = $this->preferFourBySix($candidates);
            $dpis = $this->supportedDpis($spec);

            if ($format === 'ZPL' && $labelDpi !== null && $dpis !== [] && ! in_array($labelDpi, $dpis, true)) {
                continue;
            }

            $chosen = $spec;

            break;
        }

        if ($chosen === null) {
            throw new AmazonLabelPurchaseException(
                'Amazon did not offer this rate in a format this workstation can print'
                .($labelDpi !== null ? " ({$labelFormat} at {$labelDpi} DPI)" : " ({$labelFormat})")
                .'. Get rates again, or choose another label format in Device Settings.'
            );
        }

        $format = (string) $chosen['format'];
        $printOption = $chosen['printOptions'][0] ?? [];
        $dpis = $this->supportedDpis($chosen);
        $layouts = array_values(array_filter((array) ($printOption['supportedPageLayouts'] ?? []), 'is_string'));
        $joining = array_values(array_filter((array) ($printOption['supportedFileJoiningOptions'] ?? []), 'is_bool'));
        $mandatory = collect($printOption['supportedDocumentDetails'] ?? [])
            ->filter(fn (mixed $detail): bool => is_array($detail) && ($detail['isMandatory'] ?? false) && is_string($detail['name'] ?? null))
            ->pluck('name');

        return array_filter([
            'format' => $format,
            'size' => [
                'width' => (float) ($chosen['size']['width'] ?? 4),
                'length' => (float) ($chosen['size']['length'] ?? 6),
                'unit' => (string) ($chosen['size']['unit'] ?? 'INCH'),
            ],
            // Only the DPIs this rate published are on offer; asking for 203
            // where only 300 is supported fails the purchase. The requested
            // resolution wins when it is among them; otherwise the first is
            // taken — which, after the ZPL check above, only happens for the
            // raster formats the pixel path prints at any resolution.
            'dpi' => $dpis === [] ? null : (in_array($labelDpi, $dpis, true) ? $labelDpi : (int) $dpis[0]),
            'pageLayout' => $layouts[0] ?? null,
            // Unjoined when the rate allows it, otherwise whatever it does
            // allow; a value it did not publish fails the purchase.
            'needFileJoining' => in_array(false, $joining, true) ? false : ($joining[0] ?? false),
            // Every document the print option makes mandatory, and the label
            // regardless — Amazon refuses a request that leaves one out.
            'requestedDocumentTypes' => $mandatory->push('LABEL')->unique()->values()->all(),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<int, int|float>
     */
    private function supportedDpis(array $spec): array
    {
        return array_values(array_filter((array) ($spec['printOptions'][0]['supportedDPIs'] ?? []), 'is_numeric'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function preferFourBySix(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            $size = $candidate['size'] ?? [];

            if (($size['unit'] ?? null) === 'INCH'
                && (float) ($size['width'] ?? 0) === 4.0
                && (float) ($size['length'] ?? 0) === 6.0) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /**
     * Which value-added services to buy with the label.
     *
     * ADR-0002 decision 8 puts this at the offer seam, and Amazon's data is why:
     * `availableValueAddedServiceGroups` is per rate, and `01` found the
     * Confirmation group marked `isRequired` on every UPS and USPS offer and
     * absent entirely from OnTrac's. A required group has to be answered with
     * an option *that group offers* — Amazon refuses a purchase that leaves it
     * unanswered, and one that names an option it does not list (`10`). The
     * groups do not share a vocabulary for "nothing": UPS offers
     * `NO_CONFIRMATION`, USPS offers `DELIVERY_CONFIRMATION` at $0 and no
     * refusal at all. So when nothing was asked for, the answer is the
     * cheapest option the group has, whatever it is called. A group that is
     * not offered cannot be answered at all.
     *
     * @param  array<string, mixed>  $metadata  the offer's stored rate metadata
     * @return list<array{id: string}>
     */
    private function valueAddedServicesFor(array $metadata, ShipRequest $request): array
    {
        $groups = $metadata['availableValueAddedServiceGroups'] ?? [];
        $requested = [];

        foreach ($groups as $group) {
            $available = collect($group['valueAddedServices'] ?? [])
                ->pluck('id')
                ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
                ->values();

            if ($available->isEmpty()) {
                continue;
            }

            $wanted = collect(self::confirmationPreferences($request))
                ->first(fn (string $id): bool => $available->contains($id));

            if ($wanted !== null) {
                $requested[] = ['id' => $wanted];

                continue;
            }

            // Nothing was asked for, and the group insists on an answer. The
            // cheapest option it offers is the honest one — a refusal where
            // the group has one, delivery confirmation where USPS charges
            // nothing for it. A group whose cheapest answer costs money was
            // dropped at quote time by the adapter, so this never buys a
            // surcharge nobody chose.
            if ($group['isRequired'] ?? false) {
                $requested[] = ['id' => (string) self::cheapestOption($group)['id']];
            }
        }

        return $requested;
    }

    /**
     * The option a value-added service group offers for the least money — and,
     * at the same price, the one that asks the least of the delivery.
     *
     * Priority Mail Express lists `SIGNATURE_CONFIRMATION` at $0 beside its
     * other options. Free is not the same as harmless: a signature nobody
     * asked for is a parcel that comes back when nobody is home. So a tie on
     * price goes to the refusal, then plain delivery confirmation, before
     * anything that names a signature.
     *
     * Shared with the adapter, which drops a rate at quote time when a required
     * group's cheapest option still costs something and nothing asked for it.
     *
     * @param  array<string, mixed>  $group  one of the rate's `availableValueAddedServiceGroups`
     * @return array{id: string, cost?: array{value?: mixed}}|null
     */
    public static function cheapestOption(array $group): ?array
    {
        $leastDemanding = ['NO_CONFIRMATION' => 0, 'DELIVERY_CONFIRMATION' => 1];

        return collect($group['valueAddedServices'] ?? [])
            ->filter(fn (mixed $option): bool => is_array($option) && is_string($option['id'] ?? null) && $option['id'] !== '')
            ->sortBy([
                fn (array $a, array $b): int => ((float) ($a['cost']['value'] ?? PHP_FLOAT_MAX)) <=> ((float) ($b['cost']['value'] ?? PHP_FLOAT_MAX)),
                fn (array $a, array $b): int => ($leastDemanding[$a['id']] ?? 2) <=> ($leastDemanding[$b['id']] ?? 2),
            ])
            ->first();
    }

    /**
     * Amazon's confirmation service IDs for the special services we model, most
     * specific first — adult signature supersedes signature, the same way it
     * does everywhere else in the app.
     *
     * @return list<string>
     */
    public static function confirmationPreferences(ShipRequest $request): array
    {
        return array_values(array_filter([
            $request->hasSpecialService('adult_signature_required') ? 'ADULT_SIGNATURE_CONFIRMATION' : null,
            $request->hasSpecialService('signature_required') ? 'SIGNATURE_CONFIRMATION' : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload  Amazon's `PurchaseShipmentResult`
     * @param  int|null  $labelDpi  The DPI the purchase asked for, which is the one the bytes were generated at
     */
    private function labelFrom(array $payload, ?int $labelDpi): AmazonPurchasedLabel
    {
        $shipmentId = (string) $payload['shipmentId'];
        $detail = collect($payload['packageDocumentDetails'] ?? [])->first() ?? [];

        // Observed on the first live purchases (`03`, `10`): the label arrives
        // as one document typed `LABEL`. ZPL is the label alone. The joined
        // PDF is one file of two 4x6 pages — the label first, then Amazon's
        // pack slip — and every page of it goes to the label printer, which
        // is why {@see documentSpecification()} asks for a PDF last. Only a
        // document Amazon calls a label is taken: a lone PACKSLIP or
        // CUSTOM_FORM must fail here, loudly, rather than be recorded and
        // printed as the label.
        $document = collect($detail['packageDocuments'] ?? [])
            ->first(fn (mixed $doc): bool => is_array($doc) && ($doc['type'] ?? null) === 'LABEL');

        if (! $document || blank($document['contents'] ?? null)) {
            // The shipment exists and has been paid for, so this is never a
            // reason to buy again. It is reported with the ID that can void it.
            throw new AmazonLabelPurchaseException(
                "Amazon bought shipment {$shipmentId} but returned no printable label. "
                .'Check the order in Seller Central before buying again.'
            );
        }

        $format = strtolower((string) ($document['format'] ?? ''));

        return new AmazonPurchasedLabel(
            shipmentId: $shipmentId,
            trackingId: filled($detail['trackingId'] ?? null) ? (string) $detail['trackingId'] : null,
            labelData: (string) $document['contents'],
            labelFormat: self::LABEL_FORMATS[$format] ?? 'pdf',
            labelDpi: $labelDpi,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function packagePayload(Package $package, RateRequest $request): array
    {
        $parcel = $request->packages[0];
        $currency = 'USD';

        return [
            'dimensions' => [
                'length' => round($parcel->length, 2),
                'width' => round($parcel->width, 2),
                'height' => round($parcel->height, 2),
                'unit' => 'INCH',
            ],
            'weight' => [
                'unit' => 'POUND',
                // Amazon normalizes to metric internally and returns
                // `billedWeight` in kilograms for a pound request — `01` — so
                // nothing downstream may read a weight back without converting.
                'value' => round(max(0.01, $parcel->weight), 2),
            ],
            'insuredValue' => [
                'unit' => $currency,
                // Zero unless the shipment actually asked to declare a value.
                // Insuring by default would buy coverage nobody chose and bill
                // for it on every parcel.
                'value' => round((float) ($request->specialServiceConfig('declared_value')['amount'] ?? 0), 2),
            ],
            // Amazon echoes this back on the purchased document detail, which is
            // how a label is matched to the parcel it belongs to.
            'packageClientReferenceId' => (string) $package->getKey(),
            'items' => $this->orderItems->shippingItemsFor($package, $currency),
        ];
    }

    /**
     * Amazon's `Address`.
     *
     * The six required fields are always present, empty string included: an
     * address missing its state is a data problem worth a 400 that names it,
     * whereas dropping the key produces a schema violation whose message is
     * about a missing property and sends whoever reads it to the wrong place.
     * Only the genuinely optional fields are pruned when blank.
     *
     * @return array<string, mixed>
     */
    private function address(AddressData $address): array
    {
        return [
            'name' => trim($address->firstName.' '.$address->lastName) ?: (string) ($address->company ?? 'Recipient'),
            'addressLine1' => $address->streetAddress,
            'stateOrRegion' => (string) ($address->stateOrProvince ?? ''),
            'city' => $address->city,
            'countryCode' => $address->country,
            'postalCode' => (string) ($address->postalCode ?? ''),
        ] + array_filter([
            'companyName' => $address->company,
            'addressLine2' => $address->streetAddress2,
            'email' => $address->email,
            'phoneNumber' => $address->phone,
        ], fn (?string $value): bool => filled($value));
    }

    private function describeErrors(Response $response): string
    {
        $errors = collect($response->json('errors', []))
            ->map(fn (array $error): string => trim(($error['code'] ?? 'unknown').': '.($error['message'] ?? '')))
            ->filter()
            ->all();

        return $errors === []
            ? "HTTP {$response->status()}"
            : implode('; ', $errors);
    }
}
