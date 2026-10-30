<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Client::query()->where('is_default', true)->exists() || Client::factory()->default()->create();
    ShippingMethod::factory()->create(['name' => 'Standard Ground']);
});

it('refuses to seed while the carriers are real', function (): void {
    config(['app.fake_carriers' => false]);

    $this->artisan('app:seed-load-test', ['--packers' => 1, '--shipments' => 1])
        ->expectsOutputToContain('FAKE_CARRIERS is off')
        ->assertFailed();

    expect(Shipment::count())->toBe(0)
        ->and(User::where('username', 'like', 'loadtest-%')->count())->toBe(0);
});

it('seeds packers with their own open Shipments and prints the plan', function (): void {
    config(['app.fake_carriers' => true]);

    $exitCode = Artisan::call('app:seed-load-test', ['--packers' => 2, '--shipments' => 3, '--items' => 2, '--password' => 'secret-pass']);
    $plan = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($plan['password'])->toBe('secret-pass')
        ->and($plan['packers'])->toHaveCount(2)
        ->and(array_column($plan['packers'], 'username'))->toBe(['loadtest-1', 'loadtest-2'])
        ->and($plan['packers'][0]['shipments'])->toHaveCount(3);

    $user = User::where('username', 'loadtest-1')->sole();
    expect($user->role)->toBe(Role::Packer)
        ->and(auth()->attempt(['username' => 'loadtest-1', 'password' => 'secret-pass']))->toBeTrue();

    $shipment = Shipment::where('shipment_reference', $plan['packers'][1]['shipments'][2])->sole();
    expect($shipment->status->value)->toBe('open')
        ->and($shipment->shipping_method_id)->toBe(ShippingMethod::where('name', 'Standard Ground')->value('id'))
        ->and($shipment->shipmentItems)->toHaveCount(2)
        ->and($shipment->shipmentItems->every(fn ($item): bool => filled($item->product->barcode)))->toBeTrue();
});

it('gives each run its own references', function (): void {
    config(['app.fake_carriers' => true]);

    Artisan::call('app:seed-load-test', ['--packers' => 1, '--shipments' => 2]);
    Artisan::call('app:seed-load-test', ['--packers' => 1, '--shipments' => 2]);

    expect(Shipment::count())->toBe(4)
        ->and(Shipment::distinct()->count('shipment_reference'))->toBe(4)
        ->and(User::where('username', 'loadtest-1')->count())->toBe(1);
});
