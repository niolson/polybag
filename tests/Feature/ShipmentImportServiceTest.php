<?php

use App\Contracts\DataSourceInterface;
use App\DataTransferObjects\Shipping\RateRequest;
use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Models\User;
use App\Notifications\ImportCompleted;
use App\Services\ShipmentImport\ShipmentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->dataSource = DataSource::factory()->create();
});

function fakeSource(Collection $shipments, Collection $items = new Collection): DataSourceInterface
{
    return new class($shipments, $items) implements DataSourceInterface
    {
        public function __construct(
            private Collection $shipments,
            private Collection $items,
        ) {}

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

function fakeSourceWithExportTracking(Collection $shipments, Collection $items = new Collection): DataSourceInterface
{
    return new class($shipments, $items) implements DataSourceInterface
    {
        /** @var array<string> */
        public array $exportedReferences = [];

        public function __construct(
            private Collection $shipments,
            private Collection $items,
        ) {}

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

function fakeSourceWithExportFailure(Collection $shipments, Collection $items = new Collection): DataSourceInterface
{
    return new class($shipments, $items) implements DataSourceInterface
    {
        public function __construct(
            private Collection $shipments,
            private Collection $items,
        ) {}

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->hasErrors())->toBeFalse();

    $shipment = Shipment::where('shipment_reference', 'ORD-001')->first();
    expect($shipment->shipping_method_id)->toBe($method->id)
        ->and($shipment->shipping_method_reference)->toBe('standard')
        ->and($shipment->channel_id)->toBe($channel->id)
        ->and($shipment->channel_reference)->toBe('web')
        ->and($shipment->source_record_id)->toBe('ORD-001')
        ->and($shipment->dataSource)->not->toBeNull()
        ->and($shipment->dataSource->id)->toBe($this->dataSource->id);
});

it('persists explicit residential classification from the shared import contract', function (bool $residential): void {
    $source = fakeSource(collect([[
        'shipment_reference' => 'ORD-CLASSIFICATION-001',
        'address1' => '123 Main St',
        'city' => 'Seattle',
        'state_or_province' => 'WA',
        'postal_code' => '98101',
        'country' => 'US',
        'residential' => $residential,
    ]]));

    ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect(Shipment::where('shipment_reference', 'ORD-CLASSIFICATION-001')->firstOrFail()->residential)
        ->toBe($residential);
})->with([
    'residential' => true,
    'commercial' => false,
]);

it('preserves known residential classification when a reimport omits it', function (): void {
    ShipmentImportService::forSource(
        fakeSource(collect([onExistingRow(['residential' => false])])),
        $this->dataSource,
    )->import();

    ShipmentImportService::forSource(
        fakeSource(collect([onExistingRow(['address1' => '99 Changed Ave'])])),
        $this->dataSource,
    )->import();

    $shipment = Shipment::where('shipment_reference', 'ORD-EXIST-001')->firstOrFail();

    expect($shipment->address1)->toBe('99 Changed Ave')
        ->and($shipment->residential)->toBeFalse();
});

it('updates imported classification without overriding validated classification precedence', function (): void {
    ShipmentImportService::forSource(
        fakeSource(collect([onExistingRow(['residential' => false])])),
        $this->dataSource,
    )->import();

    $shipment = Shipment::where('shipment_reference', 'ORD-EXIST-001')->firstOrFail();
    $shipment->update(['validated_residential' => false]);

    ShipmentImportService::forSource(
        fakeSource(collect([onExistingRow(['residential' => true])])),
        $this->dataSource,
    )->import();

    $shipment->refresh();
    $package = Package::factory()->for($shipment)->create();

    expect($shipment->residential)->toBeTrue()
        ->and($shipment->validated_residential)->toBeFalse()
        ->and(RateRequest::fromPackage($package)->residential)->toBeFalse();
});

it('does not treat a mapped source status as an internal shipment status', function (): void {
    $source = fakeSource(collect([[
        'shipment_reference' => 'ORD-STATUS-001',
        'address1' => '123 Main St',
        'city' => 'Seattle',
        'state_or_province' => 'WA',
        'postal_code' => '98101',
        'country' => 'US',
        'status' => 'shipped',
    ]]));

    ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect(Shipment::where('shipment_reference', 'ORD-STATUS-001')->firstOrFail()->status)
        ->toBe(ShipmentStatus::Open);
});

it('resolves shipping references and products inside the active client context', function (): void {
    $clientA = Client::factory()->default()->create();
    $clientB = Client::factory()->create([
        'name' => 'Second Client',
        'is_default' => false,
    ]);

    $methodA = ShippingMethod::factory()->create(['name' => 'Client A Standard']);
    $methodB = ShippingMethod::factory()->create(['name' => 'Client B Standard']);
    $channelA = Channel::factory()->create(['name' => 'Client A Web']);
    $channelB = Channel::factory()->create(['name' => 'Client B Web']);

    ShippingMethodAlias::factory()->create([
        'client_id' => $clientA->id,
        'reference' => 'standard',
        'shipping_method_id' => $methodA->id,
    ]);
    ShippingMethodAlias::factory()->create([
        'client_id' => $clientB->id,
        'reference' => 'standard',
        'shipping_method_id' => $methodB->id,
    ]);
    ChannelAlias::factory()->create([
        'client_id' => $clientA->id,
        'reference' => 'web',
        'channel_id' => $channelA->id,
    ]);
    ChannelAlias::factory()->create([
        'client_id' => $clientB->id,
        'reference' => 'web',
        'channel_id' => $channelB->id,
    ]);

    Product::factory()->create([
        'client_id' => $clientA->id,
        'sku' => 'SHARED-SKU',
        'name' => 'Client A Existing Product',
    ]);

    $importSourceB = DataSource::factory()->create(['client_id' => $clientB->id]);

    $source = fakeSource(
        collect([
            [
                'shipment_reference' => 'ORD-CLIENT-B',
                'first_name' => 'Jane',
                'last_name' => 'Client',
                'address1' => '123 Main St',
                'city' => 'Seattle',
                'state_or_province' => 'WA',
                'postal_code' => '98101',
                'country' => 'US',
                'shipping_method_id' => 'standard',
                'channel_id' => 'web',
            ],
        ]),
        collect([
            [
                'sku' => 'SHARED-SKU',
                'name' => 'Client B Product',
                'quantity' => 1,
            ],
        ]),
    );

    $result = ShipmentImportService::forSource($source, $importSourceB)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->productsCreated)->toBe(1)
        ->and($result->hasErrors())->toBeFalse();

    $shipment = Shipment::where('shipment_reference', 'ORD-CLIENT-B')->firstOrFail();
    $clientBProduct = Product::where('client_id', $clientB->id)->where('sku', 'SHARED-SKU')->first();

    expect($shipment->client_id)->toBe($clientB->id)
        ->and($shipment->dataSource->client_id)->toBe($clientB->id)
        ->and($shipment->shipping_method_id)->toBe($methodB->id)
        ->and($shipment->channel_id)->toBe($channelB->id)
        ->and($clientBProduct)->not->toBeNull()
        ->and(Product::where('sku', 'SHARED-SKU')->count())->toBe(2);
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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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
    $result1 = ShipmentImportService::forSource($source, $this->dataSource)->import();
    expect($result1->shipmentsCreated)->toBe(1);

    // Second import (re-import same data): unchanged source data is skipped
    $result2 = ShipmentImportService::forSource($source, $this->dataSource)->import();
    expect($result2->shipmentsSkipped)->toBe(1)
        ->and($result2->shipmentsUpdated)->toBe(0)
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

    ShipmentImportService::forSource($source, $this->dataSource)->import();

    $channel = Channel::factory()->create(['name' => 'Manual Channel']);

    $shipment = Shipment::where('shipment_reference', 'ORD-CH-006')->first();
    $shipment->update(['channel_id' => $channel->id]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsSkipped)->toBe(1)
        ->and($result->shipmentsCreated)->toBe(0)
        ->and(Shipment::where('shipment_reference', 'ORD-CH-006')->count())->toBe(1);

    // Source data is unchanged, so the manual channel assignment survives
    $shipment = Shipment::where('shipment_reference', 'ORD-CH-006')->first();
    expect($shipment->channel_id)->toBe($channel->id)
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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $recordA = DataSource::factory()->create(['name' => 'Source A']);
    $recordB = DataSource::factory()->create(['name' => 'Source B']);

    $resultA = ShipmentImportService::forSource(fakeSource($shipments), $recordA)->import();
    $resultB = ShipmentImportService::forSource(fakeSource($shipments), $recordB)->import();

    expect($resultA->shipmentsCreated)->toBe(1)
        ->and($resultB->shipmentsCreated)->toBe(1)
        ->and(Shipment::where('shipment_reference', 'ORD-CROSS-001')->count())->toBe(2);

    expect(Shipment::where('shipment_reference', 'ORD-CROSS-001')->where('data_source_id', $recordA->id)->exists())->toBeTrue()
        ->and(Shipment::where('shipment_reference', 'ORD-CROSS-001')->where('data_source_id', $recordB->id)->exists())->toBeTrue();
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

    ShipmentImportService::forSource($source, $this->dataSource)->import();

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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(1);

    $shipment = Shipment::where('shipment_reference', 'ORD-CH-005')->first();
    expect($shipment->channel_id)->toBeNull()
        ->and($shipment->channel_reference)->toBeNull();
});

it('can import shipments without fetching shipment items when item import is disabled for the source', function (): void {
    $record = DataSource::factory()->create(['settings' => ['shipment_items_enabled' => false]]);

    $source = new class(collect([['shipment_reference' => 'ORD-NO-ITEMS-001', 'first_name' => 'No', 'last_name' => 'Items', 'address1' => '100 Header Only Way', 'city' => 'Seattle', 'state_or_province' => 'WA', 'postal_code' => '98101', 'country' => 'US']])) implements DataSourceInterface
    {
        public function __construct(
            private Collection $shipments,
        ) {}

        public function fetchShipments(): Collection
        {
            return $this->shipments;
        }

        public function fetchShipmentItems(string $sourceRecordId): Collection
        {
            throw new RuntimeException('Shipment item lookup should not run.');
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

    $result = ShipmentImportService::forSource($source, $record)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->itemsCreated)->toBe(0)
        ->and($result->hasErrors())->toBeFalse()
        ->and(Shipment::where('shipment_reference', 'ORD-NO-ITEMS-001')->exists())->toBeTrue();
});

it('returns an error result and notifies admins when fetchShipments throws', function (): void {
    Notification::fake();
    Log::shouldReceive('channel')
        ->with(config('shipment-import.logging.channel', 'stack'))
        ->once()
        ->andReturnSelf();
    Log::shouldReceive('log')
        ->once()
        ->with('error', 'Import configuration failed', Mockery::type('array'));

    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);

    $source = new class implements DataSourceInterface
    {
        public function fetchShipments(): Collection
        {
            throw new RuntimeException('You have an error in your SQL syntax near \'FROM\'');
        }

        public function fetchShipmentItems(string $sourceRecordId): Collection
        {
            return collect();
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

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0])->toContain('SQL syntax');

    Notification::assertSentTo($admin, ImportCompleted::class, function (ImportCompleted $notification): bool {
        return count($notification->errors) > 0;
    });
});

it('logs row validation errors instead of only recording them silently', function (): void {
    Log::shouldReceive('channel')
        ->with(config('shipment-import.logging.channel', 'stack'))
        ->andReturnSelf();
    Log::shouldReceive('log')
        ->withArgs(fn (string $level, string $message): bool => $level === 'warning' && str_contains($message, 'Missing city'))
        ->once();
    Log::shouldReceive('log')
        ->withArgs(fn (string $level): bool => $level === 'info');

    $source = fakeSource(collect([
        [
            'shipment_reference' => 'ORD-INVALID-001',
            'first_name' => 'Missing',
            'last_name' => 'City',
            'address1' => '123 Main St',
            'city' => '',
            'state_or_province' => 'WA',
            'postal_code' => '98101',
            'country' => 'US',
        ],
    ]));

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0])->toContain('Missing city')
        ->and(Shipment::where('shipment_reference', 'ORD-INVALID-001')->exists())->toBeFalse();
});

