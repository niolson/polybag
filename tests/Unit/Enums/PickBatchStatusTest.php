<?php

use App\Enums\PickBatchStatus;

it('has the expected backing values', function (): void {
    expect(PickBatchStatus::Draft->value)->toBe('draft')
        ->and(PickBatchStatus::InProgress->value)->toBe('in_progress')
        ->and(PickBatchStatus::Completed->value)->toBe('completed')
        ->and(PickBatchStatus::Cancelled->value)->toBe('cancelled');
});

it('has four cases', function (): void {
    expect(PickBatchStatus::cases())->toHaveCount(4);
});

it('returns the correct labels', function (PickBatchStatus $case, string $label): void {
    expect($case->getLabel())->toBe($label);
})->with([
    [PickBatchStatus::Draft, 'Draft'],
    [PickBatchStatus::InProgress, 'In Progress'],
    [PickBatchStatus::Completed, 'Completed'],
    [PickBatchStatus::Cancelled, 'Cancelled'],
]);

it('returns the correct colors', function (PickBatchStatus $case, string $color): void {
    expect($case->getColor())->toBe($color);
})->with([
    [PickBatchStatus::Draft, 'gray'],
    [PickBatchStatus::InProgress, 'info'],
    [PickBatchStatus::Completed, 'success'],
    [PickBatchStatus::Cancelled, 'danger'],
]);
