<?php

namespace App\Models;

use App\Enums\AddressValidationOutcome;
use App\Enums\AddressValidator;
use App\Enums\Deliverability;
use App\Enums\ValidationReason;
use App\Enums\ValidationTrigger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One validator's answer for a Shipment: a verdict, never a copy of the
 * address. A validator that couldn't run writes no row, so every row is a
 * data point. Rows outlive address changes, since an edit after validation is
 * itself evidence about the answer.
 */
class AddressValidationAnswer extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'shipment_id',
        'validator',
        'paid',
        'outcome',
        'deliverability',
        'reason',
        'country',
        'trigger',
    ];

    protected $casts = [
        'validator' => AddressValidator::class,
        'paid' => 'boolean',
        'outcome' => AddressValidationOutcome::class,
        'deliverability' => Deliverability::class,
        'reason' => ValidationReason::class,
        'trigger' => ValidationTrigger::class,
    ];

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
