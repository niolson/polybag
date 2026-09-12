<?php

namespace App\Models;

use App\DataTransferObjects\Shipping\ServiceInference;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use App\Enums\SpecialServiceSource;
use App\Enums\TrackingStatus;
use App\Events\PackageCancelled;
use App\Events\PackageShipped;
use App\Services\CarrierNormalizer;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\SpecialServiceResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Searchable;

class Package extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'shipment_id',
        'location_id',
        'carrier_account_id',
        'postage_data_source_id',
        'postage_source',
        'box_size_id',
        'tracking_number',
        'carrier',
        'normalized_carrier_id',
        'service',
        'requested_service',
        'service_evidence',
        'service_inference_method',
        'service_ruleset_version',
        'metadata',
        'carrier_request_payload',
        'label_data',
        'customs_form_data',
        'label_orientation',
        'label_format',
        'label_dpi',
        'label_printed_at',
        'weight',
        'height',
        'width',
        'length',
        'cost',
        'weight_mismatch',
        'status',
        'shipped_at',
        'ship_date',
        'shipped_by_user_id',
        'exported',
        'manifest_id',
        'tracking_status',
        'tracking_updated_at',
        'delivered_at',
        'tracking_details',
        'tracking_checked_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'carrier_request_payload' => 'array',
        'weight' => 'decimal:2',
        'height' => 'decimal:2',
        'width' => 'decimal:2',
        'length' => 'decimal:2',
        'cost' => 'decimal:2',
        'weight_mismatch' => 'boolean',
        'label_printed_at' => 'datetime',
        'status' => PackageStatus::class,
        'postage_source' => PostageSource::class,
        'service_evidence' => ServiceEvidence::class,
        'shipped_at' => 'datetime',
        'ship_date' => 'date',
        'exported' => 'boolean',
        'tracking_status' => TrackingStatus::class,
        'tracking_updated_at' => 'datetime',
        'delivered_at' => 'datetime',
        'tracking_details' => 'array',
        'tracking_checked_at' => 'datetime',
    ];

    /**
     * @return array<string, mixed>
     */
    #[SearchUsingPrefix(['tracking_number'])]
    public function toSearchableArray(): array
    {
        return [
            'tracking_number' => $this->tracking_number,
        ];
    }

    /**
     * @return HasMany<PackageItem, $this>
     */
    public function packageItems(): HasMany
    {
        return $this->hasMany(PackageItem::class);
    }

    /**
     * @return HasMany<PackageExport, $this>
     */
    public function packageExports(): HasMany
    {
        return $this->hasMany(PackageExport::class);
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<CarrierAccount, $this>
     */
    public function carrierAccount(): BelongsTo
    {
        return $this->belongsTo(CarrierAccount::class);
    }

    /**
     * The carrier identity resolved when this package shipped.
     *
     * @return BelongsTo<Carrier, $this>
     */
    public function normalizedCarrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'normalized_carrier_id');
    }

    /**
     * The data source the postage was bought through, when it was not bought
     * on a carrier account of ours. Not the shipment's import source — see
     * ADR-0002.
     *
     * @return BelongsTo<DataSource, $this>
     */
    public function postageDataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'postage_data_source_id');
    }

    /**
     * @return BelongsTo<BoxSize, $this>
     */
    public function boxSize(): BelongsTo
    {
        return $this->belongsTo(BoxSize::class);
    }

    /**
     * @return BelongsTo<Manifest, $this>
     */
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    /**
     * @return HasMany<RateQuote, $this>
     */
    public function rateQuotes(): HasMany
    {
        return $this->hasMany(RateQuote::class);
    }

    /**
     * @return HasMany<PackageSpecialService, $this>
     */
    public function specialServices(): HasMany
    {
        return $this->hasMany(PackageSpecialService::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by_user_id');
    }

    /**
     * Packages whose label can still be sent to a printer.
     *
     * Voiding a label after the fact clears both the label data and the printed
     * timestamp, so without this a voided package reads as "shipped but never
     * printed" forever.
     *
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    public function scopePrintable(Builder $query): Builder
    {
        return $query
            ->where('status', PackageStatus::Shipped)
            ->whereNotNull('label_data');
    }

    /**
     * Packages whose postage we bought ourselves.
     *
     * The eligibility rule behind every manifest query: a SCAN form asserts
     * that we tendered these parcels on our own account, which is false for
     * channel-bought postage no matter which carrier is carrying it. Written as
     * a scope so the four queries that need it cannot drift apart.
     *
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    public function scopeBoughtOnCarrierAccount(Builder $query): Builder
    {
        return $query->where('postage_source', PostageSource::CarrierAccount);
    }

    /**
     * Whether this package's postage was bought through Shopify Shipping.
     *
     * Shopify labels are billed to the merchant's Shopify account and can only
     * be voided or refunded in the Shopify admin, so several parts of the UI
     * have to treat them differently from a label bought on our own carrier
     * account.
     *
     * Checked against the source that sold the label, not merely the postage
     * kind: Amazon Buy Shipping buys through a data source too, and its labels
     * are voidable here, priced, and carried by whoever the packer chose.
     */
    public function isShopifyShipped(): bool
    {
        return $this->boughtThroughDataSourceOfType(ShopifySource::class);
    }

    /**
     * Whether this package's postage was bought through Amazon Buy Shipping.
     */
    public function isAmazonShipped(): bool
    {
        return $this->boughtThroughDataSourceOfType(AmazonSource::class);
    }

    /**
     * @param  class-string  $sourceType
     */
    private function boughtThroughDataSourceOfType(string $sourceType): bool
    {
        if ($this->postage_source !== PostageSource::PostageDataSource) {
            return false;
        }

        $this->loadMissing('postageDataSource');

        return $this->postageDataSource?->source_type === $sourceType;
    }

    /**
     * The carrier of record as an outside system should be told it.
     *
     * The canonical name of the identity resolved when the package shipped, so
     * that a source's spelling — "US Postal Service", or whatever Shopify put
     * in `trackingCompany` — reaches a sales channel as the carrier it maps to.
     * Falls back to the raw value: an unmapped carrier is still the carrier
     * carrying the parcel, and reporting it verbatim beats reporting nothing.
     */
    public function carrierOfRecordName(): ?string
    {
        if ($this->normalized_carrier_id === null) {
            return $this->carrier;
        }

        $this->loadMissing('normalizedCarrier');

        return $this->normalizedCarrier->name;
    }

    /**
     * The service as a fact fit to report outward, or null.
     *
     * Only a `confirmed` service is one the postage source reported; an inferred
     * one is ours, and a channel that receives it turns it into a buyer-facing
     * promise we never made. Withholding it costs nothing — Amazon's
     * `shipmentConfirmation` treats `shippingMethod` as optional. ADR-0003
     * decision 7.
     */
    public function confirmedService(): ?string
    {
        return $this->service_evidence->isPublishable() ? $this->service : null;
    }

    /**
     * Record a service we derived ourselves, or leave the package alone.
     *
     * A narrow path of its own rather than a reuse of `markShipped()`, which is a
     * one-shot transition out of `Unshipped` under optimistic locking and cannot
     * upgrade a package after the fact.
     *
     * The rules it enforces:
     *
     * - A `confirmed` service is never overwritten. The postage source reported
     *   it; nothing we derive outranks that.
     * - An inference never downgrades. Re-running produces a value or it does
     *   not, and a run that produces nothing leaves the previous one standing.
     * - An inference from an older ruleset is replaced together with its version
     *   stamp, so a value and the rules that produced it never disagree.
     * - An inference under the same ruleset is a no-op, because re-deriving the
     *   same value from the same tables says nothing new.
     *
     * Enforced as conditions on the update rather than as checks before it. This
     * runs over packages in bulk while shipping continues, so a service the
     * postage source confirms between loading a package and writing to it must
     * lose the race rather than be silently overwritten by a guess.
     *
     * Returns whether the package was changed.
     */
    public function recordInferredService(ServiceInference $inference): bool
    {
        if (! $inference->isResolved() || ! $this->exists) {
            return false;
        }

        $updated = DB::table('packages')
            ->where('id', $this->id)
            ->where(function (QueryBuilder $query) use ($inference): void {
                $query
                    ->where('service_evidence', ServiceEvidence::Unknown->value)
                    ->orWhere(function (QueryBuilder $query) use ($inference): void {
                        $query
                            ->where('service_evidence', ServiceEvidence::Inferred->value)
                            ->where(function (QueryBuilder $query) use ($inference): void {
                                $query
                                    ->whereNull('service_ruleset_version')
                                    ->orWhere('service_ruleset_version', '<', $inference->rulesetVersion);
                            });
                    });
            })
            ->update([
                'service' => $inference->service,
                'service_evidence' => ServiceEvidence::Inferred->value,
                'service_inference_method' => $inference->method,
                'service_ruleset_version' => $inference->rulesetVersion,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return false;
        }

        $this->forceFill([
            'service' => $inference->service,
            'service_evidence' => ServiceEvidence::Inferred,
            'service_inference_method' => $inference->method,
            'service_ruleset_version' => $inference->rulesetVersion,
        ])->syncOriginal();

        return true;
    }

    /**
     * Compute whether there's a weight mismatch (>10% discrepancy)
     * between the actual package weight and the expected weight
     * based on the packed products.
     */
    public function computeWeightMismatch(): bool
    {
        $this->loadMissing('packageItems.product');

        $expectedWeight = (float) $this->packageItems->sum(
            fn ($item): int|float => ($item->product?->weight ?? 0) * $item->quantity
        );

        $actualWeight = (float) $this->weight;

        if ($actualWeight <= 0 || $expectedWeight <= 0) {
            return false;
        }

        return abs($actualWeight - $expectedWeight) / max($actualWeight, 0.01) > 0.10;
    }

    /**
     * Mark this package as shipped with the given response data.
     *
     * Every new transition to Shipped has to say where its postage was bought,
     * so the caller passes the discriminator rather than letting it be inferred
     * from whichever pointer happens to be set. See ADR-0002.
     *
     * @throws \InvalidArgumentException If the postage source and the response's pointers disagree, or the service evidence contradicts the service value
     * @throws \RuntimeException If the package state changed (optimistic locking)
     */
    public function markShipped(ShipResponse $response, PostageSource $postageSource, ?int $shippedByUserId = null): void
    {
        // Before the transaction, so a rejected provenance writes nothing at all.
        $this->assertProvenanceIsConsistent($postageSource, $response);
        $this->assertServiceEvidenceIsConsistent($response);

        DB::transaction(function () use ($response, $postageSource, $shippedByUserId): void {
            $normalizedCarrierId = app(CarrierNormalizer::class)->resolve($response->carrier)?->id;

            // Carriers that record facts of their own (Shopify reports which
            // carrier it picked, and its own label ID) merge into whatever the
            // package already carries rather than replacing it.
            //
            // Merged onto the stored row, not this instance: shipping can write
            // metadata mid-flight — Shopify records an in-flight purchase so it
            // can be resumed — and merging a copy loaded before that would
            // resurrect keys the carrier had deliberately cleared.
            $metadata = [];

            if ($response->metadata !== []) {
                $stored = json_decode(
                    (string) DB::table('packages')->where('id', $this->id)->value('metadata'),
                    true,
                ) ?: [];

                $metadata = ['metadata' => json_encode(array_merge($stored, $response->metadata))];
            }

            // Optimistic locking - ensure package hasn't been shipped already
            $updated = DB::table('packages')
                ->where('id', $this->id)
                ->where('status', PackageStatus::Unshipped->value)
                ->update($metadata + [
                    'tracking_number' => $response->trackingNumber,
                    'carrier_account_id' => $response->carrierAccountId,
                    'postage_data_source_id' => $response->postageDataSourceId,
                    'postage_source' => $postageSource->value,
                    'cost' => $response->cost,
                    'carrier' => $response->carrier,
                    'normalized_carrier_id' => $normalizedCarrierId,
                    'service' => $response->service,
                    'requested_service' => $response->requestedService,
                    'service_evidence' => $response->serviceEvidence->value,
                    'service_inference_method' => $response->serviceInferenceMethod,
                    'service_ruleset_version' => $response->serviceRulesetVersion,
                    'label_data' => $response->labelData,
                    // The separately-returned customs document, where the source
                    // returned one. Stored beside the label rather than fetched on
                    // demand from a carrier-hosted URL, so printing it does not
                    // depend on that URL staying fetchable.
                    'customs_form_data' => $response->customsFormData,
                    'label_orientation' => $response->labelOrientation ?? 'portrait',
                    'label_format' => $response->labelFormat ?? 'pdf',
                    'label_dpi' => $response->labelDpi,
                    'label_printed_at' => null,
                    'status' => PackageStatus::Shipped->value,
                    'shipped_at' => now(),
                    'ship_date' => $response->shipDate?->format('Y-m-d'),
                    'shipped_by_user_id' => $shippedByUserId,
                    'tracking_status' => TrackingStatus::PreTransit->value,
                    'tracking_updated_at' => null,
                    'delivered_at' => null,
                    'tracking_details' => null,
                    'tracking_checked_at' => null,
                    'exported' => false,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw new \RuntimeException('Package has already been shipped or was modified by another process.');
            }

            // Refresh the model to get the updated state
            $this->refresh();
        });

        $this->recordAppliedSpecialServices($response->appliedServices);

        $this->load('shipment.shipmentItems');
        $this->shipment->updateShippedStatus();

        PackageShipped::dispatch($this, $this->shipment);
    }

    /**
     * Reject a ship that would record provenance disagreeing with its pointers.
     *
     * Enforced here rather than in a model observer or a saving hook: markShipped()
     * writes through the query builder for optimistic locking, so model events
     * never fire for it.
     *
     * A `carrier_account` purchase may legitimately name no account — the fake
     * adapters ship without one, and a real adapter records `$account?->id` — so
     * only the foreign pointer is forbidden there. Sales-channel postage is
     * different: a source we cannot name is a source we cannot void, track or
     * manifest against, so its pointer is required.
     *
     * @throws \InvalidArgumentException
     */
    private function assertProvenanceIsConsistent(PostageSource $postageSource, ShipResponse $response): void
    {
        if ($postageSource === PostageSource::CarrierAccount && $response->postageDataSourceId !== null) {
            throw new \InvalidArgumentException(
                'A carrier_account purchase cannot also point at a postage data source.'
            );
        }

        if ($postageSource === PostageSource::PostageDataSource) {
            if ($response->postageDataSourceId === null) {
                throw new \InvalidArgumentException(
                    'A postage_data_source purchase must name the data source the postage was bought through.'
                );
            }

            if ($response->carrierAccountId !== null) {
                throw new \InvalidArgumentException(
                    'A postage_data_source purchase cannot also point at a carrier account.'
                );
            }
        }
    }

    /**
     * Reject a ship whose evidence contradicts the service value it carries.
     *
     * Enforced beside the provenance check and for the same reason: markShipped()
     * writes through the query builder, so model events never fire for it.
     *
     * The invariants are the ones ADR-0003 decision 7 depends on. `confirmed`
     * with no service is a source that reported nothing being recorded as having
     * reported something. `unknown` with a service is the guess the channel
     * export exists to withhold. And an inference nobody can reproduce — no
     * method, no ruleset version — cannot be reviewed when the rules change.
     *
     * @throws \InvalidArgumentException
     */
    private function assertServiceEvidenceIsConsistent(ShipResponse $response): void
    {
        $hasService = filled($response->service);
        $hasInferenceDetail = filled($response->serviceInferenceMethod) || filled($response->serviceRulesetVersion);

        if ($response->serviceEvidence === ServiceEvidence::Confirmed && ! $hasService) {
            throw new \InvalidArgumentException(
                'A confirmed service must name the service the postage source reported.'
            );
        }

        if ($response->serviceEvidence === ServiceEvidence::Unknown && $hasService) {
            throw new \InvalidArgumentException(
                'A service nobody confirmed or inferred cannot be recorded as the service value.'
            );
        }

        if ($response->serviceEvidence === ServiceEvidence::Inferred) {
            if (! $hasService) {
                throw new \InvalidArgumentException(
                    'An inferred service must name the service that was inferred.'
                );
            }

            if (blank($response->serviceInferenceMethod) || blank($response->serviceRulesetVersion)) {
                throw new \InvalidArgumentException(
                    'An inferred service must record the inference method and the ruleset version that produced it.'
                );
            }
        } elseif ($hasInferenceDetail) {
            throw new \InvalidArgumentException(
                'Only an inferred service may record an inference method or ruleset version.'
            );
        }
    }

    /**
     * Record which special services were actually applied when this package was shipped.
     *
     * @param  array<string>  $appliedServiceCodes  Service codes confirmed sent to the carrier
     */
    private function recordAppliedSpecialServices(array $appliedServiceCodes): void
    {
        if (empty($appliedServiceCodes)) {
            return;
        }

        $shippingMethod = $this->shipment?->shippingMethod;
        $services = SpecialService::whereIn('code', $appliedServiceCodes)->get()->keyBy('code');
        $productRequiredCodes = app(SpecialServiceResolver::class)->resolveProductRequiredCodes($this);
        $now = now();

        foreach ($appliedServiceCodes as $code) {
            $service = $services->get($code);

            if (! $service) {
                continue;
            }

            if ($productRequiredCodes->has($code)) {
                $source = SpecialServiceSource::Product;
                $sourceReference = (string) $productRequiredCodes->get($code);
            } else {
                $pivotMode = $shippingMethod?->specialServices()
                    ->where('code', $code)
                    ->value('shipping_method_special_service.mode');

                $source = $pivotMode ? SpecialServiceSource::ShippingMethod : SpecialServiceSource::System;
                $sourceReference = $pivotMode ? (string) $shippingMethod->id : null;
            }

            $config = null;

            if ($code === 'declared_value') {
                $amount = app(SpecialServiceResolver::class)->declaredValueForPackage($this);
                $config = $amount !== null ? ['amount' => $amount, 'currency' => 'USD'] : null;
            }

            $this->specialServices()->updateOrCreate(
                ['special_service_id' => $service->id],
                [
                    'source' => $source,
                    'source_reference' => $sourceReference,
                    'config' => $config,
                    'applied_at' => $now,
                ],
            );
        }
    }

    /**
     * Clear shipping data from this package (void label).
     *
     * @throws \RuntimeException If the package state changed (optimistic locking)
     */
    public function clearShipping(): void
    {
        DB::transaction(function (): void {
            // Optimistic locking - ensure package is still shipped
            $updated = DB::table('packages')
                ->where('id', $this->id)
                ->where('status', PackageStatus::Shipped->value)
                ->update([
                    'tracking_number' => null,
                    'carrier_account_id' => null,
                    'postage_data_source_id' => null,
                    'postage_source' => null,
                    'carrier' => null,
                    'normalized_carrier_id' => null,
                    'service' => null,
                    'requested_service' => null,
                    'service_evidence' => ServiceEvidence::Unknown->value,
                    'service_inference_method' => null,
                    'service_ruleset_version' => null,
                    'cost' => null,
                    'label_data' => null,
                    'customs_form_data' => null,
                    'label_orientation' => null,
                    'label_format' => 'pdf',
                    'label_dpi' => null,
                    'label_printed_at' => null,
                    'status' => PackageStatus::Unshipped->value,
                    'shipped_at' => null,
                    'shipped_by_user_id' => null,
                    'tracking_status' => null,
                    'tracking_updated_at' => null,
                    'delivered_at' => null,
                    'tracking_details' => null,
                    'tracking_checked_at' => null,
                    'exported' => false,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw new \RuntimeException('Package shipping state has changed. It may have already been voided.');
            }

            PackageExport::query()->where('package_id', $this->id)->delete();

            // Refresh the model to get the updated state
            $this->refresh();
        });

        $this->load('shipment.shipmentItems');
        $this->shipment->updateShippedStatus();

        PackageCancelled::dispatch($this, $this->shipment);
    }
}
