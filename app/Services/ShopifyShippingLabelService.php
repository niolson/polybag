<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShopifyPurchasedLabel;
use App\Enums\PostageSource;
use App\Exceptions\Carriers\ShopifyLabelPurchaseException;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Http\Integrations\Shopify\ShopifyConnector;
use App\Models\DataSource;
use App\Models\Package;
use App\Services\PostageSources\PostageSourceResolver;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Support\Facades\Http;

/**
 * Buys shipping labels through Shopify Shipping.
 *
 * Shopify's 2026-07 Admin API exposes exactly one write operation for this —
 * `shippingLabelPurchase` — and no way to quote a rate first, read what a label
 * cost, or void one afterwards. The purchase is asynchronous: the mutation
 * enqueues a job and returns a result node that has to be polled until it
 * reaches PURCHASED or PURCHASE_FAILED.
 *
 * Credentials come from the Shopify `DataSource` the shipment was imported
 * from, which is also the only place a fulfillment order ID exists — labels
 * can't be bought for a shipment that didn't come from Shopify.
 */
class ShopifyShippingLabelService
{
    /**
     * Shopify's carrier codes for `preferredRateSelection`, and the carrier each
     * one means. FedEx is absent because Shopify refuses FedEx purchases through
     * this API.
     *
     * The names matter as much as the codes: `trackingInfo.company` on a
     * `ShippingLabel` returns the *code*, not a carrier name, so the carrier of
     * record has to be translated before anything tries to resolve it. USPS is
     * the trap — its code and its name are the same string, so a USPS label
     * resolves by coincidence and hides that the others do not.
     */
    public const CARRIER_NAMES = [
        'usps' => 'USPS',
        'ups_shipping' => 'UPS',
        'dhl_express' => 'DHL Express',
        'canada_post' => 'Canada Post',
    ];

    /**
     * Scopes `shippingLabelPurchase` needs, on top of the import scopes in
     * ShopifyFulfillmentOrderActivationService. Deliberately kept separate:
     * a data source that only imports orders must not be blocked from
     * activating or syncing locations because it cannot buy postage.
     *
     * The staff user also needs the `buy_shipping_labels` permission, which is
     * not an OAuth scope and cannot be checked through the API.
     */
    public const REQUIRED_SCOPES = ['write_orders', 'write_merchant_managed_fulfillment_orders'];

    public function __construct(
        private readonly PostageSourceResolver $postageSourceResolver,
    ) {}

    /**
     * Re-reads a label already bought, so a purchase that succeeded at Shopify
     * but failed on our side can be recovered instead of bought twice.
     */
    private const SHIPPING_LABEL_QUERY = <<<'GRAPHQL'
        query ShopifyShippingLabel($id: ID!) {
          shippingLabel(id: $id) {
            id
            trackingInfo { company number url }
            shippingDocuments { documentType format url }
          }
        }
        GRAPHQL;

    private const PURCHASE_MUTATION = <<<'GRAPHQL'
        mutation PurchaseShippingLabel($input: ShippingLabelPurchaseInput!) {
          shippingLabelPurchase(shippingLabelPurchase: $input) {
            shippingLabelPurchaseResult {
              id status done
              errors { code message }
            }
            userErrors { field code message }
          }
        }
        GRAPHQL;

    private const PURCHASE_STATUS_QUERY = <<<'GRAPHQL'
        query ShippingLabelPurchaseStatus($id: ID!) {
          node(id: $id) {
            ... on ShippingLabelPurchaseResult {
              id status done
              errors { code message }
              shippingLabels {
                id
                trackingInfo { company number url }
                shippingDocuments { documentType format url }
              }
            }
          }
        }
        GRAPHQL;

