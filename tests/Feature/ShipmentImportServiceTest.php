<?php

use App\Contracts\ImportSourceInterface;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Services\ShipmentImport\ShipmentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

function fakeSource(Collection $shipments, Collection $items = new Collection): ImportSourceInterface
{
    return new class($shipments, $items) implements ImportSourceInterface
    {
        public function __construct(
            private Collection $shipments,
            private Collection $items,
        ) {}

        public function getSourceName(): string
        {
            return 'test';
        }

        public function fetchShipments(): Collection
        {
            return $this->shipments;
        }

        public function fetchShipmentItems(string $shipmentReference): Collection
        {
            return $this->items;
        }

        public function validateConfiguration(): void {}

        public function getFieldMapping(): array
        {
            return [];
        }

        public function markExported(string $shipmentReference): bool
        {
            return false;
        }
    };
}

function fakeSourceWithExportTracking(Collection $shipments, Collection $items = new Collection): ImportSourceInterface
{
    return new class($shipments, $items) implements ImportSourceInterface
    {
        /** @var array<string> */
        public array $exportedReferences = [];

        public function __construct(
            private Collection $shipments,
            private Collection $items,
        ) {}

        public function getSourceName(): string
        {
            return 'test';
        }

        public function fetchShipments(): Collection
        {
            return $this->shipments;
        }

        public function fetchShipmentItems(string $shipmentReference): Collection
        {
            return $this->items;
        }

        public function validateConfiguration(): void {}

        public function getFieldMapping(): array
        {
            return [];
        }

        public function markExported(string $shipmentReference): bool
        {
            $this->exportedReferences[] = $shipmentReference;

            return true;
        }
    };
}

function fakeSourceWithExportFailure(Collection $shipments, Collection $items = new Collection): ImportSourceInterface
{
    return new class($shipments, $items) implements ImportSourceInterface
    {
        public function __construct(
            private Collection $shipments,
            private Collection $items,
        ) {}

        public function getSourceName(): string
        {
            return 'test';
        }

        public function fetchShipments(): Collection
        {
            return $this->shipments;
        }

        public function fetchShipmentItems(string $shipmentReference): Collection
        {
            return $this->items;
        }

        public function validateConfiguration(): void {}

        public function getFieldMapping(): array
        {
            return [];
        }

        public function markExported(string $shipmentReference): bool
        {
            throw new RuntimeException('External database unavailable');
        }
    };
}

it('imports a shipment with a matching shipping method', function (): void {
    $method = ShippingMethod::factory()->create();
    ShippingMethodAlias::factory()->create([
        'reference' => 'standard',
        'shipping_method_id' => $method->id,
    ]);
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address1' => '123 Main St',
            'city' => 'Seattle',
            'state_or_province' => 'WA',
            'postal_code' => '98101',
            'country' => 'US',
            'shipping_method_id' => 'standard',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->hasErrors())->toBeFalse();

    $shipment = Shipment::where('shipment_reference', 'ORD-001')->first();
    expect($shipment->shipping_method_id)->toBe($method->id)
        ->and($shipment->shipping_method_reference)->toBe('standard')
        ->and($shipment->channel_id)->toBe($channel->id)
        ->and($shipment->channel_reference)->toBe('web');
});

it('imports a shipment when shipping method reference does not match', function (): void {
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-002',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'address1' => '456 Oak Ave',
            'city' => 'Portland',
            'state_or_province' => 'OR',
            'postal_code' => '97201',
            'country' => 'US',
            'shipping_method_id' => 'Express Overnight',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->hasErrors())->toBeFalse();

    $shipment = Shipment::where('shipment_reference', 'ORD-002')->first();
    expect($shipment->shipping_method_id)->toBeNull()
        ->and($shipment->shipping_method_reference)->toBe('Express Overnight');
});

