<?php

namespace App\Services;

use App\Contracts\AsyncRateQuoting;
use App\Contracts\BlindPurchaseSource;
use App\Contracts\CarrierAdapterInterface;
use App\Contracts\PostageOfferSource;
use App\DataTransferObjects\PostageSources\OfferDraft;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\PostageSource;
use App\Enums\ServiceCapability;
use App\Exceptions\Carriers\CarrierRateFetchException;
use App\Exceptions\Carriers\CarrierUnavailableException;
use App\Exceptions\InvalidPackageDimensionsException;
use App\Exceptions\NoActiveCarrierServicesException;
use App\Models\CarrierAccount;
use App\Models\CarrierService;
use App\Models\CarrierServiceSpecialService;
use App\Models\Package;
use App\Models\ShippingMethod;
use App\Models\ShippingOffer;
use App\Models\SpecialService;
use App\Services\Carriers\CarrierRegistry;
use App\Services\PostageSources\OfferStore;
use App\Services\Shipping\PackagingFilter;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Saloon\Http\Senders\GuzzleSender;

class ShippingRateService
{
    /**
     * Carriers excluded from the last getShippingRates() call due to prohibited services.
     * Keyed by carrier name, value is the human-readable reason.
     *
     * @var array<string, string>
     */
    private array $exclusions = [];

    /**
     * Blind-purchase offers advertised during the last getShippingRates() call.
     *
     * Kept apart from the rates rather than mixed in, because they are not
     * rates and must never be sorted against one (ADR-0003 decision 6). The
     * caller presents them separately and automation considers them only under
     * the explicit selection policy in the package shipping workflow.
     *
     * @var Collection<int, BlindPurchaseOffer>
     */
    private Collection $blindPurchaseOffers;

    /**
     * Configured sellers represented by the last getShippingRates() task set.
     *
     * @var array<int, string>
     */
    private array $configuredSourceNames = [];

    /**
     * Configured sellers whose successful response contained rates, but none
     * compatible with the Package's packaging.
     *
     * @var array<int, string>
     */
    private array $packagingIneligibleSourceNames = [];

    /** Package owning the configured-source and blind-offer snapshot. */
    private ?int $ratedPackageId = null;

    /** Shipping Method governing the snapshot; null means inference is not authorized. */
    private ?int $ratedShippingMethodId = null;

    public function __construct()
    {
        $this->blindPurchaseOffers = collect();
    }

    /**
     * @param  array<int, string>  $excludedIds
     * @return Collection<int, BlindPurchaseOffer>
     */
    public function getBlindPurchaseOffers(array $excludedIds = []): Collection
    {
        if ($excludedIds === []) {
            return $this->blindPurchaseOffers;
        }

        return $this->blindPurchaseOffers
            ->reject(fn (BlindPurchaseOffer $offer): bool => in_array($offer->id(), $excludedIds, true))
            ->values();
    }

    /**
     * Returns carriers excluded from the last getShippingRates() call.
     * Each entry is ['carrier' => string, 'reason' => string].
     *
     * @return array<int, array{carrier: string, reason: string}>
     */
    public function getExclusions(): array
    {
        return array_map(
            fn ($carrier, $reason): array => ['carrier' => $carrier, 'reason' => $reason],
            array_keys($this->exclusions),
            $this->exclusions,
        );
    }

    /**
     * Get shipping rates for a package from all applicable carriers.
     *
     * @return Collection<int, RateResponse>
     *
     * @throws NoActiveCarrierServicesException
     */
    public function getShippingRates(int $packageId): Collection
    {
        $this->configuredSourceNames = [];
        $this->packagingIneligibleSourceNames = [];
        $this->ratedPackageId = null;
        $this->ratedShippingMethodId = null;

        $package = Package::with(['packageItems', 'shipment.shippingMethod'])
            ->findOrFail($packageId);

        $destination = AddressData::fromShipment($package->shipment);
        $rateRequest = RateRequest::fromPackage($package, $destination);
        $carrierTasks = $this->buildCarrierTasks($package, $rateRequest, $destination);

        // One ship date per carrier, read once and used twice: the carrier is
        // quoted for it, and the offer's window ends with it. Reading it again
        // after the carrier calls would let a pickup cutoff or an End of Day
        // run in between hand the offer a later day than the one it was
        // priced for.
        $shipDates = $this->shipDatesFor($carrierTasks, $rateRequest->locationId);

        // Before the quote log: a rate the package's packaging rules out was
        // never offered, and must not be logged as one (ADR-0005 decision 4).
        $rateOptions = PackagingFilter::keepCompatible(
            $this->fetchRatesConcurrently($carrierTasks, $rateRequest, $shipDates),
            PackageData::fromPackage($package)->carrierPackaging,
        );

        $rates = $this->offer($package, $rateOptions, $shipDates, $rateRequest->fingerprint());
        $this->ratedPackageId = $package->id;
        $this->ratedShippingMethodId = $package->shipment?->shipping_method_id;

        return $rates;
    }

