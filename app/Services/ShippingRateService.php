<?php

namespace App\Services;

use App\Contracts\AsyncRateQuoting;
use App\Contracts\BlindPurchaseSource;
use App\Contracts\CarrierAdapterInterface;
use App\Contracts\DeclaresSellableServices;
use App\Contracts\PostageOfferSource;
use App\DataTransferObjects\PostageSources\OfferDraft;
use App\DataTransferObjects\PostageSources\PostageSourceCandidate;
use App\DataTransferObjects\PostageSources\PostageSourceResolution;
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
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierService;
use App\Models\CarrierServiceSpecialService;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingOffer;
use App\Models\SpecialService;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\PostageSources\OfferStore;
use App\Services\PostageSources\PostageSourceResolver;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\Shipping\ContentsFilter;
use App\Services\Shipping\PackagingFilter;
use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Saloon\Http\Senders\GuzzleSender;

/**
 * @phpstan-type RatingTask array{key: string, source: string, label: string, candidate: PostageSourceCandidate, carrierAccount: CarrierAccount|null, serviceCodes: array<int, string>, specialServiceCodes: array<int, string>}
 */
class ShippingRateService
{
    /**
     * Sources excluded from the last getShippingRates() call, keyed by the
     * source instance's key. `carrier` is what an operator reads, `source` the
     * registry name a caller can match on.
     *
     * @var array<string, array{carrier: string, source: string, reason: string}>
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
     * Configured sellers represented by the last getShippingRates() task set:
     * each task's key, and the registry name of the source behind it.
     *
     * @var array<string, string>
     */
    private array $configuredSources = [];

    /**
     * Keys of configured sellers whose successful response contained rates,
     * but none compatible with the Package's packaging.
     *
     * @var array<int, string>
     */
    private array $packagingIneligibleSourceKeys = [];

    /** Package owning the configured-source and blind-offer snapshot. */
    private ?int $ratedPackageId = null;

    /** Shipping Method governing the snapshot; null means inference is not authorized. */
    private ?int $ratedShippingMethodId = null;

    public function __construct()
    {
        $this->blindPurchaseOffers = collect();
    }

    /**
     * @param  (Closure(BlindPurchaseOffer): bool)|null  $excludes  Whether a shipping rule excludes the offer
     * @return Collection<int, BlindPurchaseOffer>
     */
    public function getBlindPurchaseOffers(?Closure $excludes = null): Collection
    {
        if ($excludes === null) {
            return $this->blindPurchaseOffers;
        }

        return $this->blindPurchaseOffers->reject($excludes)->values();
    }

    /**
     * Returns sources excluded from the last getShippingRates() call.
     *
     * `carrier` names the source as an operator reads it: a carrier's label,
     * or the name of the channel's catalog row. `source` is its registry name.
     *
     * @return array<int, array{carrier: string, source: string, reason: string}>
     */
    public function getExclusions(): array
    {
        return array_values($this->exclusions);
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
        $this->configuredSources = [];
        $this->packagingIneligibleSourceKeys = [];
        $this->ratedPackageId = null;
        $this->ratedShippingMethodId = null;

        $package = Package::with(['packageItems', 'shipment.shippingMethod'])
            ->findOrFail($packageId);

        $destination = AddressData::fromShipment($package->shipment);
        $rateRequest = RateRequest::fromPackage($package, $destination);
        $tasks = $this->buildRatingTasks($package, $rateRequest, $destination);

        // One ship date per task, read once and used twice: the source is
        // quoted for it, and the offer's window ends with it. Reading it again
        // after the calls would let a pickup cutoff or an End of Day run in
        // between hand the offer a later day than the one it was priced for.
        $shipDates = $this->shipDatesFor($tasks, $rateRequest->locationId);

        // Before the quote log: a rate the package's packaging rules out was
        // never offered, and must not be logged as one (ADR-0005 decision 4).
        // Nor was one for a service whose required contents the package does
        // not have, such as Media Mail for a parcel that is not all media
        // (ADR-0006 decision 11). Each rate keeps the key of the task that
        // quoted it, so its offer is windowed on that task's date.
        $packageData = PackageData::fromPackage($package);
        $rateOptions = collect();
        $rateTaskKeys = [];

        foreach ($this->fetchRatesConcurrently($tasks, $rateRequest, $shipDates) as $taskKey => $taskRates) {
            $kept = ContentsFilter::keepQualifying(
                PackagingFilter::keepCompatible($taskRates, $packageData->carrierPackaging),
                $packageData->qualifyingContents,
            );

            foreach ($kept as $rate) {
                $rateOptions->push($rate);
                $rateTaskKeys[] = $taskKey;
            }
        }

        $rates = $this->offer($package, $rateOptions, $rateTaskKeys, $shipDates, $rateRequest->fingerprint());
        $this->ratedPackageId = $package->id;
        $this->ratedShippingMethodId = $package->shipment?->shipping_method_id;

        return $rates;
    }

