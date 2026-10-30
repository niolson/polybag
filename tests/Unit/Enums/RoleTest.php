<?php

use App\Enums\Role;

it('has the expected backing values', function (): void {
    expect(Role::Packer->value)->toBe('packer')
        ->and(Role::Manager->value)->toBe('manager')
        ->and(Role::Admin->value)->toBe('admin');
});

it('has three cases', function (): void {
    expect(Role::cases())->toHaveCount(3);
});

describe('isAtLeast', function (): void {
    it('user is at least user', function (): void {
        expect(Role::Packer->isAtLeast(Role::Packer))->toBeTrue();
    });

    it('user is not at least manager', function (): void {
        expect(Role::Packer->isAtLeast(Role::Manager))->toBeFalse();
    });

    it('user is not at least admin', function (): void {
        expect(Role::Packer->isAtLeast(Role::Admin))->toBeFalse();
    });

    it('manager is at least user', function (): void {
        expect(Role::Manager->isAtLeast(Role::Packer))->toBeTrue();
    });

    it('manager is at least manager', function (): void {
        expect(Role::Manager->isAtLeast(Role::Manager))->toBeTrue();
    });

    it('manager is not at least admin', function (): void {
        expect(Role::Manager->isAtLeast(Role::Admin))->toBeFalse();
    });

    it('admin is at least user', function (): void {
        expect(Role::Admin->isAtLeast(Role::Packer))->toBeTrue();
    });

    it('admin is at least manager', function (): void {
        expect(Role::Admin->isAtLeast(Role::Manager))->toBeTrue();
    });

    it('admin is at least admin', function (): void {
        expect(Role::Admin->isAtLeast(Role::Admin))->toBeTrue();
    });
});
