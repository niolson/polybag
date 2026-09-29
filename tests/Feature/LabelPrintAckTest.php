<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\User;

it('records a label print for a shipped package', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);
    $package = Package::factory()->shipped()->create(['label_printed_at' => null]);

    $this->actingAs($user)
        ->postJson(route('labels.printed', $package))
        ->assertOk()
        ->assertJson(['reprint' => false]);

    expect($package->fresh()->label_printed_at)->not->toBeNull();

    expect(AuditLog::where('action', AuditAction::LabelPrinted)
        ->where('auditable_id', $package->id)
        ->count())->toBe(1);
});

it('reports a second print as a reprint', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);
    $package = Package::factory()->shipped()->create([
        'label_printed_at' => now()->subHour(),
    ]);

    $this->actingAs($user)
        ->postJson(route('labels.printed', $package))
        ->assertOk()
        ->assertJson(['reprint' => true]);
});

it('rejects an unshipped package', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);
    $package = Package::factory()->create();

    $this->actingAs($user)
        ->postJson(route('labels.printed', $package))
        ->assertStatus(422);

    expect($package->fresh()->label_printed_at)->toBeNull();
});

it('rejects a shipped package with no label data', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);
    $package = Package::factory()->shipped()->create(['label_data' => null]);

    $this->actingAs($user)
        ->postJson(route('labels.printed', $package))
        ->assertStatus(422);
});

it('requires authentication', function (): void {
    $package = Package::factory()->shipped()->create();

    $this->postJson(route('labels.printed', $package))->assertUnauthorized();

    expect($package->fresh()->label_printed_at)->toBeNull();
});

it('lets a shipper record a print for a package another user shipped, auditing the printer', function (): void {
    $shipper = User::factory()->create(['role' => Role::User]);
    $otherPacker = User::factory()->create(['role' => Role::User]);
    $package = Package::factory()->shipped()->create([
        'shipped_by_user_id' => $shipper->id,
        'label_printed_at' => null,
    ]);

    $this->actingAs($otherPacker)
        ->postJson(route('labels.printed', $package))
        ->assertOk();

    expect($package->fresh()->label_printed_at)->not->toBeNull();

    expect(AuditLog::where('action', AuditAction::LabelPrinted)
        ->where('auditable_id', $package->id)
        ->sole()
        ->user_id)->toBe($otherPacker->id);
});

it('lets a manager record a print for a package someone else shipped', function (): void {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $package = Package::factory()->shipped()->create([
        'shipped_by_user_id' => User::factory()->create(['role' => Role::User])->id,
        'label_printed_at' => null,
    ]);

    $this->actingAs($manager)
        ->postJson(route('labels.printed', $package))
        ->assertOk();

    expect($package->fresh()->label_printed_at)->not->toBeNull();
});

it('lets the shipping operator record their own print', function (): void {
    $shipper = User::factory()->create(['role' => Role::User]);
    $package = Package::factory()->shipped()->create([
        'shipped_by_user_id' => $shipper->id,
        'label_printed_at' => null,
    ]);

    $this->actingAs($shipper)
        ->postJson(route('labels.printed', $package))
        ->assertOk();

    expect($package->fresh()->label_printed_at)->not->toBeNull();
});
