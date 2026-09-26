<?php

namespace App\Models;

use App\Enums\LabelReferenceSource;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'logo',
        'company_name',
        'custom_message',
        'return_instructions',
        'is_default',
        'active',
        'return_company',
        'return_name',
        'return_address1',
        'return_address2',
        'return_city',
        'return_state_or_province',
        'return_postal_code',
        'return_country',
        'return_phone',
        'pick_fee_first_item',
        'pick_fee_additional_item',
        'label_fee_per_package',
        'label_reference_source',
    ];

    public function hasReturnAddress(): bool
    {
        return filled($this->return_address1) && filled($this->return_city) && filled($this->return_postal_code);
    }

    protected $casts = [
        'is_default' => 'boolean',
        'active' => 'boolean',
        'pick_fee_first_item' => 'decimal:2',
        'pick_fee_additional_item' => 'decimal:2',
        'label_fee_per_package' => 'decimal:2',
        'label_reference_source' => LabelReferenceSource::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (Client $client): void {
            if ($client->is_default && $client->isDirty('is_default')) {
                static::where('id', '!=', $client->id ?? 0)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<DataSource, $this>
     */
    public function dataSources(): HasMany
    {
        return $this->hasMany(DataSource::class);
    }

    /**
     * @return HasMany<ChannelAlias, $this>
     */
    public function channelAliases(): HasMany
    {
        return $this->hasMany(ChannelAlias::class);
    }

    /**
     * @return HasMany<ShippingMethodAlias, $this>
     */
    public function shippingMethodAliases(): HasMany
    {
        return $this->hasMany(ShippingMethodAlias::class);
    }

    /**
     * @return HasMany<ShippingRule, $this>
     */
    public function shippingRules(): HasMany
    {
        return $this->hasMany(ShippingRule::class);
    }
}
