<?php

namespace App\Models;

use App\Enums\ShippingRuleAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<array{type: string, data: array<string, mixed>}>|null $conditions
 */
class ShippingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'name',
        'shipping_method_id',
        'priority',
        'conditions',
        'action',
        'carrier_service_id',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'action' => ShippingRuleAction::class,
            'conditions' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<ShippingMethod, $this>
     */
    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    /**
     * @return BelongsTo<CarrierService, $this>
     */
    public function carrierService(): BelongsTo
    {
        return $this->belongsTo(CarrierService::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('enabled', true)->orderBy('priority');
    }
}
