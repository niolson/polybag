<?php

namespace App\Services\Carriers;

use App\Contracts\AsyncRateQuoting;
use App\Contracts\CarrierPolicy;
use App\Contracts\RecoversUnresolvedPurchase;
use App\Contracts\UsesConnectionAccount;
use App\DataTransferObjects\PostageSources\OfferDraft;
use App\DataTransferObjects\PostageSources\ServiceObservation;
use App\DataTransferObjects\Shipping\AmazonShippingQuote;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\OffAmazonShippingStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceCapability;
use App\Enums\SourceEnvironment;
use App\Exceptions\Carriers\CarrierRateFetchException;
use App\Exceptions\Carriers\CarrierUnavailableException;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\DataSource;
use App\Models\Package;
use App\Services\AmazonBuyShippingService;
use App\Services\Carriers\Concerns\ReadsShippingV2Rates;
use App\Services\PostageSources\ObservedServiceRecorder;
use App\Services\PostageSources\OffAmazonShippingCheck;
use App\Services\PostageSources\OfferStore;
use App\Services\PostageSources\PostageSourceResolver;
use App\Services\RuleEvaluator;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Support\Collection;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;

/**
 * Sells Amazon Shipping directly to an order from another channel —
 * `carrier-catalog-reset/15`, ADR-0006 options M and N.
 *
 * A direct sale on a connection's account. It sells one carrier through one
 * account, priced and known before purchase, the way UPS does, so its rates
 * are direct rates: a shipping method lists Amazon Shipping Ground, a rule
 * names *Direct, Amazon Shipping Ground*, and automation buys it without an
 * approval. The account is the Amazon connection a scope chooses
 * ({@see DataSource::resolveOffAmazonShipping()}), because Shipping v2 is the
 * only API Amazon Shipping has and its credentials are the seller's.
 *
 * - **Quoting** is `channelType: EXTERNAL`, with items described from what was
 *   packed ({@see AmazonBuyShippingService::buildOffAmazonRatePayload()}). An
 *   account Amazon has not set up for it answers `403 A-101`, reported as the
 *   connection being unavailable and recorded on it.
 * - **Services** come from the catalog by code, as the UPS adapter's do. An
 *   Amazon Shipping service nobody has authored is recorded as an observation
 *   and dropped, like a mail class missing from the USPS allow-list.
 * - **Offers** are issued here, because a purchase needs Amazon's
 *   `requestToken` and `rateId`. Each names the connection as its postage
 *   source, so buying, tracking, voiding and recovery dispatch through it
 *   exactly as they did when Buy Shipping quoted `EXTERNAL`.
 * - **No observed-service identity** rides on a kept rate, so
 *   `RateResponse::sourceKind()` is `Direct`.
 * - **Never an Amazon order.** Sold `EXTERNAL`, its label would lose its link
 *   to the Amazon order (ADR-0002); {@see PostageSourceResolver::offAmazonShippingSourceFor()}
 *   refuses one even when its connection is inactive.
 */
class AmazonShippingAdapter implements AsyncRateQuoting, CarrierPolicy, RecoversUnresolvedPurchase, UsesConnectionAccount
{
    use ReadsShippingV2Rates;

    public function getCarrierName(): string
    {
        return Carrier::AMAZON_SHIPPING;
    }

    /**
     * Configured when any Amazon connection is opted in. Whether one sells to
     * a given package is its scope's question, answered per package.
     */
    public function isConfigured(): bool
    {
        return DataSource::query()
            ->where('active', true)
            ->where('source_type', AmazonSource::class)
            ->where('offers_off_amazon_shipping', true)
            ->exists();
    }

    /**
     * Every special service is not implemented. The sandbox `EXTERNAL` rate
     * carried no value-added service groups, so until a production quote shows
     * one, a shipment that requires a special service gets no Amazon Shipping
     * offer and a default one is dropped from the request.
     */
    public function offerCapability(string $serviceCode): ServiceCapability
    {
        return ServiceCapability::NotImplemented;
    }

    public function offerDeclaredValueCap(): ?float
    {
        return null;
    }

    public function serviceCapability(string $serviceCode): ServiceCapability
    {
        return ServiceCapability::NotImplemented;
    }

