<?php

namespace App\Services\Carriers;

use App\Contracts\BlindPurchaseSource;
use App\Contracts\CarrierAdapterInterface;
use App\Contracts\CarrierPolicy;
use App\Contracts\DirectCarrierAdapter;
use App\Contracts\DiscoversServices;
use App\Contracts\PostageOfferSource;
use InvalidArgumentException;

class CarrierRegistry
{
    /**
     * @var array<string, class-string<PostageOfferSource>>
     */
    protected array $adapters;

    /**
     * @var array<string, PostageOfferSource>
     */
    protected array $instances = [];

    public function __construct()
    {
        $this->adapters = [
            'USPS' => UspsAdapter::class,
            'FedEx' => FedexAdapter::class,
            'UPS' => UpsAdapter::class,
            ShopifyAdapter::CARRIER_NAME => ShopifyAdapter::class,
            AmazonBuyShippingAdapter::SOURCE_NAME => AmazonBuyShippingAdapter::class,
        ];
    }

    /**
     * Get an adapter instance for the given carrier name.
     *
     * Typed at the widest thing registered here: not every source can quote.
     * A caller that needs rates asks {@see self::quotingAdapterFor()}, and one
     * that needs a blind purchase asks {@see self::blindPurchaseSourceFor()}.
     *
     * @throws InvalidArgumentException
     */
    public function get(string $carrierName): PostageOfferSource
    {
        if (! $this->has($carrierName)) {
            throw new InvalidArgumentException("Unknown carrier: {$carrierName}");
        }

        if (! isset($this->instances[$carrierName])) {
            $adapterClass = $this->adapters[$carrierName];
            $this->instances[$carrierName] = new $adapterClass;
        }

        return $this->instances[$carrierName];
    }

    /**
     * The carrier-policy view of a registered adapter, or null when the name is
     * unknown or belongs to something that is not a carrier.
     *
     * Shopify is registered here for the offers it sells and answers nothing
     * about carriers, so callers asking a carrier question — can this be
     * manifested? what will it insure? — get null rather than a no-op.
     */
    public function policyFor(?string $carrierName): ?CarrierPolicy
    {
        $adapter = $this->adapterOrNull($carrierName);

        return $adapter instanceof CarrierPolicy ? $adapter : null;
    }

    /**
     * A registered source that can quote a price before the label is bought.
     * Null for an unknown name or a blind-purchase source.
     *
     * The one gate that keeps blind purchase out of the automated paths that
     * work in rates: a shipping rule naming a source that answers null here has
     * no rate to pre-select, and nothing invents one (ADR-0003 decision 5).
     */
    public function quotingAdapterFor(?string $carrierName): ?CarrierAdapterInterface
    {
        $adapter = $this->adapterOrNull($carrierName);

        return $adapter instanceof CarrierAdapterInterface ? $adapter : null;
    }

    /**
     * A registered source that sells postage it cannot quote, or null.
     */
    public function blindPurchaseSourceFor(?string $carrierName): ?BlindPurchaseSource
    {
        $adapter = $this->adapterOrNull($carrierName);

        return $adapter instanceof BlindPurchaseSource ? $adapter : null;
    }

    /**
     * A registered source whose services are discovered per quote, or null.
     *
     * A shipping rule naming such a source names the source rather than a
     * service, and selects among the offers it quotes instead of pre-selecting
     * a rate for it.
     */
    public function discoveringSourceFor(?string $carrierName): ?DiscoversServices
    {
        $adapter = $this->adapterOrNull($carrierName);

        return $adapter instanceof DiscoversServices ? $adapter : null;
    }

    /**
     * A registered adapter that is also the carrier itself, so it can void and
     * track the labels it sold us. Null for an unknown name or a resale channel.
     */
    public function directAdapterFor(?string $carrierName): ?DirectCarrierAdapter
    {
        $adapter = $this->adapterOrNull($carrierName);

        return $adapter instanceof DirectCarrierAdapter ? $adapter : null;
    }

    /**
     * Same, but for the paths where no carrier is a broken package rather than
     * a recoverable answer — voiding a directly-bought label has nowhere else to
     * go.
     *
     * @throws InvalidArgumentException
     */
    public function directAdapterOrFail(string $carrierName): DirectCarrierAdapter
    {
        return $this->directAdapterFor($carrierName)
            ?? throw new InvalidArgumentException("Unknown carrier: {$carrierName}");
    }

    private function adapterOrNull(?string $carrierName): ?PostageOfferSource
    {
        if (! $carrierName || ! $this->has($carrierName)) {
            return null;
        }

        return $this->get($carrierName);
    }

    /**
     * Check if an adapter exists for the given carrier name.
     */
    public function has(string $carrierName): bool
    {
        return isset($this->adapters[$carrierName]);
    }

    /**
     * Register a new carrier adapter.
     *
     * @param  class-string<PostageOfferSource>  $adapterClass
     */
    public function register(string $carrierName, string $adapterClass): void
    {
        $this->adapters[$carrierName] = $adapterClass;
        unset($this->instances[$carrierName]);
    }

    /**
     * Get all registered carrier names.
     *
     * @return array<string>
     */
    public function getCarrierNames(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * Get all configured carrier adapters.
     *
     * @return array<string, PostageOfferSource>
     */
    public function getConfiguredAdapters(): array
    {
        $configured = [];

        foreach ($this->adapters as $name => $class) {
            $adapter = $this->get($name);
            if ($adapter->isConfigured()) {
                $configured[$name] = $adapter;
            }
        }

        return $configured;
    }

    /**
     * Register an adapter instance directly (useful for testing).
     */
    public function registerInstance(string $carrierName, PostageOfferSource $adapter): void
    {
        $this->adapters[$carrierName] = get_class($adapter);
        $this->instances[$carrierName] = $adapter;
    }

    /**
     * Clear cached instances (useful for testing).
     */
    public function clearInstances(): void
    {
        $this->instances = [];
    }

    /**
     * Reset to default adapters (useful for testing).
     */
    public function reset(): void
    {
        $this->adapters = [
            'USPS' => UspsAdapter::class,
            'FedEx' => FedexAdapter::class,
            'UPS' => UpsAdapter::class,
            ShopifyAdapter::CARRIER_NAME => ShopifyAdapter::class,
            AmazonBuyShippingAdapter::SOURCE_NAME => AmazonBuyShippingAdapter::class,
        ];
        $this->instances = [];
    }
}
