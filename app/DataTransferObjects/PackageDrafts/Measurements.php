<?php

namespace App\DataTransferObjects\PackageDrafts;

final readonly class Measurements
{
    /**
     * The weight, in lbs, a parcel ships at when the scale holds a steady 0.00:
     * there is something on it, too light for the scale to read.
     */
    public const string MINIMUM_SCALE_WEIGHT = '0.05';

    public function __construct(
        public string|float|int|null $weight,
        public string|float|int|null $height,
        public string|float|int|null $width,
        public string|float|int|null $length,
    ) {}

    public function hasPositiveValues(): bool
    {
        return $this->isPositive($this->weight)
            && $this->isPositive($this->height)
            && $this->isPositive($this->width)
            && $this->isPositive($this->length);
    }

    private function isPositive(string|float|int|null $value): bool
    {
        return is_numeric($value) && (float) $value > 0;
    }
}