    public function declaredValueCap(): ?float
    {
        return null;
    }

    public function supportsMultiPackage(): bool
    {
        return false;
    }

    /**
     * Amazon Shipping has no manifest of ours to create. End of Day lists it
     * for its date and label count alone.
     */
    public function supportsCarrierManifest(): bool
    {
        return false;
    }

    public function supportsTracking(): bool
    {
        return true;
    }

    /**
     * @param  array<string>  $serviceCodes
     */
    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
    {
        [$source, $rateRequest] = $this->rateRequestFor($request) ?? [null, null];

        if (! $source || ! $rateRequest) {
            return null;
        }

        return new PreparedRateRequest(
            pendingRequest: app(AmazonBuyShippingService::class)->connectorFor($source)->createPendingRequest($rateRequest),
            carrierName: Carrier::AMAZON_SHIPPING,
        );
    }

    /**
     * @param  array<string>  $serviceCodes
     * @return Collection<int, RateResponse>
     *
     * @throws CarrierUnavailableException when the connection is not set up to sell Amazon Shipping
     */
    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        [$source, $rateRequest] = $this->rateRequestFor($request) ?? [null, null];

        if (! $source || ! $rateRequest) {
            return collect();
        }

        try {
            $response = app(AmazonBuyShippingService::class)->connectorFor($source)->send($rateRequest);
        } catch (RequestException $e) {
            // The connector retries, and throws once it gives up. What it
            // gave up on is still Amazon's answer, and read the same way.
            $response = $e->getResponse();
        } catch (FatalRequestException $e) {
            throw new CarrierRateFetchException(Carrier::AMAZON_SHIPPING, $e);
        }

