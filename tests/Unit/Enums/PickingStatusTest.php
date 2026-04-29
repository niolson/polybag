<?php

use App\Enums\PickingStatus;

it('has the expected backing values', function (): void {
    expect(PickingStatus::Pending->value)->toBe('pending')
        ->and(PickingStatus::Batched->value)->toBe('batched')
        ->and(PickingStatus::Picked->value)->toBe('picked');
});

it('has three cases', function (): void {
    expect(PickingStatus::cases())->toHaveCount(3);
});

it('returns the correct labels', function (PickingStatus $case, string $label): void {
    expect($case->getLabel())->toBe($label);
})->with([
    [PickingStatus::Pending, 'Pending'],
    [PickingStatus::Batched, 'Batched'],
    [PickingStatus::Picked, 'Picked'],
]);

it('returns the correct colors', function (PickingStatus $case, string $color): void {
    expect($case->getColor())->toBe($color);
})->with([
    [PickingStatus::Pending, 'gray'],
    [PickingStatus::Batched, 'info'],
    [PickingStatus::Picked, 'success'],
]);

it('can be created from value', function (): void {
    expect(PickingStatus::from('pending'))->toBe(PickingStatus::Pending)
        ->and(PickingStatus::from('batched'))->toBe(PickingStatus::Batched)
        ->and(PickingStatus::from('picked'))->toBe(PickingStatus::Picked);
});
