<?php

use App\Enums\Deliverability;
use Filament\Support\Icons\Heroicon;

it('has the expected backing values', function (): void {
    expect(Deliverability::No->value)->toBe('no')
        ->and(Deliverability::Maybe->value)->toBe('maybe')
        ->and(Deliverability::Yes->value)->toBe('yes');
});

it('has three cases', function (): void {
    expect(Deliverability::cases())->toHaveCount(3);
});

it('returns the correct labels', function (Deliverability $case, string $label): void {
    expect($case->getLabel())->toBe($label);
})->with([
    [Deliverability::No, 'No'],
    [Deliverability::Maybe, 'Maybe'],
    [Deliverability::Yes, 'Yes'],
]);

it('returns the correct colors', function (Deliverability $case, string $color): void {
    expect($case->getColor())->toBe($color);
})->with([
    [Deliverability::No, 'danger'],
    [Deliverability::Maybe, 'warning'],
    [Deliverability::Yes, 'success'],
]);

it('returns the correct icons', function (Deliverability $case, Heroicon $icon): void {
    expect($case->getIcon())->toBe($icon);
})->with([
    [Deliverability::No, Heroicon::XCircle],
    [Deliverability::Maybe, Heroicon::ExclamationTriangle],
    [Deliverability::Yes, Heroicon::CheckCircle],
]);

it('can be created from value', function (): void {
    expect(Deliverability::from('no'))->toBe(Deliverability::No)
        ->and(Deliverability::from('maybe'))->toBe(Deliverability::Maybe)
        ->and(Deliverability::from('yes'))->toBe(Deliverability::Yes);
});
