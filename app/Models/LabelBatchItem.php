<?php

namespace App\Models;

use App\Enums\LabelBatchItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabelBatchItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'label_batch_id',
        'shipment_id',
        'package_id',
        'status',
        'tracking_number',
        'carrier',
        'service',
        'cost',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => LabelBatchItemStatus::class,
            'cost' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<LabelBatch, $this>
     */
    public function labelBatch(): BelongsTo
    {
        return $this->belongsTo(LabelBatch::class);
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * The service as the catalog names it, read off the Label this item
     * bought; otherwise the name the source reported at purchase.
     *
     * The Label is found by tracking number among all the package's Labels,
     * not as the active one, so an item whose Label was later voided and
     * re-bought still names what this batch bought (`postage-source-split/15`).
     * Reads `package.labels.carrierService`, which a list should eager-load.
     */
    public function serviceDisplayName(): ?string
    {
        if ($this->tracking_number === null || $this->package === null) {
            return $this->service;
        }

        $label = $this->package->labels->firstWhere('tracking_number', $this->tracking_number);

        return $label?->carrierService->name ?? $this->service;
    }
}
