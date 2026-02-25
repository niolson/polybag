<?php

namespace App\Models;

use App\DataTransferObjects\Shipping\ShipResponse;
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
        'shipped',
        'shipped_at',
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
        'shipped' => 'boolean',
        'shipped_at' => 'datetime',
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

    public function boxSize(): BelongsTo
    {
        return $this->belongsTo(BoxSize::class);
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by_user_id');
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
                ->where('shipped', false)
                ->update([
                    'tracking_number' => $response->trackingNumber,
                    'cost' => $response->cost,
                    'carrier' => $response->carrier,
                    'service' => $response->service,
                    'label_data' => $response->labelData,
                    'label_orientation' => $response->labelOrientation ?? 'portrait',
                    'label_format' => $response->labelFormat ?? 'pdf',
                    'label_dpi' => $response->labelDpi,
                    'shipped' => true,
                    'shipped_at' => now(),
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
                ->where('shipped', true)
                ->update([
                    'tracking_number' => null,
                    'carrier' => null,
                    'service' => null,
                    'cost' => null,
                    'label_data' => null,
                    'label_orientation' => null,
                    'label_format' => 'pdf',
                    'label_dpi' => null,
                    'shipped' => false,
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
