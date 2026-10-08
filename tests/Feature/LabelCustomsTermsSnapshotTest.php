<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\Contracts\SendsCustomsTerms;
use App\DataTransferObjects\Customs\RecipientTaxId;
use App\DataTransferObjects\Customs\ResolvedCustomsTerms;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\CustomsTermsOrigin;
use App\Enums\DutiesTerms;
use App\Enums\PackageStatus;
use App\Enums\RecipientTaxIdType;
use App\Enums\ShippingRuleAction;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Client;
use App\Models\ClientTaxRegistration;
use App\Models\ExchangeRate;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Customs\CustomsTermsSnapshot;
use App\Services\Customs\DutiesSupportTable;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

/**
 * `international-customs-terms/06`: the shipping workflow records what a Label
 * declared, for every adapter that sends customs terms, not in each adapter.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    $this->actingAs(User::factory()->admin()->create());

    foreach ([2, 4] as $daysAgo) {
        ExchangeRate::factory()->quoting('USD', 1.25, now()->subDays($daysAgo)->toDateString())->create();
    }
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * A packed box of one line, valued well under any threshold, bound for the
 * given shipment attributes.
 *
 * @param  array<string, mixed>  $shipmentAttributes
 */
function snapshotPackage(Client $client, array $shipmentAttributes): Package
{
    $carrier = Carrier::query()->where('name', 'MockCarrier')->first()
        ?? Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $service = CarrierService::query()->where('service_code', 'TEST')->first()
        ?? CarrierService::factory()->create([
            'carrier_id' => $carrier->id,
            'name' => 'Test Service',
            'service_code' => 'TEST',
            'active' => true,
        ]);
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($service->id);
    ShippingRule::factory()->create([
        'shipping_method_id' => $method->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
    ]);

    $shipment = Shipment::factory()->create($shipmentAttributes + [
        'client_id' => $client->id,
        'company' => null,
        'city' => 'Berlin',
        'state_or_province' => null,
        'postal_code' => '10117',
        'country' => 'DE',
        'shipping_method_id' => $method->id,
    ]);

    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0,
        'status' => PackageStatus::Unshipped,
    ]);

    $product = Product::factory()->create(['weight' => 0.2, 'client_id' => $client->id]);
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'value' => 20.0,
        'transparency' => false,
    ]);
    $package->packageItems()->create([
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    return $package;
}

/**
 * A direct carrier that sells, handing every request it was asked to ship to
 * $sent. It implements {@see SendsCustomsTerms} unless a test says it does not.
 *
 * @param  array<int, ShipRequest>  $sent
 */
function snapshotCarrier(array &$sent, bool $sendsTerms = true): MockInterface
{
    $adapter = $sendsTerms
        ? Mockery::mock(DirectCarrierAdapter::class, SendsCustomsTerms::class)
        : Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('customsDocumentDelivery')->andReturn(CustomsDocumentDelivery::FusedIntoLabel);
    $adapter->shouldReceive('getCarrierName')->andReturn('MockCarrier');
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('createShipment')->once()->andReturnUsing(function (ShipRequest $request) use (&$sent): ShipResponse {
        $sent[] = $request;

        return ShipResponse::success(
            trackingNumber: 'TRACK'.random_int(1000, 9999),
            cost: 7.50,
            carrier: 'MockCarrier',
            service: 'Test Service',
            labelData: base64_encode('label'),
        );
    });

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    return $adapter;
}

function snapshotShip(Package $package): void
{
    $rate = quotedDirectly($package, new RateResponse('MockCarrier', 'TEST', 'Test Service', 7.50, carrierServiceId: CarrierService::query()->where('service_code', 'TEST')->value('id')));

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: $rate, userId: auth()->id()),
    );

    expect($result->success)->toBeTrue();
}

it('records an order-sourced term and registration, the ITN and the recipient tax ID type', function (): void {
    $client = Client::factory()->create(['exporter_ein' => '123456789']);
    $package = snapshotPackage($client, [
        'duties_terms' => 'ddp',
        'seller_tax_regime' => 'ioss',
        'seller_tax_number' => 'IM2760000742',
        'export_itn' => 'X20261008123456',
        'recipient_tax_id_type' => RecipientTaxIdType::Vat,
        'recipient_tax_id' => 'DE123456789',
    ]);
    $sent = [];
    snapshotCarrier($sent);

    snapshotShip($package);

    $label = $package->fresh()->activeLabel()->firstOrFail();

    expect($label->customs_terms)->toBe([
        'duties_terms' => 'ddp',
        'duties_terms_source' => 'order',
        'registration' => ['regime' => 'ioss', 'number' => 'IM2760000742', 'source' => 'order'],
        'recipient_tax_id' => ['type' => 'vat'],
        'export_itn' => 'X20261008123456',
        'duties_support_version' => app(DutiesSupportTable::class)->version(),
    ])
        ->and($label->duties_cost)->toBeNull()
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]->customsTerms->dutiesTerms->value)->toBe('ddp')
        ->and($sent[0]->customsTerms->registration->number)->toBe('IM2760000742')
        ->and($sent[0]->exportItn)->toBe('X20261008123456')
        ->and($sent[0]->exporterEin)->toBe('123456789')
        ->and($sent[0]->recipientTaxId->number)->toBe('DE123456789');
});

