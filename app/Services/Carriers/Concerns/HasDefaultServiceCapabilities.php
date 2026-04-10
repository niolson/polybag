<?php

namespace App\Services\Carriers\Concerns;

use App\Enums\ServiceCapability;

/**
 * Default implementation of serviceCapability() for carrier adapters.
 *
 * Returns NotImplemented for any service code not explicitly declared by the adapter.
 * Adapters override this method to declare Supported and Prohibited services.
 */
trait HasDefaultServiceCapabilities
{
    public function serviceCapability(string $serviceCode): ServiceCapability
    {
        return ServiceCapability::NotImplemented;
    }
}
