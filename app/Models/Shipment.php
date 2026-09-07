<?php

namespace App\Models;

use App\Enums\Deliverability;
use App\Enums\PackageStatus;
use App\Enums\PickingStatus;
use App\Enums\ShipmentStatus;
use App\Models\Concerns\HasDefaultClient;
use App\Services\AddressReferenceService;
use App\Services\AddressValidationService;
use App\Services\PhoneParserService;
use App\Services\PickBatchService;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Searchable;

class Shipment extends Model
{
    use HasDefaultClient, HasFactory, Searchable;

    protected $fillable = [
        'client_id',
        'location_id',
        'shipment_reference',
        'source_record_id',
        'source_checksum',
        'first_name',
        'last_name',
        'company',
        'address1',
        'address2',
        'city',
        'state_or_province',
        'postal_code',
        'country',
        'phone',
        'phone_e164',
        'phone_extension',
        'email',
        'value',
        'residential',
        'checked',
        'deliverability',
        'validation_message',
        'validated_company',
        'validated_address1',
        'validated_address2',
        'validated_city',
        'validated_state_or_province',
        'validated_postal_code',
        'validated_country',
        'validated_residential',
        'shipping_method_reference',
        'shipping_method_id',
        'channel_reference',
        'channel_id',
        'data_source_id',
        'data_source_location_id',
        'status',
        'picking_status',
        'deliver_by',
        'metadata',
    ];

    protected $casts = [
        'checked' => 'boolean',
        'residential' => 'boolean',
        'validated_residential' => 'boolean',
        'value' => 'decimal:2',
        'deliverability' => Deliverability::class,
        'status' => ShipmentStatus::class,
        'picking_status' => PickingStatus::class,
        'deliver_by' => 'date',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (Shipment $shipment): void {
            $addressReference = app(AddressReferenceService::class);

            $shipment->country = $addressReference->normalizeCountry($shipment->country) ?? ($shipment->country ? strtoupper(trim($shipment->country)) : null);
            $shipment->state_or_province = $addressReference->normalizeSubdivision($shipment->country, $shipment->state_or_province);

            $shipment->validated_country = $addressReference->normalizeCountry($shipment->validated_country) ?? ($shipment->validated_country ? strtoupper(trim($shipment->validated_country)) : null);
            $shipment->validated_state_or_province = $addressReference->normalizeSubdivision($shipment->validated_country, $shipment->validated_state_or_province);

            $shipment->phone = filled($shipment->phone) ? trim($shipment->phone) : null;
            $shipment->phone_extension = filled($shipment->phone_extension) ? trim($shipment->phone_extension) : null;

            if ($shipment->phone) {
                $parsedPhone = PhoneParserService::parse($shipment->phone, $shipment->country);
                $shipment->phone_e164 = $parsedPhone->e164;

                if (blank($shipment->phone_extension) && $parsedPhone->extension !== null) {
                    $shipment->phone_extension = $parsedPhone->extension;
                }
            } else {
                $shipment->phone_e164 = null;
                $shipment->phone_extension = null;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[SearchUsingPrefix(['shipment_reference'])]
    public function toSearchableArray(): array
    {
        return [
            'shipment_reference' => $this->shipment_reference,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'address1' => $this->address1,
            'city' => $this->city,
            'email' => $this->email,
        ];
    }

    /**
     * Recalculate and persist the status based on package status.
     */
    public function updateShippedStatus(): void
    {
        // Don't change status of voided shipments
        if ($this->status === ShipmentStatus::Void) {
            return;
        }

        $hasShippedPackage = $this->packages()->where('status', PackageStatus::Shipped)->exists();

        if (! $hasShippedPackage) {
            $this->update(['status' => ShipmentStatus::Open]);

            return;
        }

        if (! app(SettingsService::class)->get('packing_validation_enabled', true)) {
            $this->markShipped();

            return;
        }

        $shippedPackageIds = $this->packages()->where('status', PackageStatus::Shipped)->pluck('id');

        $packedQuantities = PackageItem::whereIn('package_id', $shippedPackageIds)
            ->selectRaw('shipment_item_id, SUM(quantity) as total_packed')
            ->groupBy('shipment_item_id')
            ->pluck('total_packed', 'shipment_item_id');

        $allItemsShipped = $this->shipmentItems->every(function (ShipmentItem $item) use ($packedQuantities): bool {
            return ($packedQuantities[$item->id] ?? 0) >= $item->quantity;
        });

        if ($allItemsShipped) {
            $this->markShipped();
        } else {
            $this->update(['status' => ShipmentStatus::Open]);
        }
    }

    private function markShipped(): void
    {
        $this->update(['status' => ShipmentStatus::Shipped]);

        app(PickBatchService::class)->removeFromActiveBatches($this);
    }

    /**
     * Whether picking is required and this shipment has not been picked yet,
     * blocking packing and batch shipping.
     */
    public function isBlockedByPicking(): bool
    {
        if ($this->picking_status === PickingStatus::Picked) {
            return false;
        }

        $settings = app(SettingsService::class);

        return (bool) $settings->get('picking_enabled', false)
            && (bool) $settings->get('require_picking_before_shipping', false);
    }

    /**
     * Whether Amazon fulfills this order itself (FBA) rather than the seller.
     *
     * An FBA order is picked, packed and shipped from an Amazon warehouse, so
     * packing one here produces a duplicate physical shipment and a
     * `confirmShipment` call Amazon rejects. These are excluded from import by
     * default; this covers the ones that arrive when a source opts back in.
     */
    public function isAmazonFulfilled(): bool
    {
        return ($this->metadata['amazon_fulfilled_by'] ?? null) === AmazonSource::FULFILLED_BY_AMAZON;
    }

    /**
     * Validate the shipment's address using USPS API.
     */
    public function validateAddress(): void
    {
        app(AddressValidationService::class)->validate($this);
    }

    /**
     * Calculate the deliver-by deadline for this shipment.
     *
     * Priority: explicit deliver_by date > calculated from commitment_days > null.
     */
    public function getDeliverByDate(): ?Carbon
    {
        // 1. Explicit deliver_by date on the shipment
        if ($this->deliver_by) {
            return $this->deliver_by;
        }

        // 2. Calculated from ShippingMethod.commitment_days
        $commitmentDays = $this->shippingMethod?->commitment_days;
        if ($commitmentDays) {
            $date = Carbon::today();
            $added = 0;
            while ($added < $commitmentDays) {
                $date->addDay();
                if (! $date->isWeekend()) {
                    $added++;
                }
            }

            return $date;
        }

        // 3. No deadline
        return null;
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<ShipmentItem, $this>
     */
    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    /**
     * @return HasMany<Package, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    /**
     * @return BelongsTo<ShippingMethod, $this>
     */
    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<DataSourceLocation, $this> */
    public function dataSourceLocation(): BelongsTo
    {
        return $this->belongsTo(DataSourceLocation::class);
    }

    /**
     * @return HasMany<PickBatchShipment, $this>
     */
    public function pickBatchShipments(): HasMany
    {
        return $this->hasMany(PickBatchShipment::class);
    }
}
