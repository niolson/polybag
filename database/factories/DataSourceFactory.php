<?php

namespace Database\Factories;

use App\Enums\OffAmazonShippingStatus;
use App\Enums\PostageSetting;
use App\Models\DataSource;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSource>
 */
class DataSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'source_type' => DatabaseSource::class,
            'active' => true,
            'import_enabled' => true,
            'offers_off_amazon_shipping' => false,
            'global_export' => false,
            'settings' => [],
            'secret_settings' => null,
        ];
    }

    public function shopify(): static
    {
        return $this->state([
            'source_type' => ShopifySource::class,
            'settings' => [
                'shop_domain' => 'test.myshopify.com',
                'channel_name' => 'Shopify',
            ],
        ]);
    }

    public function amazon(): static
    {
        return $this->state([
            'source_type' => AmazonSource::class,
            'settings' => [
                'marketplace_id' => 'ATVPDKIKX0DER',
                'channel_name' => 'Amazon',
            ],
        ]);
    }

    /**
     * A Shopify or Amazon connection with this postage setting (ADR-0006
     * decision 6). Without it, a connection gets its driver's default.
     */
    public function sellingPostage(PostageSetting $setting = PostageSetting::PackerAndAutomation): static
    {
        return $this->state(['postage_setting' => $setting]);
    }

    /**
     * An Amazon connection that offers Amazon Shipping for orders from other
     * channels, with the account already checked and enabled.
     */
    public function offeringOffAmazonShipping(OffAmazonShippingStatus $status = OffAmazonShippingStatus::Enabled): static
    {
        return $this->amazon()->state([
            'offers_off_amazon_shipping' => true,
            'off_amazon_shipping_status' => $status,
            'off_amazon_shipping_checked_at' => now(),
        ]);
    }

    /**
     * A connection shared across every client. `HasDefaultClient` stamps the
     * default client on create, so the Client is cleared afterwards, as it is
     * when an operator clears it on the edit form.
     */
    public function unassigned(): static
    {
        return $this->afterCreating(fn (DataSource $source) => $source->forceFill(['client_id' => null])->saveQuietly());
    }

    public function importDisabled(): static
    {
        return $this->state(['import_enabled' => false]);
    }

    public function globalExport(): static
    {
        return $this->state([
            'source_type' => DatabaseSource::class,
            'global_export' => true,
            'settings' => ['export_enabled' => true],
        ]);
    }
}
