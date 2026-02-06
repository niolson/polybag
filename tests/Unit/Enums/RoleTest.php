<?php

use App\Enums\Role;

it('has the expected backing values', function (): void {
    expect(Role::User->value)->toBe('user')
        ->and(Role::Manager->value)->toBe('manager')
        ->and(Role::Admin->value)->toBe('admin');
});

it('has three cases', function (): void {
    expect(Role::cases())->toHaveCount(3);
});

describe('isAtLeast', function (): void {
    it('user is at least user', function (): void {
        expect(Role::User->isAtLeast(Role::User))->toBeTrue();
    });

    it('user is not at least manager', function (): void {
        expect(Role::User->isAtLeast(Role::Manager))->toBeFalse();
    });

    it('user is not at least admin', function (): void {
        expect(Role::User->isAtLeast(Role::Admin))->toBeFalse();
    });

    it('manager is at least user', function (): void {
        expect(Role::Manager->isAtLeast(Role::User))->toBeTrue();
    });

    it('manager is at least manager', function (): void {
        expect(Role::Manager->isAtLeast(Role::Manager))->toBeTrue();
    });

    it('manager is not at least admin', function (): void {
        expect(Role::Manager->isAtLeast(Role::Admin))->toBeFalse();
    });

    it('admin is at least user', function (): void {
        expect(Role::Admin->isAtLeast(Role::User))->toBeTrue();
    });

    it('admin is at least manager', function (): void {
        expect(Role::Admin->isAtLeast(Role::Manager))->toBeTrue();
    });

    it('admin is at least admin', function (): void {
        expect(Role::Admin->isAtLeast(Role::Admin))->toBeTrue();
    });
});
