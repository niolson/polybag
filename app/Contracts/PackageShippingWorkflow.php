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
}