    /**
     * The ship date each carrier in these tasks will be quoted for.
     *
     * @param  array<int, array{name: string, serviceCodes: array<string>, specialServiceCodes: array<string>}>  $carrierTasks
     * @return array<string, CarbonImmutable>
     */
    private function shipDatesFor(array $carrierTasks, ?int $locationId): array
    {
        $shipDateService = app(ShipDateService::class);
        $shipDates = [];

        foreach ($carrierTasks as $task) {
            $shipDates[$task['name']] ??= $shipDateService->getShipDate($task['name'], $locationId);
        }

        return $shipDates;
    }

    /**
     * Log every surviving rate and put an offer behind each one.
     *
     * The one loop no adapter can bypass and a new adapter cannot forget,
     * placed after the packaging filter so a rate never offered never holds
     * an identifier. Runs on every quoting path — the Ship page, batch ship,
     * auto-ship — because the purchase path is shared and restores from the
     * offer whenever a rate carries one; the rows the unattended paths leave
     * behind are unconsumed and age out with the rest.
     *
     * A direct-carrier rate becomes a {@see ShippingOffer} with
     * `postage_source = CarrierAccount`, the account the adapter quoted on,
     * and no purchase context: the account still buys, but the price, service
     * and the metadata the adapter reads at purchase are now the server's
     * copy rather than the browser's. Its window is the end of the quoted
     * ship day in the location's timezone — nothing about a direct rate moves
     * intra-day — and it is bound to the rate request it answers and to the
     * quoting account's billing identity, so an edit to either retires it. A
     * rate that already carries an offer — Amazon issues its own from inside
     * `getRates()`, with the purchase tokens only it holds — is left as it
     * is, and only pointed at its quote row and stamped with the same quote
     * fingerprint — computed here, from the package-level request, because
     * the request an adapter holds has been narrowed to the codes that
     * carrier can express and would not digest the same.
     *
     * The quote log and the offers are written together so they can point at
     * each other: `markSelected()` marks by that pointer. A quote log that
     * fails to write is a warning, as before, and the offers are issued
     * without a pointer rather than not at all — the log is analytics, the
     * offer is what the purchase needs.
     *
     * @param  Collection<int, RateResponse>  $rates
     * @param  array<string, CarbonImmutable>  $shipDates  The date each carrier was quoted for, by carrier name
     * @param  string  $quoteFingerprint  {@see RateRequest::fingerprint()} of the request every rate here answers
     * @return Collection<int, RateResponse>
     */
    private function offer(Package $package, Collection $rates, array $shipDates, string $quoteFingerprint): Collection
    {
        $rates = $rates->values();

        // One read per account quoted, not per rate: several rates share one.
        $accountFingerprints = CarrierAccount::query()
            ->whereIn('id', $rates->pluck('carrierAccountId')->filter()->unique())
            ->get()
            ->mapWithKeys(fn (CarrierAccount $account): array => [$account->id => $account->fingerprint()]);

        try {
            $quoteIds = app(RateQuoteLogger::class)->logRates($package->id, $rates);
        } catch (\Exception $e) {
            logger()->warning('Failed to log rate quotes', [
                'package_id' => $package->id,
                'error' => $e->getMessage(),
            ]);

            $quoteIds = [];
        }

        $offerStore = app(OfferStore::class);
        $shipDateService = app(ShipDateService::class);

        return $rates->map(function (RateResponse $rate, int $index) use ($package, $quoteIds, $offerStore, $shipDateService, &$shipDates, $quoteFingerprint, $accountFingerprints): RateResponse {
            $quoteId = $quoteIds[$index] ?? null;

            if ($rate->offerId !== null) {
                ShippingOffer::query()
                    ->where('public_id', $rate->offerId)
                    ->whereNull('rate_quote_id')
                    ->update(array_filter([
                        'rate_quote_id' => $quoteId,
                        'quote_fingerprint' => $quoteFingerprint,
                    ]));

                return $rate;
            }

            // A rate normally names the carrier it was asked of. One that does
            // not — an adapter answering under a different carrier name — is
            // windowed on a date read now, which is the best available.
            $shipDates[$rate->carrier] ??= $shipDateService->getShipDate($rate->carrier, $package->location_id);

            $offer = $offerStore->issue($package, new OfferDraft(
                carrier: $rate->carrier,
                postageSource: PostageSource::CarrierAccount,
                carrierAccountId: $rate->carrierAccountId,
                serviceCode: $rate->serviceCode,
                serviceName: $rate->serviceName,
                price: $rate->priceUnknown ? null : $rate->price,
                currency: 'USD',
                // The packaging requirement travels with the metadata so the
                // purchase-time check classifies what the server quoted.
                rateMetadata: $rate->packagingRequirement->intoRateMetadata($rate->metadata),
                expiresAt: $shipDates[$rate->carrier]->endOfDay(),
                rateQuoteId: $quoteId,
                quoteFingerprint: $quoteFingerprint,
                carrierAccountFingerprint: $accountFingerprints->get($rate->carrierAccountId),
            ));

            return $rate->withOfferId($offer->public_id);
        });
    }

