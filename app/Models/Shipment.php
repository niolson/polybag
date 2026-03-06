<?php

namespace App\Models;

use App\Enums\Deliverability;
use App\Enums\PackageStatus;
use App\Enums\ShipmentStatus;
use App\Services\AddressValidationService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Searchable;

class Shipment extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'shipment_reference',
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
        'status',
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
        'deliver_by' => 'date',
        'metadata' => 'array',
    ];

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
            $this->update(['status' => ShipmentStatus::Shipped]);

            return;
        }

        $shippedPackageIds = $this->packages()->where('status', PackageStatus::Shipped)->pluck('id');

        $packedQuantities = PackageItem::whereIn('package_id', $shippedPackageIds)
            ->selectRaw('shipment_item_id, SUM(quantity) as total_packed')
            ->groupBy('shipment_item_id')
            ->pluck('total_packed', 'shipment_item_id');

        $allItemsShipped = $this->shipmentItems->every(function (ShipmentItem $item) use ($packedQuantities) {
            return ($packedQuantities[$item->id] ?? 0) >= $item->quantity;
        });

        $this->update(['status' => $allItemsShipped ? ShipmentStatus::Shipped : ShipmentStatus::Open]);
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

    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
