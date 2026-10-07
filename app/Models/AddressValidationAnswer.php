<?php

namespace App\Models;

use App\Enums\AddressValidationOutcome;
use App\Enums\AddressValidator;
use App\Enums\Deliverability;
use App\Enums\ValidationReason;
use App\Enums\ValidationTrigger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One validator's answer for a Shipment: a verdict, never a copy of the
 * address. A validator that couldn't run writes no row, so every row is a
 * data point. Rows outlive address changes, since an edit after validation is
 * itself evidence about the answer, stamped as `address_changed_at`.
 *
 * A shadow answer is FedEx asked after another validator settled the
 * address (`address-validation-routing/10`): data for us, never part of the
 * Shipment's result, and never shown to tenants.
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
        'shadow',
        'shadows_answer_id',
        'street_differs',
        'house_number_differs',
        'city_differs',
        'postcode_differs',
        'address_changed_at',
    ];

    protected $casts = [
        'validator' => AddressValidator::class,
        'paid' => 'boolean',
        'outcome' => AddressValidationOutcome::class,
        'deliverability' => Deliverability::class,
        'reason' => ValidationReason::class,
        'trigger' => ValidationTrigger::class,
        'shadow' => 'boolean',
        'street_differs' => 'boolean',
        'house_number_differs' => 'boolean',
        'city_differs' => 'boolean',
        'postcode_differs' => 'boolean',
        'address_changed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<AddressValidationAnswer, $this>
     */
    public function shadowedAnswer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'shadows_answer_id');
    }

    /**
     * Answers that took part in validating the Shipment, without shadow
     * answers. Anything shown to tenants or counted as validator use starts here.
     *
     * @param  Builder<AddressValidationAnswer>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('shadow', false);
    }
}
