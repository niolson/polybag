<?php

namespace App\Models;

use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Events\PackageCancelled;
use App\Events\PackageShipped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Searchable;

class Package extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'shipment_id',
        'location_id',
        'box_size_id',
        'tracking_number',
        'carrier',
        'service',
        'metadata',
        'label_data',
        'label_orientation',
        'label_format',
        'label_dpi',
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
        'manifested',
    ];

    protected $casts = [
        'metadata' => 'array',
        'weight' => 'decimal:2',
        'height' => 'decimal:2',
        'width' => 'decimal:2',
        'length' => 'decimal:2',
        'cost' => 'decimal:2',
        'weight_mismatch' => 'boolean',
        'status' => PackageStatus::class,
        'shipped_at' => 'datetime',
        'ship_date' => 'date',
        'exported' => 'boolean',
        'manifested' => 'boolean',
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

    public function packageItems(): HasMany
    {
        return $this->hasMany(PackageItem::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function boxSize(): BelongsTo
    {
        return $this->belongsTo(BoxSize::class);
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function rateQuotes(): HasMany
    {
        return $this->hasMany(RateQuote::class);
    }

    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by_user_id');
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
            fn ($item) => ($item->product?->weight ?? 0) * $item->quantity
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
     * @throws \RuntimeException If the package state changed (optimistic locking)
     */
    public function markShipped(ShipResponse $response, ?int $shippedByUserId = null): void
    {
        DB::transaction(function () use ($response, $shippedByUserId): void {
            // Optimistic locking - ensure package hasn't been shipped already
            $updated = DB::table('packages')
                ->where('id', $this->id)
                ->where('status', PackageStatus::Unshipped->value)
                ->update([
                    'tracking_number' => $response->trackingNumber,
                    'cost' => $response->cost,
                    'carrier' => $response->carrier,
                    'service' => $response->service,
                    'label_data' => $response->labelData,
                    'label_orientation' => $response->labelOrientation ?? 'portrait',
                    'label_format' => $response->labelFormat ?? 'pdf',
                    'label_dpi' => $response->labelDpi,
                    'status' => PackageStatus::Shipped->value,
                    'shipped_at' => now(),
                    'ship_date' => $response->shipDate?->format('Y-m-d'),
                    'shipped_by_user_id' => $shippedByUserId,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw new \RuntimeException('Package has already been shipped or was modified by another process.');
            }

            // Refresh the model to get the updated state
            $this->refresh();
        });

        $this->load('shipment.shipmentItems');
        $this->shipment->updateShippedStatus();

        PackageShipped::dispatch($this, $this->shipment);
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
                    'carrier' => null,
                    'service' => null,
                    'cost' => null,
                    'label_data' => null,
                    'label_orientation' => null,
                    'label_format' => 'pdf',
                    'label_dpi' => null,
                    'status' => PackageStatus::Unshipped->value,
                    'shipped_at' => null,
                    'shipped_by_user_id' => null,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw new \RuntimeException('Package shipping state has changed. It may have already been voided.');
            }

            // Refresh the model to get the updated state
            $this->refresh();
        });

        $this->load('shipment.shipmentItems');
        $this->shipment->updateShippedStatus();

        PackageCancelled::dispatch($this, $this->shipment);
    }
}
