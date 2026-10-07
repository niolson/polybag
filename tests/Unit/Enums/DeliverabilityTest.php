<?php

use App\Enums\Deliverability;
use Filament\Support\Icons\Heroicon;

it('has the expected backing values', function (): void {
    expect(Deliverability::Yes->value)->toBe('yes')
        ->and(Deliverability::Verified->value)->toBe('verified')
        ->and(Deliverability::Partial->value)->toBe('partial')
        ->and(Deliverability::Unverified->value)->toBe('unverified')
        ->and(Deliverability::No->value)->toBe('no')
        ->and(Deliverability::NotChecked->value)->toBe('not_checked');
});

it('orders its cases from strongest to weakest evidence', function (): void {
    expect(Deliverability::cases())->toBe([
        Deliverability::Yes,
        Deliverability::Verified,
        Deliverability::Partial,
        Deliverability::Unverified,
        Deliverability::No,
        Deliverability::NotChecked,
    ]);
});

it('treats a delivery point and a reference-data match as confirmed', function (): void {
    expect(Deliverability::confirmed())->toBe([Deliverability::Yes, Deliverability::Verified]);
});

it('returns the correct labels', function (Deliverability $case, string $label): void {
    expect($case->getLabel())->toBe($label);
})->with([
    [Deliverability::Yes, 'Deliverable'],
    [Deliverability::Verified, 'Verified'],
    [Deliverability::Partial, 'Partly verified'],
    [Deliverability::Unverified, "Couldn't verify"],
    [Deliverability::No, 'Not deliverable'],
    [Deliverability::NotChecked, 'Not Checked'],
]);

it('returns the correct colors', function (Deliverability $case, string $color): void {
    expect($case->getColor())->toBe($color);
})->with([
    [Deliverability::Yes, 'success'],
    [Deliverability::Verified, 'success'],
    [Deliverability::Partial, 'warning'],
    [Deliverability::Unverified, 'gray'],
    [Deliverability::No, 'danger'],
    [Deliverability::NotChecked, 'gray'],
]);

it('returns the correct icons', function (Deliverability $case, Heroicon $icon): void {
    expect($case->getIcon())->toBe($icon);
})->with([
    [Deliverability::Yes, Heroicon::CheckCircle],
    [Deliverability::Verified, Heroicon::ShieldCheck],
    [Deliverability::Partial, Heroicon::ExclamationTriangle],
    [Deliverability::Unverified, Heroicon::QuestionMarkCircle],
    [Deliverability::No, Heroicon::XCircle],
    [Deliverability::NotChecked, Heroicon::QuestionMarkCircle],
]);

it('can be created from value', function (): void {
    expect(Deliverability::from('verified'))->toBe(Deliverability::Verified)
        ->and(Deliverability::from('partial'))->toBe(Deliverability::Partial)
        ->and(Deliverability::from('unverified'))->toBe(Deliverability::Unverified)
        ->and(Deliverability::tryFrom('maybe'))->toBeNull();
});
