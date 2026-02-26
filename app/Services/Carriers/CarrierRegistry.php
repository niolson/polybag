<?php

namespace App\Services\Carriers;

use App\Contracts\CarrierAdapterInterface;
use InvalidArgumentException;

class CarrierRegistry
{
    /**
     * @var array<string, class-string<CarrierAdapterInterface>>
     */
    protected array $adapters;

    /**
     * @var array<string, CarrierAdapterInterface>
     */
    protected array $instances = [];

    public function __construct()
    {
        $this->adapters = [
            'USPS' => UspsAdapter::class,
            'FedEx' => FedexAdapter::class,
            'UPS' => UpsAdapter::class,
        ];
    }

    /**
     * Get an adapter instance for the given carrier name.
     *
     * @throws InvalidArgumentException
     */
    public function get(string $carrierName): CarrierAdapterInterface
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
     * Check if an adapter exists for the given carrier name.
     */
    public function has(string $carrierName): bool
    {
        return isset($this->adapters[$carrierName]);
    }

    /**
     * Register a new carrier adapter.
     *
     * @param  class-string<CarrierAdapterInterface>  $adapterClass
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
     * @return array<string, CarrierAdapterInterface>
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
    public function registerInstance(string $carrierName, CarrierAdapterInterface $adapter): void
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
        ];
        $this->instances = [];
    }
}