it('records a client-sourced term and registration', function (): void {
    $client = Client::factory()->ddpToEu()->create();
    ClientTaxRegistration::factory()->ioss()->for($client)->create();
    $package = snapshotPackage($client, []);
    $sent = [];
    snapshotCarrier($sent);

    snapshotShip($package);

    $snapshot = $package->fresh()->activeLabel()->firstOrFail()->customs_terms;

    expect($snapshot['duties_terms'])->toBe('ddp')
        ->and($snapshot['duties_terms_source'])->toBe('client')
        ->and($snapshot['registration'])->toBe(['regime' => 'ioss', 'number' => 'IM0000000001', 'source' => 'client'])
        ->and($snapshot['recipient_tax_id'])->toBeNull()
        ->and($snapshot['export_itn'])->toBeNull();
});

it('never holds the recipient tax ID itself', function (): void {
    $client = Client::factory()->create();
    $package = snapshotPackage($client, [
        'country' => 'BR',
        'city' => 'Sao Paulo',
        'state_or_province' => 'SP',
        'postal_code' => '01310-100',
        'recipient_tax_id_type' => RecipientTaxIdType::Cpf,
        'recipient_tax_id' => '12345678909',
    ]);
    $sent = [];
    snapshotCarrier($sent);

    snapshotShip($package);

    $stored = (string) DB::table('package_labels')->where('package_id', $package->id)->value('customs_terms');

    expect(json_decode($stored, true)['recipient_tax_id'])->toBe(['type' => 'cpf'])
        ->and($stored)->not->toContain('12345678909');
});

it('records DDU outside the EU as the default, with no registration', function (): void {
    $client = Client::factory()->create();
    $package = snapshotPackage($client, ['country' => 'JP', 'city' => 'Tokyo', 'state_or_province' => null, 'postal_code' => '100-0001']);
    $sent = [];
    snapshotCarrier($sent);

    snapshotShip($package);

    $snapshot = $package->fresh()->activeLabel()->firstOrFail()->customs_terms;

    expect($snapshot['duties_terms'])->toBe('ddu')
        ->and($snapshot['duties_terms_source'])->toBe('default')
        ->and($snapshot['registration'])->toBeNull();
});

it('records nothing for a domestic label', function (): void {
    $client = Client::factory()->create();
    $package = snapshotPackage($client, ['country' => 'US', 'city' => 'Seattle', 'state_or_province' => 'WA', 'postal_code' => '98101']);
    $sent = [];
    snapshotCarrier($sent);

    snapshotShip($package);

    expect($package->fresh()->activeLabel()->firstOrFail()->customs_terms)->toBeNull();
});

it('records nothing for an adapter that does not send customs terms, rather than terms the carrier never saw', function (): void {
    $client = Client::factory()->ddpToEu()->create();
    $package = snapshotPackage($client, []);
    $sent = [];
    snapshotCarrier($sent, sendsTerms: false);

    snapshotShip($package);

    expect($package->fresh()->activeLabel()->firstOrFail()->customs_terms)->toBeNull();
});

it('records a source-decided purchase as such, declaring nothing of its own', function (): void {
    $resolved = new ResolvedCustomsTerms(
        applies: true,
        destinationCountry: 'DE',
        dutiesTerms: DutiesTerms::Ddp,
        dutiesTermsOrigin: CustomsTermsOrigin::Order,
    );
    $request = new ShipRequest(
        fromAddress: AddressData::fromConfig(),
        toAddress: new AddressData('Erika', 'Mustermann', 'Pariser Platz 1', 'Berlin', null, '10117', 'DE'),
        packageData: new PackageData(weight: 2.0, length: 10, width: 8, height: 4),
        exportItn: 'X20261008123456',
        customsTerms: $resolved->asSourceDecided(),
        recipientTaxId: new RecipientTaxId(RecipientTaxIdType::Vat, 'DE123456789'),
    );

    expect(app(CustomsTermsSnapshot::class)->forRequest($request))->toBe([
        'duties_terms' => null,
        'duties_terms_source' => 'source_decided',
        'registration' => null,
        'recipient_tax_id' => null,
        'export_itn' => null,
        'duties_support_version' => app(DutiesSupportTable::class)->version(),
    ]);
});