        return $this->parseRateResponse($response, $request, $serviceCodes);
    }

    /**
     * @param  array<string>  $serviceCodes  The method's Amazon Shipping service codes, the only ones kept
     * @return Collection<int, RateResponse>
     *
     * @throws CarrierUnavailableException when the connection is not set up to sell Amazon Shipping
     */
    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
    {
        $package = $this->packageFor($request);

        if (! $package) {
            return collect();
        }

        // Read off the request that was sent, never resolved again: a scope
        // edited or a connection switched off while the request was in flight
        // must not move this reply, its offers or its A-101 onto another
        // connection (ADR-0002 decision 4).
        $source = $this->connectionThatSent($response);

        if (! $source) {
            logger()->warning('Amazon Shipping getRates reply names no connection it was sent for', [
                'package_id' => $package->id,
                'status' => $response->status(),
            ]);

            return collect();
        }

        if (! $response->successful()) {
            if (OffAmazonShippingCheck::refusesAccount($response)) {
                $this->recordOffAmazonShippingStatus($source, OffAmazonShippingStatus::NotSetUp);

                throw new CarrierUnavailableException(
                    Carrier::AMAZON_SHIPPING,
                    "The Amazon connection \"{$source->name}\" is not set up to sell Amazon Shipping for orders from other channels, so it has no offers for this package. "
                    .OffAmazonShippingCheck::NOT_SET_UP_MESSAGE,
                );
            }

            logger()->warning('Amazon Shipping getRates failed', [
                'package_id' => $package->id,
                'status' => $response->status(),
                'errors' => $response->json('errors'),
            ]);

            return collect();
        }

        $this->recordOffAmazonShippingStatus($source, OffAmazonShippingStatus::Enabled);

        return $this->ratesFrom(
            AmazonShippingQuote::fromPayload($response->json('payload', [])),
            $package,
            $request,
            $source,
            $serviceCodes,
        );
    }

    /**
     * Never reached: a *Use* rule naming a service sold on a connection
     * selects among quoted rates, because a purchase needs the Offer only a
     * quote issues ({@see RuleEvaluator}). Null sends a caller to rate
     * shopping rather than to a purchase that could not be made.
     */
    public function resolvePreSelectedRate(RateResponse $rate, Package $package): ?RateResponse
    {
        return null;
    }

    /**
     * Amazon Shipping Ground is sold in the packer's own packaging, and is the
     * only service Amazon Shipping has quoted us.
     */
    public function packagingRequirementFor(RateResponse $rate): PackagingRequirement
    {
        return PackagingRequirement::shipperPackaging();
    }

    /**
     * Bought through the Buy Shipping adapter, which already buys any offer a
     * connection issued. The purchase is the same `purchaseShipment` either way
     * (`amazon-shipping-external-orders/06`), and the offer's postage source
     * dispatches it there.
     */
    public function createShipment(ShipRequest $request): ShipResponse
    {
        return app(AmazonBuyShippingAdapter::class)->createShipment($request);
    }

    public function recoverPurchase(ShipRequest $request): ?ShipResponse
    {
        return app(AmazonBuyShippingAdapter::class)->recoverPurchase($request);
    }

    /**
     * The scoped connection to ask and the `getRates` to send it, or null when
     * no connection sells this package Amazon Shipping.
     *
     * @return array{0: DataSource, 1: GetShippingRates}|null
     */
    private function rateRequestFor(RateRequest $request): ?array
    {
        $package = $this->packageFor($request);

        if (! $package || $request->packages === []) {
            return null;
        }

        $source = app(PostageSourceResolver::class)->offAmazonShippingSourceFor($package);

        if (! $source) {
            return null;
        }

        $rateRequest = new GetShippingRates(
            app(AmazonBuyShippingService::class)->buildOffAmazonRatePayload($package, $request),
            AmazonBuyShippingService::BUSINESS_ID,
        );

        // A refusal is an answer this adapter reads — `403 A-101` says the
        // connection is not set up to sell Amazon Shipping — so it has to reach
        // parseRateResponse() as a response. Left to Guzzle, the concurrent
        // path would turn it into a rejected promise nobody here sees.
        $rateRequest->config()->add('http_errors', false);

        return [$source, $rateRequest];
    }

    /**
     * Keep the connection's answer current from what a quote found.
     *
     * A production quote is the same question the connection's check asks, so
     * its answer is recorded the same way: `403 A-101` means not set up, and a
     * `200` means enabled — which also clears a stale "not set up" once the
     * seller finishes sign-up. The sandbox quotes every account, so a sandbox
     * answer proves nothing and is not recorded, as the check does not ask.
     */
    private function recordOffAmazonShippingStatus(DataSource $source, OffAmazonShippingStatus $status): void
    {
        if (SourceEnvironment::current() !== SourceEnvironment::Production
            || $source->off_amazon_shipping_status === $status) {
            return;
        }

        $source->forceFill([
            'off_amazon_shipping_status' => $status,
            'off_amazon_shipping_checked_at' => now(),
        ])->save();
    }

    /**
     * Turn one `getRates` reply into rates and offers.
     *
     * A rate is kept when its service is an authored Amazon Shipping service
     * the method lists, and it passes the same buyability filters a Buy
     * Shipping rate does. An identity nobody has authored is recorded and
     * dropped. An authored service the method does not list is dropped
     * quietly, as a direct carrier's unlisted service is.
     *
     * @param  array<string>  $serviceCodes
     * @return Collection<int, RateResponse>
     */
    private function ratesFrom(AmazonShippingQuote $quote, Package $package, RateRequest $request, DataSource $source, array $serviceCodes): Collection
    {
        $marketplace = app(AmazonBuyShippingService::class)->marketplaceIdFor($source);

        $catalog = CarrierService::query()
            ->whereHas('carrier', fn ($query) => $query->where('name', Carrier::AMAZON_SHIPPING))
            ->whereIn('service_code', collect([...$quote->rates, ...$quote->ineligibleRates])
                ->pluck('serviceId')
                ->filter()
                ->unique()
                ->values())
            ->oldest('id')
            ->get()
            ->unique('service_code')
            ->keyBy('service_code');

        $this->recordUnauthored($quote, $catalog, $marketplace);

        $expiresAt = now()->addSeconds(AmazonBuyShippingService::OFFER_WINDOW_SECONDS);
        $offerStore = app(OfferStore::class);

        return collect($quote->rates)
            ->map(fn (array $rate): array => [$rate, $catalog->get((string) ($rate['serviceId'] ?? ''))])
            ->filter(fn (array $pair): bool => $pair[1] instanceof CarrierService
                && in_array($pair[1]->service_code, $serviceCodes, true)
                && $this->isBuyable($pair[0], $request, $pair[1]))
            ->map(function (array $pair) use ($package, $expiresAt, $offerStore, $quote, $source, $marketplace): RateResponse {
                /** @var CarrierService $service */
                [$rate, $service] = $pair;

                $price = round((float) ($rate['totalCharge']['value'] ?? 0), 2);
                $packagingRequirement = PackagingRequirement::shipperPackaging();

                $offer = $offerStore->issue($package, new OfferDraft(
                    carrier: Carrier::AMAZON_SHIPPING,
                    postageSource: PostageSource::PostageDataSource,
                    postageDataSourceId: $source->id,
                    serviceCode: $service->service_code,
                    serviceName: $service->name,
                    price: $price,
                    currency: (string) ($rate['totalCharge']['unit'] ?? 'USD'),
                    rateMetadata: $this->rateMetadata($rate, $packagingRequirement),
                    purchaseContext: [
                        'requestToken' => $quote->requestToken,
                        'rateId' => (string) $rate['rateId'],
                    ],
                    expiresAt: $expiresAt,
                    marketplace: $marketplace,
                    carrierId: $service->carrier_id,
                    carrierServiceId: $service->id,
                    // No quote fingerprint here, for the reason the Buy
                    // Shipping adapter gives: `ShippingRateService` stamps
                    // the package-level one when it logs the quote.
                ));

                return new RateResponse(
                    carrier: Carrier::AMAZON_SHIPPING,
                    serviceCode: $service->service_code,
                    serviceName: $service->name,
                    price: $price,
                    deliveryDate: $rate['promise']['deliveryWindow']['end'] ?? null,
                    metadata: $offer->rate_metadata ?? [],
                    offerId: $offer->public_id,
                    packagingRequirement: $packagingRequirement,
                    carrierServiceId: $service->id,
                    carrierId: $service->carrier_id,
                );
            })
            ->values();
    }

    /**
     * Record every identity Amazon named that no Amazon Shipping service is
     * authored for, eligible or not, so the Map Carrier Services page shows
     * what production quotes before anyone can buy it.
     *
     * @param  Collection<string, CarrierService>  $catalog
     */
    private function recordUnauthored(AmazonShippingQuote $quote, Collection $catalog, ?string $marketplace): void
    {
        $observation = fn (array $rate, bool $eligible): ServiceObservation => new ServiceObservation(
            source: AmazonBuyShippingAdapter::OBSERVATION_SOURCE,
            externalCarrierId: (string) ($rate['carrierId'] ?? ''),
            externalServiceId: (string) ($rate['serviceId'] ?? ''),
            externalCarrierName: $rate['carrierName'] ?? null,
            externalServiceName: $rate['serviceName'] ?? null,
            marketplace: $marketplace,
            eligible: $eligible,
        );

        $observations = collect($quote->rates)
            ->map(fn (array $rate): ServiceObservation => $observation($rate, true))
            ->merge(collect($quote->ineligibleRates)
                ->map(fn (array $rate): ServiceObservation => $observation($rate, false)))
            ->filter(fn (ServiceObservation $o): bool => $o->externalCarrierId !== ''
                && $o->externalServiceId !== ''
                && ! $catalog->has($o->externalServiceId));

        if ($observations->isNotEmpty()) {
            app(ObservedServiceRecorder::class)->record($observations);
        }
    }

    /**
     * Whether this offer could actually be bought and printed. Checked before
     * an offer is issued, because an offer is purchase authority and a rate
     * nobody is shown must never hold one (ADR-0005 decision 5).
     *
     * No special service is ever requested ({@see offerCapability()}), so a
     * required value-added service group must have a free answer.
     *
     * @param  array<string, mixed>  $rate
     */
    private function isBuyable(array $rate, RateRequest $request, CarrierService $service): bool
    {
        $package = $request->packages[0] ?? null;

        return $this->hasPrintableDocument($rate)
            && $this->needsNoAdditionalInputs($rate)
            && $this->answersRequiredGroupsForFree($rate, [])
            && PackagingRequirement::shipperPackaging()->accepts($package?->carrierPackaging)
            && ($service->required_contents === null
                || in_array($service->required_contents, $package->qualifyingContents ?? [], true));
    }
}
