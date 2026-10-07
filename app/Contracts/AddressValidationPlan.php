<?php

namespace App\Contracts;

use App\Models\Shipment;

interface AddressValidationPlan
{
    /**
     * The validators to try for this Shipment, in order.
     *
     * @return list<AddressValidationInterface>
     */
    public function validatorsFor(Shipment $shipment): array;
}
