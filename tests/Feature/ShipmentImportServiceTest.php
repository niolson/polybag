<?php

use App\Contracts\ImportSourceInterface;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\ImportSource;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Services\ShipmentImport\ShipmentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

function fakeSource(Collection $shipments, Collection $items = new Collection, string $sourceName = 'test'): ImportSourceInterface
{
    return new class($shipments, $items, $sourceName) implements ImportSourceInterface
    {
        public function __construct(
            private Collection $shipments,
            private Collection $items,
            private string $sourceName,
        ) {}

        public function getSourceName(): string
        {
            return $this->sourceName;
        }

        public function fetchShipments(): Collection
        {
            return $this->shipments;
        }

        public function fetchShipmentItems(string $sourceRecordId): Collection
        {
            return $this->items;
        }

        public function validateConfiguration(): void {}

        public function getFieldMapping(): array
        {
            return [];
        }

        public function markExported(string $sourceRecordId): bool
        {
            return false;
        }
    };
}

function fakeSourceWithExportTracking(Collection $shipments, Collection $items = new Collection, string $sourceName = 'test'): ImportSourceInterface
{
    return new class($shipments, $items, $sourceName) implements ImportSourceInterface
    {
        /** @var array<string> */
        public array $exportedReferences = [];

        public function __construct(
            private Collection $shipments,
            private Collection $items,
            private string $sourceName,
        ) {}

        public function getSourceName(): string
        {
            return $this->sourceName;
        }

        public function fetchShipments(): Collection
        {
            return $this->shipments;
        }

        public function fetchShipmentItems(string $sourceRecordId): Collection
        {
            return $this->items;
        }

        public function validateConfiguration(): void {}

        public function getFieldMapping(): array
        {
            return [];
        }

        public function markExported(string $sourceRecordId): bool
        {
            $this->exportedReferences[] = $sourceRecordId;

            return true;
        }
    };
}

function fakeSourceWithExportFailure(Collection $shipments, Collection $items = new Collection, string $sourceName = 'test'): ImportSourceInterface
{
    return new class($shipments, $items, $sourceName) implements ImportSourceInterface
    {
        public function __construct(
            private Collection $shipments,
            private Collection $items,
            private string $sourceName,
        ) {}

        public function getSourceName(): string
        {
            return $this->sourceName;
        }

        public function fetchShipments(): Collection
        {
            return $this->shipments;
        }

        public function fetchShipmentItems(string $sourceRecordId): Collection
        {
            return $this->items;
        }

        public function validateConfiguration(): void {}

        public function getFieldMapping(): array
        {
            return [];
        }

        public function markExported(string $sourceRecordId): bool
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
        ->and($shipment->channel_reference)->toBe('web')
        ->and($shipment->source_record_id)->toBe('ORD-001')
        ->and($shipment->importSource)->not->toBeNull()
        ->and($shipment->importSource->config_key)->toBe('test');
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

it('normalizes imported country and subdivision values before saving', function (): void {
    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-NORM-001',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'address1' => '123 Main St',
            'city' => 'Los Angeles',
            'state_or_province' => 'California',
            'postal_code' => '90001',
            'country' => 'United States',
            'channel_id' => 'web',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->hasErrors())->toBeFalse();

    $shipment = Shipment::where('shipment_reference', 'ORD-NORM-001')->first();

    expect($shipment)->not->toBeNull()
        ->and($shipment->country)->toBe('US')
        ->and($shipment->state_or_province)->toBe('CA')
        ->and($shipment->channel_id)->toBe($channel->id);
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
        ->and($shipment->phone)->toBe('+1 210-728-4548 ext. 65440')
        ->and($shipment->phone_e164)->toBe('+12107284548')
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
        ->and($shipment->phone)->toBe('not-a-phone')
        ->and($shipment->phone_e164)->toBeNull()
        ->and($shipment->phone_extension)->toBeNull()
        ->and($shipment->validation_message)->toContain('Invalid phone number could not be normalized');
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
        ->and($shipment->phone)->toBe('not-a-phone')
        ->and($shipment->phone_e164)->toBeNull()
        ->and($shipment->email)->toBeNull()
        ->and($shipment->validation_message)->toContain('Invalid phone number could not be normalized')
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
        ->and($shipment->phone_e164)->toBe('+15125551234')
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
        ->and($shipment->phone_e164)->toBe('+14085551234')
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
    expect($shipment->phone)->toBe('+1 415-555-1234 ext. 999')
        ->and($shipment->phone_e164)->toBe('+14155551234')
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

it('does not duplicate a shipment when channel is manually assigned between imports', function (): void {
    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-CH-006',
            'first_name' => 'Riley',
            'last_name' => 'Parker',
            'address1' => '400 Import Way',
            'city' => 'Phoenix',
            'state_or_province' => 'AZ',
            'postal_code' => '85001',
            'country' => 'US',
            'channel_id' => 'UNMAPPED_CHANNEL',
        ],
    ]));

    ShipmentImportService::forSource($source)->import();

    $channel = Channel::factory()->create(['name' => 'Manual Channel']);

    $shipment = Shipment::where('shipment_reference', 'ORD-CH-006')->first();
    $shipment->update(['channel_id' => $channel->id]);

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsUpdated)->toBe(1)
        ->and($result->shipmentsCreated)->toBe(0)
        ->and(Shipment::where('shipment_reference', 'ORD-CH-006')->count())->toBe(1);

    $shipment = Shipment::where('shipment_reference', 'ORD-CH-006')->first();
    expect($shipment->channel_id)->toBeNull()
        ->and($shipment->channel_reference)->toBe('UNMAPPED_CHANNEL')
        ->and($shipment->source_record_id)->toBe('ORD-CH-006');
});

