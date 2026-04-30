<?php

namespace App\DataTransferObjects\Shipping;

readonly class ClassifiedRate
{
    public function __construct(
        public RateResponse $rate,
        public bool $isOnTime,
    ) {}
}
