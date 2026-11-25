<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyShippingStat extends Model
{
    protected $fillable = [
        'date',
        'carrier',
        'service',
        'carrier_service_id',
        'channel_id',
        'shipping_method_id',
        'location_id',
        'client_id',
        'package_count',
        'costed_package_count',
        'total_cost',
        'total_weight',
    ];

    protected $casts = [
        'package_count' => 'integer',
        'costed_package_count' => 'integer',
        'total_cost' => 'decimal:2',
        'total_weight' => 'decimal:2',
    ];

    /**
     * Ensure the date is always stored as Y-m-d (no time component).
     * SQLite stores date-cast values with the full datetime format,
     * which breaks exact-match queries and pluck-by-date lookups.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Carbon::parse($value)->startOfDay() : null,
            set: fn ($value): ?string => $value ? Carbon::parse($value)->format('Y-m-d') : null,
        );
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<ShippingMethod, $this>
     */
    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The catalog service the packages' active Labels were bought as; null
     * when a Label names none.
     *
     * @return BelongsTo<CarrierService, $this>
     */
    public function carrierService(): BelongsTo
    {
        return $this->belongsTo(CarrierService::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
