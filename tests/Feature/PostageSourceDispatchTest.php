<?php

use App\Contracts\PackageLabelWorkflow;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\PackageStatus;
use App\Enums\TrackingStatus;
use App\Models\Package;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\UspsAdapter;
use App\Services\TrackingService;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * Stands in for the real USPS adapter to record whether the carrier was asked
 * anything at all. The registry instantiates adapters by class name, so the
 * record has to be static.
 */
class SpyUspsAdapter extends UspsAdapter
{
    /** @var array<int, string> */
    public static array $calls = [];

    public function supportsTracking(): bool
    {
        return true;
    }

    public function trackShipment(Package $package): TrackShipmentResponse
    {
        self::$calls[] = 'track';

        return TrackShipmentResponse::success(status: TrackingStatus::InTransit);
    }

    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
    {
        self::$calls[] = 'cancel';

        return CancelResponse::success('Voided at USPS.');
    }
}

beforeEach(function (): void {
    SpyUspsAdapter::$calls = [];
    app(CarrierRegistry::class)->register('USPS', SpyUspsAdapter::class);
});

it('voids a Shopify-bought label through Shopify, never through the carrier carrying it', function (): void {
    // The carrier of record is USPS — the point of the split. Dispatching on it
    // would ask our own USPS account to void a label Shopify bought.
    $package = shippedShopifyPackage();

    $result = app(PackageLabelWorkflow::class)->voidLabel($package, User::factory()->manager()->create());

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('Cancel this label in the Shopify admin')
        ->and(SpyUspsAdapter::$calls)->toBe([])
        ->and($package->refresh()->status)->toBe(PackageStatus::Shipped);
});

it('tracks a Shopify-bought label through Shopify, never through the carrier carrying it', function (): void {
    $package = shippedShopifyPackage();

    Saloon::fake([MockResponse::make(fulfillmentState('OUT_FOR_DELIVERY'))]);

    $response = app(TrackingService::class)->refreshPackage($package);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(TrackingStatus::OutForDelivery)
        ->and($package->refresh()->tracking_status)->toBe(TrackingStatus::OutForDelivery)
        ->and(SpyUspsAdapter::$calls)->toBe([]);
});

it('still sends a directly-bought label to its carrier', function (): void {
    $package = Package::factory()->usps()->create();

    $tracking = app(TrackingService::class)->refreshPackage($package);
    $void = app(PackageLabelWorkflow::class)->voidLabel($package->refresh(), User::factory()->manager()->create());

    expect($tracking->status)->toBe(TrackingStatus::InTransit)
        ->and($void->success)->toBeTrue()
        ->and(SpyUspsAdapter::$calls)->toBe(['track', 'cancel']);
});

it('records a failed check instead of throwing when the source cannot be reached', function (): void {
    // A tracking check is a status read. Letting a throttled reply or a dropped
    // connection escape would 500 the Filament action a packer clicked and fail
    // the queued refresh job, neither of which records that the check missed.
    $package = shippedShopifyPackage();

    Saloon::fake([MockResponse::make(['errors' => [['message' => 'Throttled']]])]);

    $response = app(TrackingService::class)->refreshPackage($package);

    $package->refresh();

    expect($response->success)->toBeFalse()
        ->and($package->tracking_checked_at)->not->toBeNull()
        ->and($package->tracking_status)->toBeNull()
        ->and($package->status)->toBe(PackageStatus::Shipped);
});

it('reads a Shopify label through the source that bought it, not the shipment current one', function (): void {
    $package = shippedShopifyPackage();

    // The shipment is re-pointed at a second Shopify shop after the label was
    // bought. Its fulfillment orders are a different shop's, and its token would
    // not open ours.
    $repointed = createShopifyDataSource(
        ['shop_domain' => 'repointed-shop.myshopify.com'],
        ['oauth_access_token' => 'shpat_repointed'],
    );
    $package->shipment->update(['data_source_id' => $repointed->id]);

    Saloon::fake([MockResponse::make(fulfillmentState('IN_TRANSIT'))]);

    app(TrackingService::class)->refreshPackage($package->fresh());

    Saloon::assertSent(fn ($request, $response): bool => str_contains(
        $response->getPendingRequest()->getUrl(),
        'test-shop.myshopify.com',
    ));
});

it('gives no answer when the source that bought the label has been deactivated', function (): void {
    $package = shippedShopifyPackage();
    $package->postageDataSource->update(['active' => false]);

    // An active import source on the shipment is not a substitute: it is not who
    // bought this label.
    $package->shipment->update([
        'data_source_id' => createShopifyDataSource(['shop_domain' => 'other-shop.myshopify.com'])->id,
    ]);

    Saloon::fake([MockResponse::make(fulfillmentState('IN_TRANSIT'))]);

    $response = app(TrackingService::class)->refreshPackage($package->fresh());

    expect($response->success)->toBeFalse()
        ->and($response->message)->toContain('no fulfillment carrying this tracking number');

    Saloon::assertNothingSent();
});

it('refuses to track a label bought through a postage source it has no integration for', function (): void {
    $package = shippedShopifyPackage();
    // A driver that imports orders and sells no postage at all. This used to be
    // AmazonSource, which stopped being an example of the case when Amazon Buy
    // Shipping got an implementation of its own.
    $package->postageDataSource->update(['source_type' => 'App\\Services\\ShipmentImport\\Sources\\DatabaseSource']);

    $response = app(TrackingService::class)->refreshPackage($package->fresh());

    expect($response->supported)->toBeFalse()
        ->and(SpyUspsAdapter::$calls)->toBe([]);
});
