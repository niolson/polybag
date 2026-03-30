<?php

use App\Models\Shipment;

it('creates a test package when fake carriers are enabled', function (): void {
    config(['app.fake_carriers' => true]);

    $shipment = Shipment::factory()->create([
        'postal_code' => '12345',
    ]);

    $response = $this->postJson('/api/test/create-package');

    $response->assertOk()
        ->assertJsonStructure(['package_id']);

    expect($shipment->packages()->count())->toBe(1);
});
