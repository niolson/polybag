<?php

namespace App\Models;

use App\Enums\LabelBatchItemStatus;
use App\Enums\LabelBatchStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property-read int|null $printable_count Items still holding a printable label; only set when the query withCounts it.
 * @property-read int|null $unprinted_count Printable items whose label has not been printed; only set when the query withCounts it.
 */
class LabelBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'bus_batch_id',
        'user_id',
        'box_size_id',
        'label_format',
        'label_dpi',
        'status',
        'total_shipments',
        'successful_shipments',
        'failed_shipments',
        'total_cost',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => LabelBatchStatus::class,
            'total_cost' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<BoxSize, $this>
     */
    public function boxSize(): BelongsTo
    {
        return $this->belongsTo(BoxSize::class);
    }

    /**
     * @return HasMany<LabelBatchItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(LabelBatchItem::class);
    }

    public function isComplete(): bool
    {
        return in_array($this->status, [
            LabelBatchStatus::Completed,
            LabelBatchStatus::CompletedWithErrors,
            LabelBatchStatus::Failed,
        ]);
    }

    /**
     * Items in this batch that still hold a printable label.
     *
     * Excludes items whose label was voided after the batch ran — those are neither
     * printed nor printable, so counting them as unprinted would leave a print
     * action that can never clear.
     *
     * @return HasMany<LabelBatchItem, $this>
     */
    public function printableItems(): HasMany
    {
        return $this->items()
            ->where('status', LabelBatchItemStatus::Success)
            ->whereHas('package', fn (Builder $query) => $query->printable());
    }

    /**
     * @return HasMany<LabelBatchItem, $this>
     */
    public function unprintedItems(): HasMany
    {
        return $this->printableItems()
            ->whereHas('package', fn (Builder $query) => $query->whereNull('label_printed_at'));
    }

    /**
     * Whether a batch still in flight has made no progress for a minute, which
     * is what a stopped queue worker looks like from the batch page.
     */
    public function isStalled(): bool
    {
        if ($this->isComplete()) {
            return false;
        }

        $lastActivity = $this->items()->max('updated_at');
        $lastActivity = $lastActivity ? max($this->updated_at, Carbon::parse($lastActivity)) : $this->updated_at;

        return $lastActivity->lte(now()->subMinute());
    }

    public function unprintedCount(): int
    {
        return $this->unprintedItems()->count();
    }

    public function printedCount(): int
    {
        return $this->printableItems()
            ->whereHas('package', fn (Builder $query) => $query->whereNotNull('label_printed_at'))
            ->count();
    }
}
