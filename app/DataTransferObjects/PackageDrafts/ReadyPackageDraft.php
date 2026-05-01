<?php

namespace App\DataTransferObjects\PackageDrafts;

use App\Models\Package;

final readonly class ReadyPackageDraft
{
    public function __construct(
        public Package $package,
        public PackageDraftSnapshot $snapshot,
    ) {}
}
