<?php

namespace App\DataTransferObjects\PackageDrafts;

use App\Models\BoxSize;

final readonly class BatchPackageDraftInput
{
    public function __construct(
        public BoxSize $boxSize,
    ) {}
}
