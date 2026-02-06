<?php

namespace App\Models;

use App\Enums\Deliverability;
use App\Services\AddressValidationService;
use App\Services\SettingsService;
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
        'state',
        'zip',
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
        'validated_state',
        'validated_zip',
        'validated_country',
        'validated_residential',
        'shipping_method_reference',
        'shipping_method_id',
        'channel_reference',
        'channel_id',
        'shipped',
    ];

    protected $casts = [
        'checked' => 'boolean',
        'residential' => 'boolean',
        'validated_residential' => 'boolean',
        'value' => 'decimal:2',
        'deliverability' => Deliverability::class,
        'shipped' => 'boolean',
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
     * Recalculate and persist the shipped flag based on package status.
     */
    public function updateShippedStatus(): void
    {
        $hasShippedPackage = $this->packages()->where('shipped', true)->exists();

        if (! $hasShippedPackage) {
            $this->update(['shipped' => false]);

            return;
        }

        if (! SettingsService::get('packing_validation_enabled', true)) {
            $this->update(['shipped' => true]);

            return;
        }

        $shippedPackageIds = $this->packages()->where('shipped', true)->pluck('id');

        $packedQuantities = PackageItem::whereIn('package_id', $shippedPackageIds)
            ->selectRaw('shipment_item_id, SUM(quantity) as total_packed')
            ->groupBy('shipment_item_id')
            ->pluck('total_packed', 'shipment_item_id');

        $allItemsShipped = $this->shipmentItems->every(function (ShipmentItem $item) use ($packedQuantities) {
            return ($packedQuantities[$item->id] ?? 0) >= $item->quantity;
        });

        $this->update(['shipped' => $allItemsShipped]);
    }

    /**
     * Validate the shipment's address using USPS API.
     */
    public function validateAddress(): void
    {
        app(AddressValidationService::class)->validate($this);
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