it('stores the raw shipping method reference even when resolved', function (): void {
    $method = ShippingMethod::factory()->create();
    ShippingMethodAlias::factory()->create([
        'reference' => 'ground',
        'shipping_method_id' => $method->id,
    ]);
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'shop', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-003',
            'first_name' => 'Bob',
            'last_name' => 'Smith',
            'address1' => '789 Pine Rd',
            'city' => 'Denver',
            'state_or_province' => 'CO',
            'postal_code' => '80201',
            'country' => 'US',
            'shipping_method_id' => 'ground',
            'channel_id' => 'shop',
        ],
    ]));

    ShipmentImportService::forSource($source)->import();

    $shipment = Shipment::where('shipment_reference', 'ORD-003')->first();
    expect($shipment->shipping_method_reference)->toBe('ground');
});

it('resolves shipping method via alias', function (): void {
    $method = ShippingMethod::factory()->create();
    ShippingMethodAlias::factory()->create([
        'reference' => 'Standard Shipping',
        'shipping_method_id' => $method->id,
    ]);
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-ALIAS-001',
            'first_name' => 'Test',
            'last_name' => 'User',
            'address1' => '100 Alias St',
            'city' => 'Chicago',
            'state_or_province' => 'IL',
            'postal_code' => '60601',
            'country' => 'US',
            'shipping_method_id' => 'Standard Shipping',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->hasErrors())->toBeFalse();

    $shipment = Shipment::where('shipment_reference', 'ORD-ALIAS-001')->first();
    expect($shipment->shipping_method_id)->toBe($method->id)
        ->and($shipment->shipping_method_reference)->toBe('Standard Shipping');
});

it('returns null when neither alias nor direct match exists', function (): void {
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-NONE-001',
            'first_name' => 'Test',
            'last_name' => 'User',
            'address1' => '300 Unknown Blvd',
            'city' => 'Miami',
            'state_or_province' => 'FL',
            'postal_code' => '33101',
            'country' => 'US',
            'shipping_method_id' => 'nonexistent-method',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    $shipment = Shipment::where('shipment_reference', 'ORD-NONE-001')->first();
    expect($shipment->shipping_method_id)->toBeNull()
        ->and($shipment->shipping_method_reference)->toBe('nonexistent-method');
});

it('imports a shipment with no shipping method reference at all', function (): void {
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-004',
            'first_name' => 'Alice',
            'last_name' => 'Wong',
            'address1' => '321 Elm Blvd',
            'city' => 'Austin',
            'state_or_province' => 'TX',
            'postal_code' => '73301',
            'country' => 'US',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1);

    $shipment = Shipment::where('shipment_reference', 'ORD-004')->first();
    expect($shipment->shipping_method_id)->toBeNull()
        ->and($shipment->shipping_method_reference)->toBeNull();
});