    /**
     * The blind-purchase offers this package is eligible for, asking nobody for
     * a rate.
     *
     * The purchase path's answer to "was this ever offered?". Everything that
     * decides eligibility runs again — the shipping method's services, the
     * destination, special-service capability, and each source's own gates
     * (client opt-in, a fulfillment order to buy against, a catalogued
     * selection) — while `fetchRatesConcurrently()` and its carrier calls are
     * skipped, because no rate is wanted and no money may be spent finding one.
     *
     * Sharing `buildCarrierTasks()` with quoting is the point: an offer is
     * eligible here exactly when it would have been advertised there, rather
     * than under a second copy of the rules that can drift from the first.
     *
     * @return Collection<int, BlindPurchaseOffer>
     *
     * @throws NoActiveCarrierServicesException
     */
    public function blindPurchaseOffersFor(Package $package): Collection
    {
        $this->configuredSourceNames = [];
        $this->packagingIneligibleSourceNames = [];
        $this->ratedPackageId = null;
        $this->ratedShippingMethodId = null;

        $destination = AddressData::fromShipment($package->shipment);
        $rateRequest = RateRequest::fromPackage($package, $destination);
        $registry = app(CarrierRegistry::class);
        $shipDateService = app(ShipDateService::class);

        foreach ($this->buildCarrierTasks($package, $rateRequest, $destination) as $task) {
            $source = $registry->blindPurchaseSourceFor($task['name']);

            if (! $source || ! $source->isConfigured()) {
                continue;
            }

            $this->blindPurchaseOffers = $this->blindPurchaseOffers->merge($source->blindPurchaseOffers(
                $rateRequest
                    ->withShipDate($shipDateService->getShipDate($task['name'], $rateRequest->locationId))
                    ->withSpecialServiceCodes($task['specialServiceCodes']),
                $task['serviceCodes'],
            ));
        }

        return $this->blindPurchaseOffers;
    }

    /**
     * The sole blind purchase automation may infer from a ShippingMethod.
     *
     * This is based on configured, package-eligible sellers, not on which rate
     * calls happened to return an answer. A configured direct seller therefore
     * prevents Shopify becoming a fallback during an outage. A shipping rule
     * can make a more specific choice separately.
     *
     * @param  array<int, string>  $excludedIds
     *
     * @throws \LogicException when no completed rate snapshot exists for this package
     */
    public function soleBlindPurchaseOfferForAutomation(int $packageId, array $excludedIds = []): ?BlindPurchaseOffer
    {
        if ($this->ratedPackageId !== $packageId) {
            throw new \LogicException('soleBlindPurchaseOfferForAutomation() must follow getShippingRates() for the same package.');
        }

        $registry = app(CarrierRegistry::class);

        $eligibleSourceNames = array_values(array_diff(
            $this->configuredSourceNames,
            $this->packagingIneligibleSourceNames,
        ));

        if ($this->ratedShippingMethodId === null || count($eligibleSourceNames) !== 1) {
            return null;
        }

        $source = $registry->blindPurchaseSourceFor($eligibleSourceNames[0]);

        if (! $source) {
            return null;
        }

        $offers = $this->getBlindPurchaseOffers($excludedIds);

        return $offers->count() === 1 ? $offers->first() : null;
    }