    /**
     * The ship date each task will be quoted for, by task key.
     *
     * Dated by the carrier expected to carry the parcel (ADR-0006, guideline
     * 12). A direct task is its carrier. Shopify is the carrier its
     * connection's *Date Shopify's choice as* names. An Amazon task gets no
     * date: `getRates` sends none, each offer carries Amazon's own window, and
     * the purchase is dated by the carrier the offer names.
     *
     * @param  array<int, RatingTask>  $tasks
     * @return array<string, CarbonImmutable|null>
     */
    private function shipDatesFor(array $tasks, ?int $locationId): array
    {
        $shipDateService = app(ShipDateService::class);
        $shipDates = [];

        foreach ($tasks as $task) {
            $candidate = $task['candidate'];

            $shipDates[$task['key']] = match (true) {
                $candidate->isDirect() => $shipDateService->getShipDate(
                    Carrier::query()->where('name', $candidate->carrier)->first(),
                    $locationId,
                ),
                $candidate->isChannel() && $candidate->dataSourceType === ShopifySource::class => $shipDateService->getShipDate(
                    DataSource::find($candidate->postageDataSourceId)?->shipDateCarrier(),
                    $locationId,
                ),
                default => null,
            };
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
     * @param  array<int, string>  $rateTaskKeys  The key of the task that quoted each rate, by position
     * @param  array<string, CarbonImmutable|null>  $shipDates  The date each task was quoted for, by task key
     * @param  string  $quoteFingerprint  {@see RateRequest::fingerprint()} of the request every rate here answers
     * @return Collection<int, RateResponse>
     */
    private function offer(Package $package, Collection $rates, array $rateTaskKeys, array $shipDates, string $quoteFingerprint): Collection
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

        return $rates->map(function (RateResponse $rate, int $index) use ($package, $quoteIds, $offerStore, $rateTaskKeys, $shipDates, $quoteFingerprint, $accountFingerprints): RateResponse {
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

            $offer = $offerStore->issue($package, new OfferDraft(
                carrier: $rate->carrier,
                postageSource: PostageSource::CarrierAccount,
                carrierAccountId: $rate->carrierAccountId,
                carrierId: $rate->carrierId,
                carrierServiceId: $rate->carrierServiceId,
                serviceCode: $rate->serviceCode,
                serviceName: $rate->serviceName,
                price: $rate->priceUnknown ? null : $rate->price,
                currency: 'USD',
                // The packaging requirement travels with the metadata so the
                // purchase-time check classifies what the server quoted.
                rateMetadata: $rate->packagingRequirement->intoRateMetadata($rate->metadata),
                expiresAt: $shipDates[$rateTaskKeys[$index]]?->endOfDay(),
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
     * Sharing `buildRatingTasks()` with quoting is the point: an offer is
     * eligible here exactly when it would have been advertised there, rather
     * than under a second copy of the rules that can drift from the first.
     *
     * @return Collection<int, BlindPurchaseOffer>
     *
     * @throws NoActiveCarrierServicesException
     */
    public function blindPurchaseOffersFor(Package $package): Collection
    {
        $this->configuredSources = [];
        $this->packagingIneligibleSourceKeys = [];
        $this->ratedPackageId = null;
        $this->ratedShippingMethodId = null;

        $destination = AddressData::fromShipment($package->shipment);
        $rateRequest = RateRequest::fromPackage($package, $destination);
        $registry = app(CarrierRegistry::class);
        $tasks = $this->buildRatingTasks($package, $rateRequest, $destination);
        $shipDates = $this->shipDatesFor($tasks, $rateRequest->locationId);

        foreach ($tasks as $task) {
            $source = $registry->blindPurchaseSourceFor($task['source']);

            if (! $source || ! $source->isConfigured()) {
                continue;
            }

            $this->blindPurchaseOffers = $this->blindPurchaseOffers->merge($source->blindPurchaseOffers(
                $rateRequest
                    ->withShipDate($shipDates[$task['key']])
                    ->withSpecialServiceCodes($task['specialServiceCodes']),
                $task['serviceCodes'],
            ));
        }

        return $this->blindPurchaseOffers;
    }

    /**
     * The sole blind purchase automation may infer from a ShippingMethod.
     *
     * This is based on the configured sources that can sell one of the
     * method's services for this package, not on which rate calls happened to
     * return an answer. A configured direct seller therefore prevents Shopify
     * becoming a fallback during an outage, while a direct account whose
     * integration sells none of the method's services is no seller at all
     * (ADR-0006 decision 3). A shipping rule can make a more specific choice
     * separately.
     *
     * @param  (Closure(BlindPurchaseOffer): bool)|null  $excludes  Whether a shipping rule excludes the offer
     *
     * @throws \LogicException when no completed rate snapshot exists for this package
     */
    public function soleBlindPurchaseOfferForAutomation(int $packageId, ?Closure $excludes = null): ?BlindPurchaseOffer
    {
        if ($this->ratedPackageId !== $packageId) {
            throw new \LogicException('soleBlindPurchaseOfferForAutomation() must follow getShippingRates() for the same package.');
        }

        $registry = app(CarrierRegistry::class);

        $eligibleSources = array_values(array_diff_key(
            $this->configuredSources,
            array_flip($this->packagingIneligibleSourceKeys),
        ));

        if ($this->ratedShippingMethodId === null || count($eligibleSources) !== 1) {
            return null;
        }

        $source = $registry->blindPurchaseSourceFor($eligibleSources[0]);

        if (! $source) {
            return null;
        }

        $offers = $this->getBlindPurchaseOffers($excludes);

        return $offers->count() === 1 ? $offers->first() : null;
    }

    /**
     * Which sources may be asked for this package, and with what.
     *
     * Source-first (ADR-0006 decision 4): the package's postage sources are
     * resolved once, then each is given the method's services it can sell and
     * the special services it can apply. A source that can sell none of them
     * gets no task.
     *
     * Resets the exclusions and offers recorded from any earlier call, so a
     * caller reads the reasons belonging to the tasks it just built.
     *
     * @return array<int, RatingTask>
     *
     * @throws NoActiveCarrierServicesException
     */
    private function buildRatingTasks(
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

        $methodServices = null;

        if ($shippingMethod) {
            $methodServices = $this->getActiveCarrierServices($shippingMethod, $destination);

            if ($methodServices->isEmpty()) {
                throw new NoActiveCarrierServicesException($shippingMethod->name);
            }

            logger()->debug('ShippingRateService: Getting rates', [
                'package_id' => $package->id,
                'shipping_method' => $shippingMethod->name,
                'active_carrier_services_count' => $methodServices->count(),
                'carrier_services' => $methodServices->pluck('service_code', 'name')->toArray(),
            ]);
        } else {
            logger()->debug('ShippingRateService: No shipping method assigned, asking every source', [
                'package_id' => $package->id,
            ]);
        }

        $sources = app(PostageSourceResolver::class)->resolve($package, $shippingMethod);
        $tasks = [];

        foreach ($this->assignServices($sources, $methodServices, $destination) as $assignment) {
            $task = $this->buildTask(
                $assignment['candidate'],
                $assignment['source'],
                $assignment['services'],
                $requiredCodes,
                $defaultCodes,
                $scopeMap,
                $serviceNames,
                $rateRequest,
            );

            if ($task) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    /**
     * The services each resolved source can sell (ADR-0006 decision 3).
     *
     * - A direct account sells its carrier's services that its integration
     *   supports ({@see DeclaresSellableServices}).
     * - Shopify sells what its catalog rows name, until
     *   `carrier-catalog-reset/09`.
     * - Amazon, for its own orders or off-Amazon, is asked when the method
     *   lists its row, until `carrier-catalog-reset/12`.
     *
     * With a method, a source that sells none of its services is left out.
     * With none, every source is asked with no service list, except that a PO
     * Box or military destination asks only a source with a cataloged service
     * known to reach it: querying one blind risks a carrier-side reject (UPS
     * 400s on a military "AE" state).
     *
     * @param  Collection<int, CarrierService>|null  $methodServices  null when there is no shipping method
     * @return array<int, array{candidate: PostageSourceCandidate, source: string, services: Collection<int, CarrierService>}>
     */
    private function assignServices(PostageSourceResolution $sources, ?Collection $methodServices, AddressData $destination): array
    {
        $registry = app(CarrierRegistry::class);
        $restrictedDestination = $destination->isPoBox() || $destination->isMilitary();
        $assignments = [];

        foreach ($sources->candidates as $candidate) {
            $sourceName = $this->registryNameFor($candidate);

            if ($sourceName === null || ! $registry->has($sourceName)) {
                continue;
            }

            $services = $methodServices !== null
                ? $methodServices->filter(fn (CarrierService $service): bool => $service->carrier?->name === $sourceName)
                : $this->getActiveCarrierServicesForCarrierName($sourceName, $destination);

            $adapter = $candidate->isDirect() ? $registry->directAdapterFor($sourceName) : null;

            if ($adapter instanceof DeclaresSellableServices) {
                $services = $services->filter(fn (CarrierService $service): bool => $adapter->sellsService($service->service_code));
            }

            if ($services->isEmpty() && ($methodServices !== null || $restrictedDestination)) {
                if ($methodServices === null) {
                    logger()->debug("ShippingRateService: {$sourceName} has no cataloged service for this destination, skipping");
                }

                continue;
            }

            $assignments[] = [
                'candidate' => $candidate,
                'source' => $sourceName,
                'services' => $services->values(),
            ];
        }

        return $assignments;
    }

    /**
     * The name a resolved source's adapter is registered under.
     *
     * A direct carrier's fixed name is the registry's key (ADR-0006 decision
     * 1, as amended). The channel sources are still registered as the
     * `Shopify` and `Amazon` rows they pose as, until
     * `carrier-catalog-reset/09` and `12` remove them.
     */
    private function registryNameFor(PostageSourceCandidate $candidate): ?string
    {
        if ($candidate->isDirect()) {
            return $candidate->carrier;
        }

        return match ($candidate->dataSourceType) {
            ShopifySource::class => ShopifyAdapter::CARRIER_NAME,
            AmazonSource::class => AmazonBuyShippingAdapter::SOURCE_NAME,
            default => null,
        };
    }

    /**
     * Fetch rates from every task concurrently using a shared Guzzle sender.
     *
     * Each direct task is rated on the account its source resolved, handed
     * over on the request rather than stored on the adapter: the registry
     * shares one adapter instance between tasks.
     *
     * @param  array<int, RatingTask>  $tasks
     * @param  array<string, CarbonImmutable|null>  $shipDates  The date to quote each task for, by task key; null where the source takes none
     * @return array<string, Collection<int, RateResponse>> Rates by the key of the task that quoted them, in the order they arrived
     */
    private function fetchRatesConcurrently(array $tasks, RateRequest $rateRequest, array $shipDates): array
    {
        $ratesByTask = [];
        $preparedRequests = [];
        $taskMeta = [];

        $registry = app(CarrierRegistry::class);

        // Phase 1: Prepare all requests (authenticate connectors, build request bodies)
        foreach ($tasks as $task) {
            $key = $task['key'];
            $sourceName = $task['source'];
            $serviceCodes = $task['serviceCodes'];

            try {
                if (! $registry->has($sourceName)) {
                    logger()->warning("ShippingRateService: Unknown carrier {$sourceName}");

                    continue;
                }

                $adapter = $registry->get($sourceName);

                if (! $adapter->isConfigured()) {
                    logger()->warning("ShippingRateService: {$sourceName} is not configured");

                    continue;
                }

                $this->configuredSources[$key] = $sourceName;

                $taskRateRequest = $rateRequest
                    ->withShipDate($shipDates[$key])
                    ->withSpecialServiceCodes($task['specialServiceCodes'])
                    ->withCarrierAccount($task['carrierAccount']);

                if ($adapter instanceof AsyncRateQuoting) {
                    $prepared = $adapter->prepareRateRequest($taskRateRequest, $serviceCodes);

                    if ($prepared) {
                        $preparedRequests[$key] = $prepared;
                        $taskMeta[$key] = ['task' => $task, 'adapter' => $adapter, 'serviceCodes' => $serviceCodes, 'rateRequest' => $taskRateRequest];

                        continue;
                    }
                }

                // A source with no rate API at all advertises a purchase rather
                // than quoting one. Collected separately: it has no price, so
                // there is nothing to compare it with and nowhere in this
                // collection it could honestly sit.
                if ($adapter instanceof BlindPurchaseSource) {
                    $this->blindPurchaseOffers = $this->blindPurchaseOffers->merge(
                        $adapter->blindPurchaseOffers($taskRateRequest, $serviceCodes)
                    );

                    continue;
                }

                // Nothing to send: mock rates, or an adapter that declined to
                // prepare one. Ask it synchronously instead. Only sources that
                // got this far can reach the parse phase below, which is why the
                // two halves of AsyncRateQuoting travel together.
                if ($adapter instanceof CarrierAdapterInterface) {
                    $rates = $adapter->getRates($taskRateRequest, $serviceCodes);
                    $this->recordPackagingEligibility($key, $rates, $taskRateRequest);
                    $ratesByTask[$key] = $rates;
                }
            } catch (CarrierUnavailableException $e) {
                $this->recordUnavailable($task, $e);
            } catch (InvalidPackageDimensionsException $e) {
                $this->exclude($task, $task['label'].' requires valid package dimensions before rates can be requested.');

                logger()->warning("ShippingRateService: {$sourceName} cannot rate an unmeasured package", [
                    'carrier' => $sourceName,
                    'error' => $e->getMessage(),
                ]);
            } catch (CarrierRateFetchException $e) {
                $loggedException = $e->getPrevious() ?? $e;

                logger()->error("ShippingRateService: {$sourceName} rate fetch failed", [
                    'carrier' => $sourceName,
                    'exception' => $loggedException::class,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Exception $e) {
                logger()->error("ShippingRateService: {$sourceName} prepare error", [
                    'carrier' => $sourceName,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fall back to synchronous sends when only one source needs an API call (no
        // concurrency overhead needed) or when any request has a fake/mock response set
        // (Saloon faking bypasses the sender, so the shared GuzzleSender can't handle it).
        $hasFakeResponses = collect($preparedRequests)->contains(
            fn (PreparedRateRequest $p): bool => $p->pendingRequest->hasFakeResponse()
        );

        if (count($preparedRequests) <= 1 || $hasFakeResponses) {
            foreach (array_keys($preparedRequests) as $key) {
                $meta = $taskMeta[$key];
                $sourceName = $meta['task']['source'];

                try {
                    $rates = $meta['adapter']->getRates($meta['rateRequest'], $meta['serviceCodes']);
                    $this->recordPackagingEligibility($key, $rates, $meta['rateRequest']);
                    $ratesByTask[$key] = $rates;
                } catch (CarrierUnavailableException $e) {
                    $this->recordUnavailable($meta['task'], $e);
                } catch (CarrierRateFetchException $e) {
                    $loggedException = $e->getPrevious() ?? $e;

                    logger()->error("ShippingRateService: {$sourceName} rate fetch failed", [
                        'carrier' => $sourceName,
                        'exception' => $loggedException::class,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $ratesByTask;
        }

        // Phase 2: Send all requests concurrently through a shared Guzzle sender.
        // Resolved from the container, like every other collaborator here, so a
        // test can drive the concurrent path — the fake-response check above
        // routes anything Saloon has faked down the synchronous branch instead.
        $sharedSender = app(GuzzleSender::class);
        $promises = [];

        foreach ($preparedRequests as $key => $prepared) {
            logger()->debug("ShippingRateService: Sending async rate request to {$taskMeta[$key]['task']['source']}");
            $promises[$key] = $sharedSender->sendAsync($prepared->pendingRequest);
        }

        $results = PromiseUtils::settle($promises)->wait();

        // Phase 3: Parse responses
        foreach ($results as $key => $result) {
            $meta = $taskMeta[$key];
            $sourceName = $meta['task']['source'];

            if ($result['state'] === 'fulfilled') {
                try {
                    $rates = $meta['adapter']->parseRateResponse(
                        $result['value'],
                        $meta['rateRequest'],
                        $meta['serviceCodes'],
                    );

                    logger()->debug("ShippingRateService: Got {$sourceName} rates", [
                        'rates_count' => $rates->count(),
                    ]);

                    $this->recordPackagingEligibility($key, $rates, $meta['rateRequest']);
                    $ratesByTask[$key] = $rates;
                } catch (CarrierUnavailableException $e) {
                    $this->recordUnavailable($meta['task'], $e);
                } catch (\Exception $e) {
                    logger()->error("ShippingRateService: {$sourceName} parse error", [
                        'carrier' => $sourceName,
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                logger()->error("ShippingRateService: {$sourceName} request failed", [
                    'error' => $result['reason']?->getMessage() ?? 'Unknown error',
                ]);
            }
        }

        return $ratesByTask;
    }

    /**
     * A source that answered, or could not be asked, because of how it is set
     * up — an Amazon connection not signed up for Amazon Shipping, say. That
     * is not a failed request: it has no offers, and the packer is told why
     * beside the rates rather than left to wonder where they went.
     *
     * @param  array{key: string, source: string, label: string}  $task
     */
    private function recordUnavailable(array $task, CarrierUnavailableException $e): void
    {
        $this->exclude($task, $e->getMessage());

        logger()->warning("ShippingRateService: {$task['source']} is unavailable", [
            'carrier' => $task['source'],
            'reason' => $e->getMessage(),
        ]);
    }

    /**
     * Record why a source was not asked, or answered nothing, keyed by its
     * source instance.
     *
     * @param  array{key: string, source: string, label: string}  $task
     */
    private function exclude(array $task, string $reason): void
    {
        $this->exclusions[$task['key']] = [
            'carrier' => $task['label'],
            'source' => $task['source'],
            'reason' => $reason,
        ];
    }

    /**
     * A successful non-empty response proves a seller is ineligible when every
     * returned rate conflicts with the Package's packaging. Empty responses and
     * failures remain conservatively eligible so Shopify cannot become an
     * outage fallback.
     *
     * A rate dropped for contents the Package lacks does not count against its
     * seller here. Were a direct USPS account that quoted only Media Mail read
     * as ineligible, Shopify could become the sole choice and be bought blind
     * for the same Media Mail the drop just refused.
     *
     * @param  Collection<int, RateResponse>  $rates
     */
    private function recordPackagingEligibility(string $taskKey, Collection $rates, RateRequest $rateRequest): void
    {
        if ($rates->isEmpty()) {
            return;
        }

        $carrierPackaging = $rateRequest->packages[0]->carrierPackaging ?? null;

        if (PackagingFilter::keepCompatible($rates, $carrierPackaging)->isEmpty()) {
            $this->packagingIneligibleSourceKeys[] = $taskKey;
        }
    }

    /**
     * Build the rate task for one source, applying capability and carrier-service
     * scope checks. Hard-required codes (shipping-method required mode + product
     * compliance) drop carrier services that aren't scoped for them and exclude
     * the source entirely when nothing survives (or the source prohibits /
     * hasn't implemented the code). Default-mode codes never drop a carrier
     * service — the code is stripped from the source's request instead.
     *
     * Catalog scoping applies to direct sources only (ADR-0006 decision 10):
     * it records what our own integrations can request. Shopify cannot
     * guarantee a special service at all, and Amazon's offers are judged one
     * by one on the value-added services each returns (ADR-0002 decision 8).
     * Capability and declared-value checks apply to every source.
     *
     * Returns null (recording the reason in $this->exclusions) when the source
     * is excluded.
     *
     * @param  Collection<int, CarrierService>  $services  Empty when no shipping method is assigned
     * @param  array<int, string>  $requiredCodes
     * @param  array<int, string>  $defaultCodes
     * @param  array<string, array<int, array<int, array<int, string>|null>>>  $scopeMap
     * @param  Collection<string, string>  $serviceNames
     * @return RatingTask|null
     */
    private function buildTask(
        PostageSourceCandidate $candidate,
        string $sourceName,
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
        $scoped = $candidate->isDirect();
        $label = Carrier::labelForName($sourceName);
        $task = ['key' => $candidate->key(), 'source' => $sourceName, 'label' => $label];

        $adapter = $registry->has($sourceName) ? $registry->get($sourceName) : null;

        foreach ($requiredCodes as $code) {
            $capability = $adapter?->offerCapability($code);

            if ($capability !== null && $capability !== ServiceCapability::Supported) {
                // Prohibited, Unguaranteed and NotImplemented all exclude: a
                // hard-required service the offer can't actually apply must not
                // be skipped.
                $this->exclude($task, $this->requiredServiceExclusion(
                    $capability,
                    $label,
                    $serviceNames->get($code, $code),
                ));

                return null;
            }

            if ($capReason = $this->declaredValueCapViolation($code, $adapter, $rateRequest, $label)) {
                $this->exclude($task, $capReason);

                return null;
            }

            $carrierScopes = $scoped ? $this->scopesForCarrier($scopeMap, $code, $services) : null;

            if ($carrierScopes !== null) {
                $services = $services->filter(
                    fn (CarrierService $service): bool => $this->scopeAllows($carrierScopes, $service->id, $destinationCountry)
                );

                if ($services->isEmpty()) {
                    $this->exclude($task, $label.' has no services that support '.$serviceNames->get($code, $code).' for this destination.');

                    return null;
                }
            }

            $specialServiceCodes[] = $code;
        }

        foreach ($defaultCodes as $code) {
            if ($adapter) {
                $capability = $adapter->offerCapability($code);

                if ($capability === ServiceCapability::Prohibited) {
                    $this->exclude($task, $label.' does not support '.$serviceNames->get($code, $code).'.');

                    return null;
                }

                // A preference this offer cannot express is dropped, not fatal —
                // the same treatment as a code we have not wired up.
                if ($capability === ServiceCapability::NotImplemented || $capability === ServiceCapability::Unguaranteed) {
                    continue;
                }
            }

            if ($capReason = $this->declaredValueCapViolation($code, $adapter, $rateRequest, $label)) {
                // Stripping a default declared value silently would under-insure
                // the package — exclude the source visibly instead.
                $this->exclude($task, $capReason);

                return null;
            }

            $carrierScopes = $scoped ? $this->scopesForCarrier($scopeMap, $code, $services) : null;

            if ($carrierScopes !== null && $services->doesntContain(
                fn (CarrierService $service): bool => $this->scopeAllows($carrierScopes, $service->id, $destinationCountry)
            )) {
                logger()->debug("ShippingRateService: {$sourceName} has no services scoped for default service {$code}, requesting rates without it");

                continue;
            }

            $specialServiceCodes[] = $code;
        }

        return [
            ...$task,
            'candidate' => $candidate,
            'carrierAccount' => $candidate->carrierAccount,
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
    private function requiredServiceExclusion(ServiceCapability $capability, string $sourceLabel, string $serviceName): string
    {
        return $capability === ServiceCapability::Unguaranteed
            ? $sourceLabel.' cannot guarantee '.$serviceName.' — it picks the carrier and service after the label is bought.'
            : $sourceLabel.' does not support '.$serviceName.'.';
    }

    /**
     * Exclusion reason when the package's declared value exceeds the source's
     * cap, or null when the code isn't declared_value / no cap applies.
     */
    private function declaredValueCapViolation(
        string $code,
        ?PostageOfferSource $adapter,
        RateRequest $rateRequest,
        string $sourceLabel,
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
            $sourceLabel,
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
     * The sources rate shopping would ask for this shipment, before it has a
     * Package: those that resolve for it and can sell one of its method's
     * services at this destination, with a configured adapter behind them.
     *
     * The same resolution and service assignment {@see buildRatingTasks()}
     * uses, and the same `isConfigured()` the task runner checks, so a caller
     * deciding ahead of a purchase what the purchase could buy reads the set
     * the purchase will. `BatchLabelService` asks this before a batch starts,
     * for the report printer skip: an unconfigured carrier, one whose services
     * cannot reach a PO Box or military address, or a channel the shipment did
     * not come from would otherwise count as an option the batch does not
     * actually have.
     *
     * Special-service exclusions are not applied here; they are per-package
     * and resolved once the rate request exists.
     *
     * @return Collection<int, PostageOfferSource>
     */
    public function sellersForShipment(Shipment $shipment, ?int $locationId, AddressData $destination): Collection
    {
        $registry = app(CarrierRegistry::class);
        $shippingMethod = $shipment->shippingMethod;

        // The Package the batch is about to prepare, so the shipment resolves
        // exactly as its rating will.
        $package = new Package;
        $package->location_id = $locationId;
        $package->setRelation('shipment', $shipment);

        $sources = app(PostageSourceResolver::class)->resolve($package, $shippingMethod);
        $methodServices = $shippingMethod ? $this->getActiveCarrierServices($shippingMethod, $destination) : null;

        if ($methodServices?->isEmpty()) {
            return collect();
        }

        return collect($this->assignServices($sources, $methodServices, $destination))
            ->pluck('source')
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