it('calls markExported for each successfully imported shipment', function (): void {
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSourceWithExportTracking(collect([
        [
            'shipment_reference' => 'ORD-EXP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address1' => '123 Main St',
            'city' => 'Seattle',
            'state_or_province' => 'WA',
            'postal_code' => '98101',
            'country' => 'US',
            'channel_id' => 'web',
        ],
        [
            'shipment_reference' => 'ORD-EXP-002',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'address1' => '456 Oak Ave',
            'city' => 'Portland',
            'state_or_province' => 'OR',
            'postal_code' => '97201',
            'country' => 'US',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(2)
        ->and($result->shipmentsExported)->toBe(2)
        ->and($source->exportedReferences)->toBe(['ORD-EXP-001', 'ORD-EXP-002']);
});

it('continues importing when markExported fails', function (): void {
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSourceWithExportFailure(collect([
        [
            'shipment_reference' => 'ORD-FAIL-001',
            'first_name' => 'Bob',
            'last_name' => 'Smith',
            'address1' => '789 Pine Rd',
            'city' => 'Denver',
            'state_or_province' => 'CO',
            'postal_code' => '80201',
            'country' => 'US',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->shipmentsExported)->toBe(0)
        ->and($result->hasErrors())->toBeTrue()
        ->and($result->errors[0])->toContain('marking shipment ORD-FAIL-001 as exported');

    // Shipment was still imported despite export failure
    expect(Shipment::where('shipment_reference', 'ORD-FAIL-001')->exists())->toBeTrue();
});

it('imports shipment with phone extension and stores them separately', function (): void {
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-PHONE-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address1' => '123 Main St',
            'city' => 'Seattle',
            'state_or_province' => 'WA',
            'postal_code' => '98101',
            'country' => 'US',
            'phone' => '+1 210-728-4548 ext. 65440',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->hasErrors())->toBeFalse();

    $shipment = Shipment::where('shipment_reference', 'ORD-PHONE-001')->first();
    expect($shipment)->not->toBeNull()
        ->and($shipment->phone)->toBe('2107284548')
        ->and($shipment->phone_extension)->toBe('65440')
        ->and($shipment->validation_message)->toBeNull();
});

it('imports shipment with invalid phone number and stores warning', function (): void {
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-PHONE-002',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address1' => '123 Main St',
            'city' => 'Seattle',
            'state_or_province' => 'WA',
            'postal_code' => '98101',
            'country' => 'US',
            'phone' => 'not-a-phone',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->hasErrors())->toBeFalse();

    $shipment = Shipment::where('shipment_reference', 'ORD-PHONE-002')->first();
    expect($shipment)->not->toBeNull()
        ->and($shipment->phone)->toBeNull()
        ->and($shipment->phone_extension)->toBeNull()
        ->and($shipment->validation_message)->toContain('Invalid phone number removed');
});

it('imports shipment with invalid email and stores warning', function (): void {
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-EMAIL-001',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'address1' => '456 Oak Ave',
            'city' => 'Portland',
            'state_or_province' => 'OR',
            'postal_code' => '97201',
            'country' => 'US',
            'email' => 'not-a-valid-email',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->hasErrors())->toBeFalse();

    $shipment = Shipment::where('shipment_reference', 'ORD-EMAIL-001')->first();
    expect($shipment)->not->toBeNull()
        ->and($shipment->email)->toBeNull()
        ->and($shipment->validation_message)->toContain('Invalid email removed');
});

it('imports shipment with both invalid phone and email', function (): void {
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-BOTH-001',
            'first_name' => 'Bob',
            'last_name' => 'Smith',
            'address1' => '789 Pine Rd',
            'city' => 'Denver',
            'state_or_province' => 'CO',
            'postal_code' => '80201',
            'country' => 'US',
            'phone' => 'not-a-phone',
            'email' => 'bad@@email',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->hasErrors())->toBeFalse();

    $shipment = Shipment::where('shipment_reference', 'ORD-BOTH-001')->first();
    expect($shipment)->not->toBeNull()
        ->and($shipment->phone)->toBeNull()
        ->and($shipment->email)->toBeNull()
        ->and($shipment->validation_message)->toContain('Invalid phone number removed')
        ->and($shipment->validation_message)->toContain('Invalid email removed');
});

it('imports shipment with valid phone and email without warnings', function (): void {
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-VALID-001',
            'first_name' => 'Alice',
            'last_name' => 'Wong',
            'address1' => '321 Elm Blvd',
            'city' => 'Austin',
            'state_or_province' => 'TX',
            'postal_code' => '73301',
            'country' => 'US',
            'phone' => '5125551234',
            'email' => 'alice@example.com',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->hasErrors())->toBeFalse();

    $shipment = Shipment::where('shipment_reference', 'ORD-VALID-001')->first();
    expect($shipment->phone)->toBe('5125551234')
        ->and($shipment->phone_extension)->toBeNull()
        ->and($shipment->email)->toBe('alice@example.com')
        ->and($shipment->validation_message)->toBeNull();
});

it('imports shipment with separate phone_extension field from source', function (): void {
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-EXT-001',
            'first_name' => 'Mike',
            'last_name' => 'Jones',
            'address1' => '500 Tech Pkwy',
            'city' => 'San Jose',
            'state_or_province' => 'CA',
            'postal_code' => '95112',
            'country' => 'US',
            'phone' => '4085551234',
            'phone_extension' => '999',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->hasErrors())->toBeFalse();

    $shipment = Shipment::where('shipment_reference', 'ORD-EXT-001')->first();
    expect($shipment->phone)->toBe('4085551234')
        ->and($shipment->phone_extension)->toBe('999');
});

it('uses separate phone_extension field over parsed extension', function (): void {
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-EXT-002',
            'first_name' => 'Sara',
            'last_name' => 'Lee',
            'address1' => '600 Market St',
            'city' => 'San Francisco',
            'state_or_province' => 'CA',
            'postal_code' => '94105',
            'country' => 'US',
            'phone' => '+1 415-555-1234 ext. 999',
            'phone_extension' => '777',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    $shipment = Shipment::where('shipment_reference', 'ORD-EXT-002')->first();
    // Separate field takes precedence over parsed extension
    expect($shipment->phone)->toBe('4155551234')
        ->and($shipment->phone_extension)->toBe('777');
});

it('resolves channel via alias', function (): void {
    $channel = Channel::factory()->create();
    ChannelAlias::factory()->create([
        'reference' => 'AMZ',
        'channel_id' => $channel->id,
    ]);

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-CH-001',
            'first_name' => 'Test',
            'last_name' => 'User',
            'address1' => '100 Channel St',
            'city' => 'Chicago',
            'state_or_province' => 'IL',
            'postal_code' => '60601',
            'country' => 'US',
            'channel_id' => 'AMZ',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->hasErrors())->toBeFalse();

    $shipment = Shipment::where('shipment_reference', 'ORD-CH-001')->first();
    expect($shipment->channel_id)->toBe($channel->id)
        ->and($shipment->channel_reference)->toBe('AMZ');
});