// ── Existing shipment behavior (on_existing) ──────────────────────────────────

function onExistingRow(array $overrides = []): array
{
    return array_merge([
        'shipment_reference' => 'ORD-EXIST-001',
        'first_name' => 'Casey',
        'last_name' => 'Jordan',
        'address1' => '12 Original St',
        'city' => 'Austin',
        'state_or_province' => 'TX',
        'postal_code' => '78701',
        'country' => 'US',
    ], $overrides);
}

it('updates an existing shipment when the source data changed', function (): void {
    ShipmentImportService::forSource(fakeSource(collect([onExistingRow()])), $this->dataSource)->import();

    $result = ShipmentImportService::forSource(
        fakeSource(collect([onExistingRow(['address1' => '99 Changed Ave'])])),
        $this->dataSource,
    )->import();

    expect($result->shipmentsUpdated)->toBe(1)
        ->and($result->shipmentsSkipped)->toBe(0);

    expect(Shipment::where('shipment_reference', 'ORD-EXIST-001')->value('address1'))->toBe('99 Changed Ave');
});

it('never updates existing shipments in skip mode', function (): void {
    $this->dataSource->update(['settings' => ['on_existing' => 'skip']]);

    ShipmentImportService::forSource(fakeSource(collect([onExistingRow()])), $this->dataSource)->import();

    $result = ShipmentImportService::forSource(
        fakeSource(collect([onExistingRow(['address1' => '99 Changed Ave'])])),
        $this->dataSource,
    )->import();

    expect($result->shipmentsSkipped)->toBe(1)
        ->and($result->shipmentsUpdated)->toBe(0);

    expect(Shipment::where('shipment_reference', 'ORD-EXIST-001')->value('address1'))->toBe('12 Original St');
});