it('allows the same displayed shipment reference to exist twice for one source when source_record_id differs', function (): void {
    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-DUP-DISPLAY',
            'source_record_id' => 'SRC-001',
            'first_name' => 'Alex',
            'last_name' => 'One',
            'address1' => '1 Main St',
            'city' => 'Dallas',
            'state_or_province' => 'TX',
            'postal_code' => '75001',
            'country' => 'US',
        ],
        [
            'shipment_reference' => 'ORD-DUP-DISPLAY',
            'source_record_id' => 'SRC-002',
            'first_name' => 'Alex',
            'last_name' => 'Two',
            'address1' => '2 Main St',
            'city' => 'Dallas',
            'state_or_province' => 'TX',
            'postal_code' => '75002',
            'country' => 'US',
        ],
    ]));

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(2)
        ->and(Shipment::where('shipment_reference', 'ORD-DUP-DISPLAY')->count())->toBe(2);

    expect(Shipment::where('source_record_id', 'SRC-001')->exists())->toBeTrue()
        ->and(Shipment::where('source_record_id', 'SRC-002')->exists())->toBeTrue();
});

it('allows the same displayed shipment reference from two different import sources', function (): void {
    $shipments = collect([
        [
            'shipment_reference' => 'ORD-CROSS-001',
            'first_name' => 'Jamie',
            'last_name' => 'Source',
            'address1' => '100 Shared Ref Rd',
            'city' => 'Miami',
            'state_or_province' => 'FL',
            'postal_code' => '33101',
            'country' => 'US',
        ],
    ]);

    $resultA = ShipmentImportService::forSource(fakeSource($shipments, sourceName: 'source_a'))->import();
    $resultB = ShipmentImportService::forSource(fakeSource($shipments, sourceName: 'source_b'))->import();

    expect($resultA->shipmentsCreated)->toBe(1)
        ->and($resultB->shipmentsCreated)->toBe(1)
        ->and(Shipment::where('shipment_reference', 'ORD-CROSS-001')->count())->toBe(2);

    $sourceA = ImportSource::where('config_key', 'source_a')->first();
    $sourceB = ImportSource::where('config_key', 'source_b')->first();

    expect($sourceA)->not->toBeNull()
        ->and($sourceB)->not->toBeNull()
        ->and(Shipment::where('shipment_reference', 'ORD-CROSS-001')->where('import_source_id', $sourceA->id)->exists())->toBeTrue()
        ->and(Shipment::where('shipment_reference', 'ORD-CROSS-001')->where('import_source_id', $sourceB->id)->exists())->toBeTrue();
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