it('imports shipment with unmapped channel reference', function (): void {
    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-CH-002',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'address1' => '456 Oak Ave',
            'city' => 'Portland',
            'state_or_province' => 'OR',
            'postal_code' => '97201',
            'country' => 'US',
            'channel_id' => 'UNKNOWN_CHANNEL',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->hasErrors())->toBeFalse();

    $shipment = Shipment::where('shipment_reference', 'ORD-CH-002')->first();
    expect($shipment->channel_id)->toBeNull()
        ->and($shipment->channel_reference)->toBe('UNKNOWN_CHANNEL');
});

it('deduplicates unmapped channel shipments on re-import', function (): void {
    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-CH-003',
            'first_name' => 'Bob',
            'last_name' => 'Smith',
            'address1' => '789 Pine Rd',
            'city' => 'Denver',
            'state_or_province' => 'CO',
            'postal_code' => '80201',
            'country' => 'US',
            'channel_id' => 'NEW_CHANNEL',
        ],
    ]));

    // First import
    $result1 = ShipmentImportService::forSource($source)->import();
    expect($result1->shipmentsCreated)->toBe(1);

    // Second import (re-import same data)
    $result2 = ShipmentImportService::forSource($source)->import();
    expect($result2->shipmentsUpdated)->toBe(1)
        ->and($result2->shipmentsCreated)->toBe(0);

    // Should still only have one shipment
    expect(Shipment::where('shipment_reference', 'ORD-CH-003')->count())->toBe(1);
});

it('stores channel_reference even when channel is resolved', function (): void {
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'shop', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-CH-004',
            'first_name' => 'Alice',
            'last_name' => 'Wong',
            'address1' => '321 Elm Blvd',
            'city' => 'Austin',
            'state_or_province' => 'TX',
            'postal_code' => '73301',
            'country' => 'US',
            'channel_id' => 'shop',
        ],
    ]));

    ShipmentImportService::forSource($source)->import();

    $shipment = Shipment::where('shipment_reference', 'ORD-CH-004')->first();
    expect($shipment->channel_id)->toBe($channel->id)
        ->and($shipment->channel_reference)->toBe('shop');
});

it('imports shipment with no channel reference at all', function (): void {
    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-CH-005',
            'first_name' => 'Mike',
            'last_name' => 'Jones',
            'address1' => '500 Tech Pkwy',
            'city' => 'San Jose',
            'state_or_province' => 'CA',
            'postal_code' => '95112',
            'country' => 'US',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1);

    $shipment = Shipment::where('shipment_reference', 'ORD-CH-005')->first();
    expect($shipment->channel_id)->toBeNull()
        ->and($shipment->channel_reference)->toBeNull();
});
