<?php

namespace App\Services\ShipmentImport;

use App\Contracts\ImportSourceInterface;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\ImportSource;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;

class ImportReferenceResolver
{
    /** @var array<string, int> */
    private array $channelCache = [];

    /** @var array<string, ?int> */
    private array $shippingMethodCache = [];

    /** @var array<string, int> */
    private array $productCache = [];

    public function warm(): void
    {
        ChannelAlias::all()->each(function (ChannelAlias $alias): void {
            $this->channelCache[$alias->reference] = $alias->channel_id;
        });

        Channel::all()->each(function (Channel $channel): void {
            $this->channelCache[(string) $channel->id] = $channel->id;
        });

        ShippingMethodAlias::all()->each(function (ShippingMethodAlias $alias): void {
            $this->shippingMethodCache[$alias->reference] = $alias->shipping_method_id;
        });

        ShippingMethod::pluck('id')->each(function (int $id): void {
            $this->shippingMethodCache[(string) $id] = $id;
        });

        Product::pluck('id', 'sku')->each(function (int $id, string $sku): void {
            $this->productCache[$sku] = $id;
        });
    }

    public function importSourceFor(ImportSourceInterface $source): ImportSource
    {
        $configKey = $source->getSourceName();
        $config = config("shipment-import.sources.{$configKey}", []);

        return ImportSource::firstOrCreate(
            ['config_key' => $configKey],
            [
                'name' => (string) ($config['name'] ?? str($configKey)->replace(['_', '-'], ' ')->title()),
                'driver' => (string) ($config['driver'] ?? $source::class),
                'active' => (bool) ($config['enabled'] ?? true),
                'settings' => null,
            ],
        );
    }

    public function shippingMethodIdFor(array $data): ?int
    {
        $reference = $data['shipping_method_id'] ?? null;

        if (! $reference) {
            return null;
        }

        return $this->shippingMethodCache[(string) $reference] ?? null;
    }

    public function channelIdFor(array $data): ?int
    {
        $reference = $data['channel_id'] ?? null;

        if (! $reference) {
            return null;
        }

        return $this->channelCache[(string) $reference] ?? null;
    }

    /**
     * @return array{id: int|null, created: bool, updated: bool}
     */
    public function productIdFor(array $itemData): array
    {
        $sku = $itemData['sku'] ?? null;

        if (! $sku) {
            return ['id' => null, 'created' => false, 'updated' => false];
        }

        if (config('shipment-import.behavior.auto_update_products', true)) {
            $updateData = array_filter([
                'name' => $itemData['name'] ?? $sku,
                'description' => $itemData['description'] ?? null,
                'barcode' => $itemData['barcode'] ?? null,
                'weight' => $itemData['weight'] ?? null,
            ], fn ($value) => $value !== null);

            $product = Product::updateOrCreate(
                ['sku' => $sku],
                array_merge($updateData, ['active' => true])
            );

            $this->productCache[$sku] = $product->id;

            return [
                'id' => $product->id,
                'created' => $product->wasRecentlyCreated,
                'updated' => ! $product->wasRecentlyCreated && $product->wasChanged(),
            ];
        }

        return [
            'id' => $this->productCache[$sku] ?? null,
            'created' => false,
            'updated' => false,
        ];
    }
}
