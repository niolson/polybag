<?php

use App\Filament\Support\Percentile;

it('takes the nearest-rank percentile, whatever order the values come in', function (): void {
    $values = collect([30, 4, 10, 8, 2]);

    expect(Percentile::of($values, 50))->toBe(8.0)
        ->and(Percentile::of($values, 90))->toBe(30.0)
        ->and(Percentile::of($values, 1))->toBe(2.0)
        ->and(Percentile::of(collect([7]), 90))->toBe(7.0);
});

it('has no percentile of nothing', function (): void {
    expect(Percentile::of(collect(), 50))->toBeNull();
});
