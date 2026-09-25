<?php

namespace App\Contracts;

/**
 * A direct integration that can say which of its carrier's catalog services it
 * sells, before anything is quoted.
 *
 * ADR-0006 decision 3: a direct account sells a service when its carrier's
 * adapter supports the code. Rate shopping asks this to decide whether a
 * direct account is a seller for a shipping method at all, which is what the
 * sole-choice test for a blind purchase counts.
 *
 * Optional, not part of {@see DirectCarrierAdapter}: an adapter that does not
 * declare it is taken to sell every code of its carrier, as before.
 */
interface DeclaresSellableServices
{
    public function sellsService(string $serviceCode): bool;
}