it('rewrites local edits in update mode even when the source is unchanged', function (): void {
    $this->dataSource->update(['settings' => ['on_existing' => 'update']]);

    ShipmentImportService::forSource(fakeSource(collect([onExistingRow()])), $this->dataSource)->import();

    Shipment::where('shipment_reference', 'ORD-EXIST-001')->first()
        ->update(['first_name' => 'Locally Edited']);

    $result = ShipmentImportService::forSource(fakeSource(collect([onExistingRow()])), $this->dataSource)->import();

    expect($result->shipmentsUpdated)->toBe(1)
        ->and($result->shipmentsSkipped)->toBe(0);

    expect(Shipment::where('shipment_reference', 'ORD-EXIST-001')->value('first_name'))->toBe('Casey');
});

it('never updates shipped shipments regardless of mode', function (): void {
    $this->dataSource->update(['settings' => ['on_existing' => 'update']]);

    ShipmentImportService::forSource(fakeSource(collect([onExistingRow()])), $this->dataSource)->import();

    Shipment::where('shipment_reference', 'ORD-EXIST-001')->first()
        ->update(['status' => ShipmentStatus::Shipped]);

    $result = ShipmentImportService::forSource(
        fakeSource(collect([onExistingRow(['address1' => '99 Changed Ave'])])),
        $this->dataSource,
    )->import();

    expect($result->shipmentsSkipped)->toBe(1)
        ->and($result->shipmentsUpdated)->toBe(0);

    expect(Shipment::where('shipment_reference', 'ORD-EXIST-001')->value('address1'))->toBe('12 Original St');
});