    /**
     * Which sources may be asked for this package, and with what.
     *
     * Resets the exclusions and offers recorded from any earlier call, so a
     * caller reads the reasons belonging to the tasks it just built.
     *
     * @return array<int, array{name: string, serviceCodes: array<string>, specialServiceCodes: array<string>}>
     *
     * @throws NoActiveCarrierServicesException
     */
    private function buildCarrierTasks(
        Package $package,
        RateRequest $rateRequest,
        AddressData $destination,
    ): array {
        $shipment = $package->shipment;
        $shippingMethod = $shipment->shippingMethod;

        $resolver = app(SpecialServiceResolver::class);
        $methodCodes = $resolver->methodCodesByMode($shippingMethod);
        $productCodes = $resolver->resolveProductRequiredCodes($package)->keys()->all();
        $requiredCodes = array_values(array_unique([...$methodCodes['required'], ...$productCodes]));
        $defaultCodes = array_values(array_diff($methodCodes['default'], $requiredCodes));

        // Superseded variants never travel together (adult signature implies signature)
        if (in_array('adult_signature_required', [...$requiredCodes, ...$defaultCodes], true)) {
            $requiredCodes = array_values(array_diff($requiredCodes, ['signature_required']));
            $defaultCodes = array_values(array_diff($defaultCodes, ['signature_required']));
        }

        $this->exclusions = [];
        $this->blindPurchaseOffers = collect();
        $scopeMap = $this->loadServiceScopes([...$requiredCodes, ...$defaultCodes]);
        $serviceNames = SpecialService::whereIn('code', [...$requiredCodes, ...$defaultCodes])
            ->pluck('name', 'code');

        $carrierTasks = [];

        if ($shippingMethod) {
            $activeCarrierServices = $this->getActiveCarrierServices($shippingMethod, $destination);

            if ($activeCarrierServices->isEmpty()) {
                throw new NoActiveCarrierServicesException($shippingMethod->name);
            }

            logger()->debug('ShippingRateService: Getting rates', [
                'package_id' => $package->id,
                'shipping_method' => $shippingMethod->name,
                'active_carrier_services_count' => $activeCarrierServices->count(),
                'carrier_services' => $activeCarrierServices->pluck('service_code', 'name')->toArray(),
            ]);

            $carrierServicesByCarrier = $activeCarrierServices->groupBy('carrier_id');

            foreach ($carrierServicesByCarrier as $services) {
                $task = $this->buildCarrierTask(
                    $services->first()->carrier->name,
                    $services,
                    $requiredCodes,
                    $defaultCodes,
                    $scopeMap,
                    $serviceNames,
                    $rateRequest,
                );

                if ($task) {
                    $carrierTasks[] = $task;
                }
            }

            return $carrierTasks;
        }

        logger()->debug('ShippingRateService: No shipping method assigned, querying all configured carriers', [
            'package_id' => $package->id,
        ]);

        $restrictedDestination = $destination->isPoBox() || $destination->isMilitary();

        foreach (array_keys(app(CarrierRegistry::class)->getConfiguredAdapters()) as $name) {
            $services = $this->getActiveCarrierServicesForCarrierName($name, $destination);

            if ($restrictedDestination && $services->isEmpty()) {
                // No cataloged service for this carrier is known to reach a PO
                // Box / military destination -- querying it blind risks a
                // carrier-side reject (e.g. UPS 400s on a military "AE" state).
                logger()->debug("ShippingRateService: {$name} has no cataloged service for this destination, skipping", [
                    'package_id' => $package->id,
                ]);

                continue;
            }

            $task = $this->buildCarrierTask(
                $name,
                $services,
                $requiredCodes,
                $defaultCodes,
                $scopeMap,
                $serviceNames,
                $rateRequest,
            );

            if ($task) {
                $carrierTasks[] = $task;
            }
        }

        return $carrierTasks;
    }

