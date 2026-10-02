<?php

namespace App\Contracts;

use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingOptions;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\Models\Package;

interface PackageShippingWorkflow
{
    public function prepareRates(Package $package): PackageShippingOptions;

    public function ship(Package $package, PackageShippingRequest $request): PackageShippingResult;

    public function autoShip(Package $package, PackageAutoShippingRequest $request): PackageShippingResult;

    /**
     * Ask each source about this package's unaccounted purchases now, rather
     * than on the next attempt to buy: a shipped result when a label came
     * back, a refusal while any stays unknown, and null once none is left.
     */
    public function checkEarlierPurchases(Package $package, string $labelFormat = 'pdf', ?int $labelDpi = null, ?int $userId = null): ?PackageShippingResult;
}
