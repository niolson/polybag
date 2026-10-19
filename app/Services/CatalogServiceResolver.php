<?php

namespace App\Services;

use App\Models\Carrier;
use App\Models\CarrierService;
use App\Services\ServiceInference\ServiceRuleset;

/**
 * The catalog service a Label's service is, where nothing at purchase named it
 * — issue `postage-source-split/15`.
 *
 * A quoted purchase needs none of this: the rate already carries its
 * `CarrierService`, and the Label records it. This is for the case that
 * reaches a Label with only a name: a service the inference ladder derived.
 *
 * Every answer is snapshotted where it is written and never asked again on
 * read, so renaming a catalog row or editing a table later does not change what
 * an earlier Label meant.
 */
class CatalogServiceResolver
{
    public function __construct(private readonly ServiceRuleset $ruleset) {}

    /**
     * The catalog service an inferred service name is, through the ruleset's
     * own table — the same vocabulary that produced the name.
     */
    public function forInferredService(?int $carrierId, ?string $service): ?int
    {
        $carrier = $carrierId === null ? null : Carrier::find($carrierId);

        if ($carrier === null) {
            return null;
        }

        $serviceCode = $this->ruleset->catalogServiceCodeFor($carrier->name, $service);

        return $serviceCode === null ? null : $this->serviceWithCode($carrier, $serviceCode);
    }

    private function serviceWithCode(Carrier $carrier, string $serviceCode): ?int
    {
        return $carrier->carrierServices()
            ->where('service_code', $serviceCode)
            ->orderBy('id')
            ->value('id');
    }
}
