<?php

namespace App\Services\Carriers;

use App\Contracts\CarrierAdapterInterface;
use InvalidArgumentException;

class CarrierRegistry
{
    /**
     * @var array<string, class-string<CarrierAdapterInterface>>
     */
    protected static array $adapters = [
        'USPS' => UspsAdapter::class,
        'FedEx' => FedexAdapter::class,
        'UPS' => UpsAdapter::class,
    ];

    /**
     * @var array<string, CarrierAdapterInterface>
     */
    protected static array $instances = [];

    /**
     * Get an adapter instance for the given carrier name.
     *
     * @throws InvalidArgumentException
     */
    public static function get(string $carrierName): CarrierAdapterInterface
    {
        if (! self::has($carrierName)) {
            throw new InvalidArgumentException("Unknown carrier: {$carrierName}");
        }

        if (! isset(self::$instances[$carrierName])) {
            $adapterClass = self::$adapters[$carrierName];
            self::$instances[$carrierName] = new $adapterClass;
        }

        return self::$instances[$carrierName];
    }

    /**
     * Check if an adapter exists for the given carrier name.
     */
    public static function has(string $carrierName): bool
    {
        return isset(self::$adapters[$carrierName]);
    }

    /**
     * Register a new carrier adapter.
     *
     * @param  class-string<CarrierAdapterInterface>  $adapterClass
     */
    public static function register(string $carrierName, string $adapterClass): void
    {
        self::$adapters[$carrierName] = $adapterClass;
        unset(self::$instances[$carrierName]);
    }

    /**
     * Get all registered carrier names.
     *
     * @return array<string>
     */
    public static function getCarrierNames(): array
    {
        return array_keys(self::$adapters);
    }

    /**
     * Get all configured carrier adapters.
     *
     * @return array<string, CarrierAdapterInterface>
     */
    public static function getConfiguredAdapters(): array
    {
        $configured = [];

        foreach (self::$adapters as $name => $class) {
            $adapter = self::get($name);
            if ($adapter->isConfigured()) {
                $configured[$name] = $adapter;
            }
        }

        return $configured;
    }

    /**
     * Register an adapter instance directly (useful for testing).
     */
    public static function registerInstance(string $carrierName, CarrierAdapterInterface $adapter): void
    {
        self::$adapters[$carrierName] = get_class($adapter);
        self::$instances[$carrierName] = $adapter;
    }

    /**
     * Clear cached instances (useful for testing).
     */
    public static function clearInstances(): void
    {
        self::$instances = [];
    }

    /**
     * Reset to default adapters (useful for testing).
     */
    public static function reset(): void
    {
        self::$adapters = [
            'USPS' => UspsAdapter::class,
            'FedEx' => FedexAdapter::class,
            'UPS' => UpsAdapter::class,
        ];
        self::$instances = [];
    }
}