it('does not mark an open shipment shipped when packing has begun', function (): void {
    ShipmentImportService::forSource(fakeSource(collect([onExistingRow()])), $this->dataSource)->import();

    $shipment = Shipment::where('shipment_reference', 'ORD-EXIST-001')->firstOrFail();
    Package::factory()->for($shipment)->create();

    $result = ShipmentImportService::forSource(
        fakeSource(collect([onExistingRow([
            '_import_status' => ShipmentStatus::Shipped->value,
        ])])),
        $this->dataSource,
    )->import();

    expect($result->shipmentsSkipped)->toBe(1)
        ->and($result->shipmentsUpdated)->toBe(0)
        ->and($shipment->fresh()->status)->toBe(ShipmentStatus::Open);
});

it('treats item changes as source changes for update-if-changed', function (): void {
    $items = collect([['sku' => 'EXIST-SKU', 'name' => 'Widget', 'quantity' => 1]]);

    ShipmentImportService::forSource(fakeSource(collect([onExistingRow()]), $items), $this->dataSource)->import();

    $changedItems = collect([['sku' => 'EXIST-SKU', 'name' => 'Widget', 'quantity' => 3]]);

    $result = ShipmentImportService::forSource(
        fakeSource(collect([onExistingRow()]), $changedItems),
        $this->dataSource,
    )->import();

    expect($result->shipmentsUpdated)->toBe(1)
        ->and($result->shipmentsSkipped)->toBe(0);

    $shipment = Shipment::where('shipment_reference', 'ORD-EXIST-001')->first();
    expect($shipment->shipmentItems()->first()->quantity)->toBe(3);
});

it('does not re-import items for skipped shipments', function (): void {
    $items = collect([['sku' => 'EXIST-SKU', 'name' => 'Widget', 'quantity' => 1]]);

    ShipmentImportService::forSource(fakeSource(collect([onExistingRow()]), $items), $this->dataSource)->import();

    $shipment = Shipment::where('shipment_reference', 'ORD-EXIST-001')->first();
    $shipment->shipmentItems()->first()->update(['quantity' => 5]);

    $result = ShipmentImportService::forSource(fakeSource(collect([onExistingRow()]), $items), $this->dataSource)->import();

    expect($result->shipmentsSkipped)->toBe(1)
        ->and($result->itemsUpdated)->toBe(0);

    expect($shipment->shipmentItems()->first()->quantity)->toBe(5);
});