    /**
     * Everything Shopify will tell us about a label after it was bought.
     *
     * Two questions, one request. Shopify has no "was this label voided" query,
     * so the void answer comes from the fulfillment the purchase created:
     * voiding a label in the admin moves it to a LABEL_VOIDED display status,
     * and cancelling the fulfillment outright sets its status to CANCELLED. The
     * same `displayStatus` carries the delivery lifecycle once the parcel moves,
     * which is the only tracking we are entitled to for postage bought on
     * Shopify's account (ADR-0002).
     *
     * `events` is asked for on the chance Shopify populates it. The documented
     * way events are created is `fulfillmentEventCreate`, called by apps and
     * fulfillment services, so it may always come back empty here — costing one
     * field on a request already being made. It is not worth a second query and
     * its absence is not an error.
     */
    private const FULFILLMENT_STATE_QUERY = <<<'GRAPHQL'
        query ShopifyFulfillmentState($id: ID!) {
          fulfillmentOrder(id: $id) {
            id
            status
            fulfillments(first: 20) {
              nodes {
                id
                status
                displayStatus
                inTransitAt
                deliveredAt
                estimatedDeliveryAt
                trackingInfo { number company url }
                events(first: 50) {
                  nodes {
                    id
                    status
                    happenedAt
                    message
                    city
                    province
                    zip
                    country
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

    /** Fulfillment states that mean the label PolyBag holds is no longer live. */
    private const VOIDED_STATES = ['LABEL_VOIDED', 'CANCELED', 'CANCELLED'];

    /**
     * Buy a label for the package's Shopify fulfillment order.
     *
     * @param  string|null  $carrierCode  Shopify carrier code, or null to let Shopify choose the rate
     * @param  string|null  $serviceCode  Carrier-defined service code, required when $carrierCode is given
     *
     * @throws ShopifyLabelPurchaseException
     */
    public function purchase(
        Package $package,
        ShipRequest $request,
        ?string $carrierCode = null,
        ?string $serviceCode = null,
    ): ShopifyPurchasedLabel {
        $dataSource = $this->dataSourceFor($package);

        if (! $dataSource) {
            throw new ShopifyLabelPurchaseException(
                'This shipment did not come from an active Shopify data source, so no Shopify Shipping label can be bought for it.'
            );
        }

        $fulfillmentOrderId = $this->fulfillmentOrderId($package);

        if (! $fulfillmentOrderId) {
            throw new ShopifyLabelPurchaseException(
                'This shipment has no Shopify fulfillment order ID, so no Shopify Shipping label can be bought for it.'
            );
        }

        $connector = ShopifyConnector::fromSettings(
            array_merge($dataSource->settings ?? [], $dataSource->secret_settings ?? [])
        );

        // A purchase that Shopify completed but that failed on our side (a label
        // download that 500s, a crash between the two) leaves the shop charged
        // and the fulfillment created. Buying again would charge twice and be
        // rejected anyway, so re-read the label we already own.
        $recovered = $this->recoverPurchasedLabel($package, $connector);

        if ($recovered) {
            return $recovered;
        }

        // Shopify starts buying the moment the mutation is accepted, so a purchase
        // whose polling timed out or died mid-flight is still running — or already
        // done. Resume that result rather than issuing a second mutation.
        $pendingResultId = $package->metadata['shopify_purchase_result_id'] ?? null;

        if (filled($pendingResultId)) {
            logger()->info('Resuming a Shopify label purchase left in flight', [
                'package_id' => $package->id,
                'purchase_result_id' => $pendingResultId,
            ]);

            return $this->awaitLabel($connector, (string) $pendingResultId, null, $package);
        }

        $input = $this->buildPurchaseInput($fulfillmentOrderId, $request, $carrierCode, $serviceCode, $dataSource);

        $json = $connector->send(new GraphQL(self::PURCHASE_MUTATION, ['input' => $input]))->json();

        $this->assertNoGraphQLErrors($json);

        $payload = $json['data']['shippingLabelPurchase'] ?? [];

        if (! empty($payload['userErrors'])) {
            throw new ShopifyLabelPurchaseException($this->describeErrors($payload['userErrors']));
        }

        $result = $payload['shippingLabelPurchaseResult'] ?? null;
        $resultId = $result['id'] ?? null;

        if (! $resultId) {
            throw new ShopifyLabelPurchaseException('Shopify accepted the label purchase but returned no result to track it with.');
        }

        // Persist before the first poll: from here on Shopify owns a purchase we
        // would otherwise have no way to find again.
        $this->rememberPurchaseResult($package, (string) $resultId);

        return $this->awaitLabel($connector, $resultId, $result, $package);
    }

    /**
     * The fulfillment Shopify holds for this package's label, or null when
     * there is no answer to be had.
     *
     * Null covers three different unknowns deliberately: a shipment that never
     * came from Shopify, a fulfillment order Shopify no longer returns (deleted,
     * or moved to another location), and — the one that matters — an order
     * fulfilled in several shipments, whose other fulfillments belong to other
     * packages. Reading one of those as ours would un-ship a parcel in transit
     * or record another package's delivery against this one, so every caller
     * treats null as "don't know", never as a state.
     *
     * @return array<string, mixed>|null
     *
     * @throws ShopifyLabelPurchaseException
     */
    public function fulfillmentFor(Package $package): ?array
    {
        $dataSource = $this->postageSourceFor($package);
        $fulfillmentOrderId = $this->fulfillmentOrderId($package);

        if (! $dataSource || ! $fulfillmentOrderId || ! $package->tracking_number) {
            return null;
        }

        $connector = ShopifyConnector::fromSettings(
            array_merge($dataSource->settings ?? [], $dataSource->secret_settings ?? [])
        );

        $json = $connector->send(new GraphQL(self::FULFILLMENT_STATE_QUERY, ['id' => $fulfillmentOrderId]))->json();

        $this->assertNoGraphQLErrors($json);

        $fulfillments = $json['data']['fulfillmentOrder']['fulfillments']['nodes'] ?? null;

        if ($fulfillments === null) {
            return null;
        }

        return collect($fulfillments)->first(
            fn (array $fulfillment): bool => ($fulfillment['trackingInfo'][0]['number'] ?? null) === $package->tracking_number
        );
    }

    /**
     * Whether the label PolyBag holds for this package has been voided or
     * cancelled on Shopify's side.
     *
     * Returns false whenever the answer can't be established, so that an
     * ambiguous reply never un-ships a package that is genuinely in transit.
     *
     * @throws ShopifyLabelPurchaseException
     */
    public function isVoidedInShopify(Package $package): bool
    {
        return $this->isVoided($this->fulfillmentFor($package));
    }

    /**
     * Whether an already-fetched fulfillment reports the label as gone.
     *
     * Takes the fulfillment rather than the package so one poll can answer both
     * "was it voided?" and "how far along is it?" — LABEL_VOIDED is the signal
     * for the first and disqualifies the second.
     *
     * @param  array<string, mixed>|null  $fulfillment
     */
    public function isVoided(?array $fulfillment): bool
    {
        if ($fulfillment === null) {
            return false;
        }

        return in_array($fulfillment['displayStatus'] ?? '', self::VOIDED_STATES, true)
            || in_array($fulfillment['status'] ?? '', self::VOIDED_STATES, true);
    }

    /**
     * The Shopify data source that bought this package's postage.
     *
     * Once a package records channel postage, this is the recorded source or it
     * is nothing. There is deliberately no fallback to the shipment's import
     * source: those are the same record at purchase time, but a shipment can be
     * re-pointed afterwards, and reading a label bought on source A through
     * source B's credentials is exactly the drift the provenance column exists
     * to prevent. Answering "don't know" costs one skipped poll; answering with
     * the wrong shop's fulfillments could un-ship a parcel in transit.
     *
     * An inactive or non-Shopify recorded source is likewise no answer rather
     * than a reason to look elsewhere.
     *
     * Before a package ships there is no provenance to honour, so the purchase
     * path still resolves through the shipment's import source — which is where
     * the fulfillment order it will buy against lives.
     */
    public function postageSourceFor(Package $package): ?DataSource
    {
        if ($package->postage_source !== PostageSource::PostageDataSource) {
            return $this->dataSourceFor($package);
        }

        $package->loadMissing('postageDataSource');

        $source = $package->postageDataSource;

        return ($source && $source->active && $source->source_type === ShopifySource::class)
            ? $source
            : null;
    }

    /**
     * The active Shopify data source a package's shipment was imported from.
     *
     * The binding itself is not Shopify's rule but the general one for channel
     * postage — ADR-0002 decision 9 — so it is asked of `PostageSourceResolver`
     * rather than reimplemented here. All that remains particular to this
     * service is that a source of another driver is no answer: an Amazon
     * account sells postage too, and cannot sell it through this mutation.
     */
    public function dataSourceFor(Package $package): ?DataSource
    {
        $source = $this->postageSourceResolver->channelSourceFor($package);

        return $source?->source_type === ShopifySource::class ? $source : null;
    }

    public function fulfillmentOrderId(Package $package): ?string
    {
        $package->loadMissing('shipment');

        $id = $package->shipment?->metadata['shopify_fulfillment_order_id'] ?? null;

        return filled($id) ? (string) $id : null;
    }

    /**
     * Whether a package can have a Shopify Shipping label bought for it at all.
     */
    public function canPurchaseFor(Package $package): bool
    {
        return $this->dataSourceFor($package) !== null && $this->fulfillmentOrderId($package) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPurchaseInput(
        string $fulfillmentOrderId,
        ShipRequest $request,
        ?string $carrierCode,
        ?string $serviceCode,
        DataSource $dataSource,
    ): array {
        $package = $request->packageData;

        // Shopify rejects a shipping date in the past, and a date-only ship date
        // resolves to midnight — which is already past by the time packing starts.
        $shipDate = $request->shipDate?->toDateTime();
        $shippingDatetime = ($shipDate && $shipDate > now())
            ? $request->shipDate->toIso8601String()
            : now()->addMinutes(5)->toIso8601String();

        $input = [
            'fulfillmentOrderId' => $fulfillmentOrderId,
            'shippingDatetime' => $shippingDatetime,
            'notifyCustomer' => (bool) ($dataSource->settings['notify_customer'] ?? false),
            'packageInfo' => [
                'customPackage' => [
                    'dimensions' => [
                        'length' => round($package->length, 2),
                        'width' => round($package->width, 2),
                        'height' => round($package->height, 2),
                        'unit' => 'INCHES',
                    ],
                    // PolyBag weighs the packed box, so the empty-package weight
                    // Shopify would otherwise add is already included in the total.
                    'weight' => ['value' => 0.0, 'unit' => 'POUNDS'],
                    'type' => 'BOX',
                ],
            ],
            'totalWeight' => ['value' => round($package->weight, 2), 'unit' => 'POUNDS'],
            'originAddress' => $this->mailingAddress($request->fromAddress),
        ];

        if ($carrierCode !== null && $serviceCode !== null) {
            $input['preferredRateSelection'] = [
                'carrierCode' => $carrierCode,
                'serviceCode' => $serviceCode,
            ];
        }

        return $input;
    }

    /**
     * @return array<string, string>
     */
    private function mailingAddress(AddressData $address): array
    {
        return array_filter([
            'firstName' => $address->firstName,
            'lastName' => $address->lastName,
            'company' => $address->company,
            'address1' => $address->streetAddress,
            'address2' => $address->streetAddress2,
            'city' => $address->city,
            'provinceCode' => $address->stateOrProvince,
            'zip' => $address->postalCode,
            'countryCode' => $address->country,
            'phone' => $address->phone,
        ], fn (?string $value): bool => filled($value));
    }

    /**
     * Poll the purchase result until Shopify finishes buying the label.
     *
     * @param  array<string, mixed>|null  $initialResult
     *
     * @throws ShopifyLabelPurchaseException
     */
    private function awaitLabel(ShopifyConnector $connector, string $resultId, ?array $initialResult, Package $package): ShopifyPurchasedLabel
    {
        $attempts = (int) config('services.shopify.label_poll_attempts', 20);
        $intervalMs = (int) config('services.shopify.label_poll_interval_ms', 1500);

        $result = $initialResult;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $status = $result['status'] ?? null;

            if ($status === 'PURCHASE_FAILED') {
                // Terminal: no label was bought, so the next attempt is free to
                // start a fresh purchase rather than resuming this dead result.
                $this->forgetPurchaseResult($package);

                throw new ShopifyLabelPurchaseException(
                    empty($result['errors'])
                        ? 'Shopify could not buy the label and gave no reason.'
                        : $this->describeErrors($result['errors'])
                );
            }

            // The mutation's own result never carries labels, so a PURCHASED
            // status is only actionable once a polled result brings them along.
            if ($status === 'PURCHASED' && isset($result['shippingLabels'])) {
                return $this->buildLabel($result, $package);
            }

            if ($attempt > 0) {
                usleep($intervalMs * 1000);
            }

            $json = $connector->send(new GraphQL(self::PURCHASE_STATUS_QUERY, ['id' => $resultId]))->json();
            $this->assertNoGraphQLErrors($json);

            $result = $json['data']['node'] ?? null;

            if (! $result) {
                throw new ShopifyLabelPurchaseException('Shopify stopped reporting on the label purchase before it finished.');
            }
        }

        // The result ID stays on the package deliberately: the purchase is still
        // running at Shopify, and the next attempt resumes it instead of buying
        // a second label.
        throw new ShopifyLabelPurchaseException(
            'Shopify is still buying the label. Try again in a moment — this will pick up the purchase already in progress rather than buying another.'
        );
    }

    /**
     * Re-read a label a previous attempt already bought.
     *
     * Keyed off `shopify_shipping_label_id` surviving on an unshipped package,
     * which only happens when a purchase settled at Shopify and then failed
     * before the package was marked shipped. The void synchronizer clears the
     * key when it un-ships a package, so a re-ship after a Shopify-side void
     * buys a new label rather than resurrecting the voided one.
     *
     * @throws ShopifyLabelPurchaseException
     */
    private function recoverPurchasedLabel(Package $package, ShopifyConnector $connector): ?ShopifyPurchasedLabel
    {
        $labelId = $package->metadata['shopify_shipping_label_id'] ?? null;

        if (! filled($labelId)) {
            return null;
        }

        logger()->info('Recovering a Shopify label bought by an earlier attempt', [
            'package_id' => $package->id,
            'shipping_label_id' => $labelId,
        ]);

        $json = $connector->send(new GraphQL(self::SHIPPING_LABEL_QUERY, ['id' => $labelId]))->json();

        $this->assertNoGraphQLErrors($json);

        $label = $json['data']['shippingLabel'] ?? null;

        if (! $label) {
            // Never fall through to a fresh purchase here. We know a label was
            // bought; buying another would charge the shop twice for one parcel.
            throw new ShopifyLabelPurchaseException(
                "A Shopify label ({$labelId}) was already bought for this package but can no longer be read. "
                .'Check the order in Shopify before shipping it again.'
            );
        }

        return $this->buildLabelFromNode($label);
    }

    /**
     * @param  array<string, mixed>  $result
     *
     * @throws ShopifyLabelPurchaseException
     */
    private function buildLabel(array $result, Package $package): ShopifyPurchasedLabel
    {
        $label = $result['shippingLabels'][0] ?? null;

        if (! $label) {
            throw new ShopifyLabelPurchaseException('Shopify reported the label as purchased but returned no label.');
        }

        // Record the purchase before downloading anything. Shopify has charged
        // for this label already; if the download fails, the package stays
        // unshipped but the label ID survives, so the next attempt recovers it
        // instead of buying a second one.
        $this->rememberPurchase($package, $label);

        return $this->buildLabelFromNode($label);
    }

    /**
     * Record the in-flight purchase so it can be resumed rather than repeated.
     */
    private function rememberPurchaseResult(Package $package, string $resultId): void
    {
        $package->metadata = array_merge($package->metadata ?? [], [
            'shopify_purchase_result_id' => $resultId,
        ]);

        $package->save();
    }

    private function forgetPurchaseResult(Package $package): void
    {
        $package->metadata = collect($package->metadata ?? [])
            ->except(['shopify_purchase_result_id'])
            ->all();

        $package->save();
    }

    /**
     * @param  array<string, mixed>  $label
     */
    private function rememberPurchase(Package $package, array $label): void
    {
        $documents = collect($label['shippingDocuments'] ?? []);

        // The label ID supersedes the purchase-result ID: once a label exists,
        // recovery reads the label directly and the result must not be resumed
        // again. Keeping only one live marker keeps the states unambiguous.
        $package->metadata = collect(array_merge($package->metadata ?? [], array_filter([
            'shopify_shipping_label_id' => $label['id'] ?? null,
            'shopify_label_document_url' => $documents->firstWhere('documentType', 'LABEL')['url'] ?? null,
            'shopify_customs_form_url' => $documents->firstWhere('documentType', 'CUSTOMS_FORM')['url'] ?? null,
        ], fn (?string $value): bool => filled($value))))
            ->except(['shopify_purchase_result_id'])
            ->all();

        $package->save();
    }

    /**
     * @param  array<string, mixed>  $label
     *
     * @throws ShopifyLabelPurchaseException
     */
    private function buildLabelFromNode(array $label): ShopifyPurchasedLabel
    {
        $documents = collect($label['shippingDocuments'] ?? []);
        $labelDocument = $documents->firstWhere('documentType', 'LABEL');

        if (! $labelDocument) {
            throw new ShopifyLabelPurchaseException('Shopify returned the purchase without a label document to print.');
        }

        $customsDocument = $documents->firstWhere('documentType', 'CUSTOMS_FORM');

        return new ShopifyPurchasedLabel(
            shippingLabelId: (string) $label['id'],
            trackingNumber: $label['trackingInfo']['number'] ?? null,
            trackingCompany: $label['trackingInfo']['company'] ?? null,
            labelData: $this->download($labelDocument['url'] ?? null),
            // Reported, never requested: `ShippingLabelPurchaseInput` has no
            // format field. `ShippingEnumsFileFormat` is PDF or ZPL, but the
            // shop's label format setting selects a paper size (Thermal 4x6,
            // Letter, A4), all three are PDF, and it is applied when the admin
            // renders for printing rather than to the document behind this URL.
            // So nothing merchant-facing reaches the ZPL value, and what we
            // download is a 4x6 PDF whatever that setting says. PDF is what a
            // label with no stated format has to be.
            labelFormat: strtolower((string) ($labelDocument['format'] ?? 'pdf')),
            customsFormUrl: $customsDocument['url'] ?? null,
            labelDocumentUrl: $labelDocument['url'] ?? null,
        );
    }

    private function download(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $response = Http::timeout(30)->get($url);

        if (! $response->successful()) {
            throw new ShopifyLabelPurchaseException(
                "Shopify bought the label but it could not be downloaded (HTTP {$response->status()}). Reprint it from the Shopify admin."
            );
        }

        return base64_encode($response->body());
    }

    /**
     * @param  array<string, mixed>  $json
     *
     * @throws ShopifyLabelPurchaseException
     */
    private function assertNoGraphQLErrors(array $json): void
    {
        if (empty($json['errors'])) {
            return;
        }

        $messages = array_map(
            fn (array $error): string => (string) ($error['message'] ?? 'Unknown GraphQL error'),
            $json['errors'],
        );

        throw new ShopifyLabelPurchaseException('Shopify GraphQL error: '.implode('; ', $messages));
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    private function describeErrors(array $errors): string
    {
        return implode('; ', array_map(
            fn (array $error): string => (string) ($error['message'] ?? $error['code'] ?? 'Unknown error'),
            $errors,
        ));
    }
}