    /**
     * Fetch rates from multiple carriers concurrently using a shared Guzzle sender.
     *
     * @param  array<int, array{name: string, serviceCodes: array<string>, specialServiceCodes: array<string>}>  $carrierTasks
     * @param  array<string, CarbonImmutable>  $shipDates  The date to quote each carrier for, by carrier name
     * @return Collection<int, RateResponse>
     */
    private function fetchRatesConcurrently(array $carrierTasks, RateRequest $rateRequest, array $shipDates): Collection
    {
        $rateOptions = collect();
        $preparedRequests = [];
        $taskMeta = [];

        $registry = app(CarrierRegistry::class);

        // Phase 1: Prepare all requests (authenticate connectors, build request bodies)
        foreach ($carrierTasks as $task) {
            $carrierName = $task['name'];
            $serviceCodes = $task['serviceCodes'];

            try {
                if (! $registry->has($carrierName)) {
                    logger()->warning("ShippingRateService: Unknown carrier {$carrierName}");

                    continue;
                }

                $adapter = $registry->get($carrierName);

                if (! $adapter->isConfigured()) {
                    logger()->warning("ShippingRateService: {$carrierName} is not configured");

                    continue;
                }

                $this->configuredSourceNames[] = $carrierName;

                $carrierRateRequest = $rateRequest
                    ->withShipDate($shipDates[$carrierName])
                    ->withSpecialServiceCodes($task['specialServiceCodes']);

                if ($adapter instanceof AsyncRateQuoting) {
                    $prepared = $adapter->prepareRateRequest($carrierRateRequest, $serviceCodes);

                    if ($prepared) {
                        $preparedRequests[$carrierName] = $prepared;
                        $taskMeta[$carrierName] = ['adapter' => $adapter, 'serviceCodes' => $serviceCodes, 'rateRequest' => $carrierRateRequest];

                        continue;
                    }
                }

                // A source with no rate API at all advertises a purchase rather
                // than quoting one. Collected separately: it has no price, so
                // there is nothing to compare it with and nowhere in this
                // collection it could honestly sit.
                if ($adapter instanceof BlindPurchaseSource) {
                    $this->blindPurchaseOffers = $this->blindPurchaseOffers->merge(
                        $adapter->blindPurchaseOffers($carrierRateRequest, $serviceCodes)
                    );

                    continue;
                }

                // Nothing to send: mock rates, or an adapter that declined to
                // prepare one. Ask it synchronously instead. Only sources that
                // got this far can reach the parse phase below, which is why the
                // two halves of AsyncRateQuoting travel together.
                if ($adapter instanceof CarrierAdapterInterface) {
                    $rates = $adapter->getRates($carrierRateRequest, $serviceCodes);
                    $this->recordPackagingEligibility($carrierName, $rates, $carrierRateRequest);
                    $rateOptions->push(...$rates);
                }
            } catch (CarrierUnavailableException $e) {
                $this->recordUnavailable($carrierName, $e);
            } catch (InvalidPackageDimensionsException $e) {
                $this->exclusions[$carrierName] = $carrierName.' requires valid package dimensions before rates can be requested.';

                logger()->warning("ShippingRateService: {$carrierName} cannot rate an unmeasured package", [
                    'carrier' => $carrierName,
                    'error' => $e->getMessage(),
                ]);
            } catch (CarrierRateFetchException $e) {
                $loggedException = $e->getPrevious() ?? $e;

                logger()->error("ShippingRateService: {$carrierName} rate fetch failed", [
                    'carrier' => $carrierName,
                    'exception' => $loggedException::class,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Exception $e) {
                logger()->error("ShippingRateService: {$carrierName} prepare error", [
                    'carrier' => $carrierName,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fall back to synchronous sends when only one carrier needs an API call (no
        // concurrency overhead needed) or when any request has a fake/mock response set
        // (Saloon faking bypasses the sender, so the shared GuzzleSender can't handle it).
        $hasFakeResponses = collect($preparedRequests)->contains(
            fn (PreparedRateRequest $p): bool => $p->pendingRequest->hasFakeResponse()
        );

        if (count($preparedRequests) <= 1 || $hasFakeResponses) {
            foreach ($preparedRequests as $carrierName => $prepared) {
                $meta = $taskMeta[$carrierName];

                try {
                    $rates = $meta['adapter']->getRates($meta['rateRequest'], $meta['serviceCodes']);
                    $this->recordPackagingEligibility($carrierName, $rates, $meta['rateRequest']);
                    $rateOptions->push(...$rates);
                } catch (CarrierUnavailableException $e) {
                    $this->recordUnavailable($carrierName, $e);
                } catch (CarrierRateFetchException $e) {
                    $loggedException = $e->getPrevious() ?? $e;

                    logger()->error("ShippingRateService: {$carrierName} rate fetch failed", [
                        'carrier' => $carrierName,
                        'exception' => $loggedException::class,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $rateOptions;
        }

        // Phase 2: Send all requests concurrently through a shared Guzzle sender.
        // Resolved from the container, like every other collaborator here, so a
        // test can drive the concurrent path — the fake-response check above
        // routes anything Saloon has faked down the synchronous branch instead.
        $sharedSender = app(GuzzleSender::class);
        $promises = [];

        foreach ($preparedRequests as $carrierName => $prepared) {
            logger()->debug("ShippingRateService: Sending async rate request to {$carrierName}");
            $promises[$carrierName] = $sharedSender->sendAsync($prepared->pendingRequest);
        }

        $results = PromiseUtils::settle($promises)->wait();

        // Phase 3: Parse responses
        foreach ($results as $carrierName => $result) {
            $meta = $taskMeta[$carrierName];

            if ($result['state'] === 'fulfilled') {
                try {
                    $rates = $meta['adapter']->parseRateResponse(
                        $result['value'],
                        $meta['rateRequest'],
                        $meta['serviceCodes'],
                    );

                    logger()->debug("ShippingRateService: Got {$carrierName} rates", [
                        'rates_count' => $rates->count(),
                    ]);

                    $this->recordPackagingEligibility($carrierName, $rates, $meta['rateRequest']);
                    $rateOptions->push(...$rates);
                } catch (CarrierUnavailableException $e) {
                    $this->recordUnavailable($carrierName, $e);
                } catch (\Exception $e) {
                    logger()->error("ShippingRateService: {$carrierName} parse error", [
                        'carrier' => $carrierName,
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                logger()->error("ShippingRateService: {$carrierName} request failed", [
                    'error' => $result['reason']?->getMessage() ?? 'Unknown error',
                ]);
            }
        }

        return $rateOptions;
    }

    /**
     * A source that answered, or could not be asked, because of how it is set
     * up — an Amazon connection not signed up for Amazon Shipping, say. That
     * is not a failed request: it has no offers, and the packer is told why
     * beside the rates rather than left to wonder where they went.
     */
    private function recordUnavailable(string $sourceName, CarrierUnavailableException $e): void
    {
        $this->exclusions[$sourceName] = $e->getMessage();

        logger()->warning("ShippingRateService: {$sourceName} is unavailable", [
            'carrier' => $sourceName,
            'reason' => $e->getMessage(),
        ]);
    }

    /**
     * A successful non-empty response proves a seller is ineligible when every
     * returned rate conflicts with the Package's packaging. Empty responses and
     * failures remain conservatively eligible so Shopify cannot become an
     * outage fallback.
     *
     * @param  Collection<int, RateResponse>  $rates
     */
    private function recordPackagingEligibility(string $sourceName, Collection $rates, RateRequest $rateRequest): void
    {
        if ($rates->isEmpty()) {
            return;
        }

        $carrierPackaging = $rateRequest->packages[0]->carrierPackaging ?? null;

        if (PackagingFilter::keepCompatible($rates, $carrierPackaging)->isEmpty()) {
            $this->packagingIneligibleSourceNames[] = $sourceName;
        }
    }

    /**
     * Build the rate task for one carrier, applying capability and carrier-service
     * scope checks. Hard-required codes (shipping-method required mode + product
     * compliance) drop carrier services that aren't scoped for them and exclude
     * the carrier entirely when nothing survives (or the carrier prohibits /
     * hasn't implemented the code). Default-mode codes never drop a carrier
     * service — the code is stripped from the carrier's request instead.
     *
     * Returns null (recording the reason in $this->exclusions) when the carrier
     * is excluded.
     *
     * @param  Collection<int, CarrierService>  $services  Empty when no shipping method is assigned
     * @param  array<int, string>  $requiredCodes
     * @param  array<int, string>  $defaultCodes
     * @param  array<string, array<int, array<int, array<int, string>|null>>>  $scopeMap
     * @param  Collection<string, string>  $serviceNames
     * @return array{name: string, serviceCodes: array<int, string>, specialServiceCodes: array<int, string>}|null
     */
    private function buildCarrierTask(
        string $carrierName,
        Collection $services,
        array $requiredCodes,
        array $defaultCodes,
        array $scopeMap,
        Collection $serviceNames,
        RateRequest $rateRequest,
    ): ?array {
        $destinationCountry = $rateRequest->destinationCountry;
        $registry = app(CarrierRegistry::class);
        $specialServiceCodes = [];

        $adapter = $registry->has($carrierName) ? $registry->get($carrierName) : null;

        foreach ($requiredCodes as $code) {
            $capability = $adapter?->offerCapability($code);

            if ($capability !== null && $capability !== ServiceCapability::Supported) {
                // Prohibited, Unguaranteed and NotImplemented all exclude: a
                // hard-required service the offer can't actually apply must not
                // be skipped.
                $this->exclusions[$carrierName] = $this->requiredServiceExclusion(
                    $capability,
                    $carrierName,
                    $serviceNames->get($code, $code),
                );

                return null;
            }

            if ($capReason = $this->declaredValueCapViolation($code, $adapter, $rateRequest, $carrierName)) {
                $this->exclusions[$carrierName] = $capReason;

                return null;
            }

            $carrierScopes = $this->scopesForCarrier($scopeMap, $code, $services);

            if ($carrierScopes !== null) {
                $services = $services->filter(
                    fn (CarrierService $service): bool => $this->scopeAllows($carrierScopes, $service->id, $destinationCountry)
                );

                if ($services->isEmpty()) {
                    $this->exclusions[$carrierName] = $carrierName.' has no services that support '.$serviceNames->get($code, $code).' for this destination.';

                    return null;
                }
            }

            $specialServiceCodes[] = $code;
        }

        foreach ($defaultCodes as $code) {
            if ($adapter) {
                $capability = $adapter->offerCapability($code);

                if ($capability === ServiceCapability::Prohibited) {
                    $this->exclusions[$carrierName] = $carrierName.' does not support '.$serviceNames->get($code, $code).'.';

                    return null;
                }

                // A preference this offer cannot express is dropped, not fatal —
                // the same treatment as a code we have not wired up.
                if ($capability === ServiceCapability::NotImplemented || $capability === ServiceCapability::Unguaranteed) {
                    continue;
                }
            }

            if ($capReason = $this->declaredValueCapViolation($code, $adapter, $rateRequest, $carrierName)) {
                // Stripping a default declared value silently would under-insure
                // the package — exclude the carrier visibly instead.
                $this->exclusions[$carrierName] = $capReason;

                return null;
            }

            $carrierScopes = $this->scopesForCarrier($scopeMap, $code, $services);

            if ($carrierScopes !== null && $services->doesntContain(
                fn (CarrierService $service): bool => $this->scopeAllows($carrierScopes, $service->id, $destinationCountry)
            )) {
                logger()->debug("ShippingRateService: {$carrierName} has no services scoped for default service {$code}, requesting rates without it");

                continue;
            }

            $specialServiceCodes[] = $code;
        }

        return [
            'name' => $carrierName,
            'serviceCodes' => $services->pluck('service_code')->values()->all(),
            'specialServiceCodes' => $specialServiceCodes,
        ];
    }

    /**
     * Why a hard-required special service dropped this offer.
     *
     * Unguaranteed reads differently on purpose: the carrier behind a Shopify
     * offer may well support the service, and telling an operator that "Shopify
     * does not support Signature Required" would send them looking for a
     * carrier setting to change. The problem is that nobody has picked the
     * carrier yet.
     */
    private function requiredServiceExclusion(ServiceCapability $capability, string $carrierName, string $serviceName): string
    {
        return $capability === ServiceCapability::Unguaranteed
            ? $carrierName.' cannot guarantee '.$serviceName.' — it picks the carrier and service after the label is bought.'
            : $carrierName.' does not support '.$serviceName.'.';
    }

    /**
     * Exclusion reason when the package's declared value exceeds the carrier's
     * cap, or null when the code isn't declared_value / no cap applies.
     */
    private function declaredValueCapViolation(
        string $code,
        ?PostageOfferSource $adapter,
        RateRequest $rateRequest,
        string $carrierName,
    ): ?string {
        if ($code !== 'declared_value' || ! $adapter) {
            return null;
        }

        $cap = $adapter->offerDeclaredValueCap();
        $amount = $rateRequest->specialServiceConfig('declared_value')['amount'] ?? null;

        if ($cap === null || $amount === null || $amount <= $cap) {
            return null;
        }

        return sprintf(
            '%s cannot declare a value of $%s — its maximum is $%s.',
            $carrierName,
            number_format((float) $amount, 2),
            number_format($cap, 0),
        );
    }

    /**
     * Carrier-service scope rows for one code, limited to this carrier.
     * Returns null when the code has no rows for any of this carrier's services —
     * the code is unscoped for this carrier and only the carrier-wide capability
     * check applies. Also null when there is no carrier-service list to filter
     * (no shipping method assigned).
     *
     * @param  array<string, array<int, array<int, array<int, string>|null>>>  $scopeMap
     * @param  Collection<int, CarrierService>  $services
     * @return array<int, array<int, string>|null>|null carrier_service_id => restricted_countries
     */
    private function scopesForCarrier(array $scopeMap, string $code, Collection $services): ?array
    {
        if ($services->isEmpty() || ! isset($scopeMap[$code])) {
            return null;
        }

        $carrierId = $services->first()->carrier_id;

        return $scopeMap[$code][$carrierId] ?? null;
    }

    /**
     * @param  array<int, array<int, string>|null>  $carrierScopes  carrier_service_id => restricted_countries
     */
    private function scopeAllows(array $carrierScopes, int $carrierServiceId, string $destinationCountry): bool
    {
        if (! array_key_exists($carrierServiceId, $carrierScopes)) {
            return false;
        }

        $restrictedCountries = $carrierScopes[$carrierServiceId];

        return empty($restrictedCountries) || in_array($destinationCountry, $restrictedCountries, true);
    }

    /**
     * Load carrier_service_special_service rows for the given codes into a
     * lookup of code => carrier_id => carrier_service_id => restricted_countries.
     *
     * @param  array<int, string>  $codes
     * @return array<string, array<int, array<int, array<int, string>|null>>>
     */
    private function loadServiceScopes(array $codes): array
    {
        if (empty($codes)) {
            return [];
        }

        $codesByServiceId = SpecialService::whereIn('code', $codes)->pluck('code', 'id');

        if ($codesByServiceId->isEmpty()) {
            return [];
        }

        $rows = CarrierServiceSpecialService::whereIn('special_service_id', $codesByServiceId->keys())->get();

        $carrierIdsByServiceId = CarrierService::whereIn('id', $rows->pluck('carrier_service_id'))
            ->pluck('carrier_id', 'id');

        $map = [];

        foreach ($rows as $row) {
            $code = $codesByServiceId[$row->special_service_id];
            $carrierId = (int) $carrierIdsByServiceId[$row->carrier_service_id];

            $map[$code][$carrierId][(int) $row->carrier_service_id] = $row->restricted_countries;
        }

        return $map;
    }

    /**
     * The sources rate shopping would ask for this shipping method and
     * destination: the carriers of its active services that can reach the
     * destination, with a registered, configured adapter behind them.
     *
     * The same two filters {@see buildCarrierTasks()} and the task runner
     * apply — {@see getActiveCarrierServices()} and `isConfigured()` — so a
     * caller deciding ahead of a purchase what the purchase could buy reads
     * the same set the purchase will. `BatchLabelService` asks this before a
     * batch starts, for the report printer skip: an unconfigured carrier, or
     * one whose services cannot reach a PO Box or military address, would
     * otherwise count as an option the batch does not actually have.
     *
     * Special-service exclusions are not applied here; they are per-package
     * and resolved once the rate request exists.
     *
     * @return Collection<int, PostageOfferSource>
     */
    public function sellersForShippingMethod(ShippingMethod $shippingMethod, AddressData $destination): Collection
    {
        $registry = app(CarrierRegistry::class);

        return $this->getActiveCarrierServices($shippingMethod, $destination)
            ->map(fn (CarrierService $service): ?string => $service->carrier?->name)
            ->filter(fn (?string $name): bool => $name !== null && $registry->has($name))
            ->unique()
            ->map(fn (string $name): PostageOfferSource => $registry->get($name))
            ->filter(fn (PostageOfferSource $seller): bool => $seller->isConfigured())
            ->values();
    }

    /**
     * Get active carrier services for a shipping method.
     * Filters to only include services where both the carrier and the service are active.
     *
     * @return Collection<int, CarrierService>
     */
    private function getActiveCarrierServices(ShippingMethod $shippingMethod, AddressData $destination): Collection
    {
        $query = $shippingMethod->carrierServices()
            ->active()
            ->withActiveCarrier()
            ->with('carrier');

        if ($destination->isPoBox()) {
            $query->where('can_ship_to_po_boxes', true);
        }

        if ($destination->isMilitary()) {
            $query->where('can_ship_to_military_addresses', true);
        }

        return $query->get();
    }

    /**
     * Active CarrierService rows for a carrier name, used by the no-shipping-method
     * fallback. For an ordinary destination this returns an empty collection,
     * preserving that fallback's existing "ask the adapter for everything, no
     * service-code restriction" behavior. For a PO Box / military destination
     * it returns only the carrier's cataloged services flagged capable of
     * reaching it -- which may be empty if the carrier has no such service (or
     * no catalog rows at all), in which case the caller must not query it.
     *
     * @return Collection<int, CarrierService>
     */
    private function getActiveCarrierServicesForCarrierName(string $carrierName, AddressData $destination): Collection
    {
        if (! $destination->isPoBox() && ! $destination->isMilitary()) {
            return collect();
        }

        return CarrierService::query()
            ->active()
            ->withActiveCarrier()
            ->whereHas('carrier', fn (Builder $query) => $query->where('name', $carrierName))
            ->when($destination->isPoBox(), fn (Builder $query) => $query->where('can_ship_to_po_boxes', true))
            ->when($destination->isMilitary(), fn (Builder $query) => $query->where('can_ship_to_military_addresses', true))
            ->get();
    }
}
