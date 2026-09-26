<?php

use App\DataTransferObjects\PostageSources\PostageSourceCandidate;
use App\DataTransferObjects\PostageSources\PostageSourceResolution;
use App\Enums\OffAmazonShippingStatus;
use App\Enums\PostageSetting;
use App\Enums\PostageSource;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\PostageSources\PostageSourceResolver;
use Illuminate\Support\Collection;

/**
 * ADR-0002's 2026-09-22 amendment: which Amazon connection sells Amazon
 * Shipping for an order that did not come from Amazon, chosen by a scope row
 * that targets the connection.
 */
function offAmazonPackage(?DataSource $origin = null, ?Client $client = null, ?Location $location = null, array $metadata = []): Package
{
    return Package::factory()->create([
        'location_id' => $location?->id,
        'shipment_id' => Shipment::factory()->create([
            'data_source_id' => $origin?->id,
            'client_id' => $client?->id,
            'metadata' => $metadata,
        ])->id,
    ]);
}

function scopeConnectionTo(DataSource $source, ?Location $location = null, ?Client $client = null): CarrierAccountScope
{
    return CarrierAccountScope::create([
        'data_source_id' => $source->id,
        'location_id' => $location?->id,
        'client_id' => $client?->id,
    ]);
}

/**
 * The candidates bought through a connection, leaving out the direct carriers
 * every package with no shipping method also resolves.
 *
 * @return Collection<int, PostageSourceCandidate>
 */
function connectionCandidates(PostageSourceResolution $resolution): Collection
{
    return $resolution->candidates
        ->reject(fn (PostageSourceCandidate $candidate): bool => $candidate->isDirect())
        ->values();
}

function offAmazonCandidate(Package $package): ?PostageSourceCandidate
{
    return app(PostageSourceResolver::class)->resolve($package->refresh())
        ->candidates
        ->first(fn (PostageSourceCandidate $candidate): bool => $candidate->offAmazon);
}

describe('resolution', function (): void {
    beforeEach(function (): void {
        $this->location = Location::factory()->create();
        $this->client = Client::factory()->create();
    });

    it('offers a globally scoped connection to orders from every other channel', function (): void {
        $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
        scopeConnectionTo($connection);

        $shopify = createShopifyDataSource();
        $database = DataSource::factory()->create();

        foreach ([offAmazonPackage($shopify), offAmazonPackage($database), offAmazonPackage()] as $package) {
            expect(offAmazonCandidate($package)?->postageDataSourceId)->toBe($connection->id);
        }
    });

    it('walks the four precedence bands, most specific first', function (): void {
        $global = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create(['name' => 'Global']);
        $clientOwn = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create(['name' => 'Client']);
        $locationOwn = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create(['name' => 'Location']);
        $both = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create(['name' => 'Both']);

        scopeConnectionTo($global);
        scopeConnectionTo($clientOwn, client: $this->client);
        scopeConnectionTo($locationOwn, location: $this->location);
        scopeConnectionTo($both, $this->location, $this->client);

        $package = offAmazonPackage(client: $this->client, location: $this->location);
        $winner = fn (): ?int => offAmazonCandidate($package)?->postageDataSourceId;

        expect($winner())->toBe($both->id);

        $both->delete();
        expect($winner())->toBe($locationOwn->id);

        $locationOwn->delete();
        expect($winner())->toBe($clientOwn->id);

        $clientOwn->delete();
        expect($winner())->toBe($global->id);
    });

    it('offers at most one connection, because every band holds at most one row', function (): void {
        $a = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
        $b = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();

        scopeConnectionTo($a);
        scopeConnectionTo($b, client: $this->client);

        $candidates = app(PostageSourceResolver::class)
            ->resolve(offAmazonPackage(client: $this->client))
            ->candidates
            ->filter(fn (PostageSourceCandidate $candidate): bool => $candidate->offAmazon);

        expect($candidates)->toHaveCount(1)
            ->and($candidates->first()->postageDataSourceId)->toBe($b->id);
    });

    it('runs without being asked for any carrier', function (): void {
        $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
        scopeConnectionTo($connection);

        $resolution = app(PostageSourceResolver::class)->resolve(offAmazonPackage());

        expect(connectionCandidates($resolution))->toHaveCount(1);
    });

    it('never offers a scoped connection to an Amazon order', function (): void {
        $origin = DataSource::factory()->unassigned()->amazon()->create();
        $other = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
        scopeConnectionTo($other, client: $this->client);

        $package = offAmazonPackage($origin, $this->client);

        expect(offAmazonCandidate($package))->toBeNull()
            ->and(app(PostageSourceResolver::class)->resolve($package)->channel()?->postageDataSourceId)->toBe($origin->id);
    });

    it('never offers a scoped connection to an Amazon order whose connection is inactive', function (): void {
        $origin = DataSource::factory()->unassigned()->amazon()->create(['active' => false]);
        $other = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
        scopeConnectionTo($other);

        $resolution = app(PostageSourceResolver::class)->resolve(offAmazonPackage($origin));

        expect(connectionCandidates($resolution))->toBeEmpty();
    });

    it('recognizes an Amazon order by its order ID once its connection is gone', function (): void {
        $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
        scopeConnectionTo($connection);

        $package = offAmazonPackage(metadata: ['amazon_order_id' => '111-0000000-0000000']);

        expect(offAmazonCandidate($package))->toBeNull();
    });

    it('offers nothing from an inactive, opted-out or unscoped connection', function (): void {
        $inactive = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create(['active' => false]);
        $optedOut = DataSource::factory()->unassigned()->amazon()->create(['offers_off_amazon_shipping' => false]);
        DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();

        scopeConnectionTo($inactive);
        scopeConnectionTo($optedOut, client: $this->client);

        expect(offAmazonCandidate(offAmazonPackage(client: $this->client)))->toBeNull();

        // The opted-out connection keeps its row, ignored.
        expect($optedOut->offAmazonShippingScopes()->count())->toBe(1);
    });

    it('offers nothing when no row matches the package', function (): void {
        $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
        scopeConnectionTo($connection, client: $this->client);

        expect(offAmazonCandidate(offAmazonPackage(client: Client::factory()->create())))->toBeNull();
    });

    it('lets a narrower row for an ineligible connection give way to a wider one', function (): void {
        $global = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
        $clientOwn = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create(['active' => false]);

        scopeConnectionTo($global);
        scopeConnectionTo($clientOwn, client: $this->client);

        expect(offAmazonCandidate(offAmazonPackage(client: $this->client))?->postageDataSourceId)->toBe($global->id);
    });

    it('does not depend on order import', function (): void {
        $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->importDisabled()->create();
        scopeConnectionTo($connection);

        expect(offAmazonCandidate(offAmazonPackage())?->postageDataSourceId)->toBe($connection->id);
    });
});

describe('the off-Amazon candidate', function (): void {
    it('is marked off-Amazon, carries the check result, and is neither channel postage nor a carrier', function (): void {
        $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping(OffAmazonShippingStatus::NotSetUp)->create();
        scopeConnectionTo($connection);

        $shopify = createShopifyDataSource();
        $shopify->update(['postage_setting' => PostageSetting::PackerOnly]);
        $resolution = app(PostageSourceResolver::class)->resolve(offAmazonPackage($shopify));
        $candidate = offAmazonCandidate(offAmazonPackage($shopify));

        // Still returned when Amazon said the account is not set up, so the
        // refusal stays visible instead of the option silently vanishing.
        expect($candidate->kind)->toBe(PostageSource::PostageDataSource)
            ->and($candidate->postageDataSourceId)->toBe($connection->id)
            ->and($candidate->offAmazonShippingStatus)->toBe(OffAmazonShippingStatus::NotSetUp)
            ->and($candidate->carrier)->toBeNull()
            ->and($candidate->isChannel())->toBeFalse()
            ->and($resolution->channel()?->postageDataSourceId)->toBe($shopify->id)
            ->and($resolution->forCarrier('Amazon'))->toBeEmpty();
    });

    it('is never taken for the channel of an order from a database', function (): void {
        $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
        scopeConnectionTo($connection);

        $resolution = app(PostageSourceResolver::class)->resolve(offAmazonPackage(DataSource::factory()->create()));

        expect(connectionCandidates($resolution))->toHaveCount(1)
            ->and($resolution->channel())->toBeNull();
    });
});

describe('the postage setting', function (): void {
    it('does not ask a connection that does not sell postage for its own Amazon orders', function (): void {
        $connection = DataSource::factory()->amazon()->sellingPostage(PostageSetting::DoesNotSell)->create();

        $resolution = app(PostageSourceResolver::class)->resolve(offAmazonPackage($connection));

        expect($resolution->channel())->toBeNull();
    });

    it('does not change what a connection sells to orders from other channels', function (PostageSetting $setting): void {
        $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->sellingPostage($setting)->create();
        scopeConnectionTo($connection);

        expect(offAmazonCandidate(offAmazonPackage())?->postageDataSourceId)->toBe($connection->id);
    })->with([
        'does not sell' => PostageSetting::DoesNotSell,
        'packer only' => PostageSetting::PackerOnly,
    ]);
});

describe('scope rules', function (): void {
    it('refuses a row with no target or with two', function (): void {
        $account = CarrierAccount::factory()->create();
        $connection = DataSource::factory()->unassigned()->amazon()->create();

        expect(fn () => CarrierAccountScope::create([]))->toThrow(DomainException::class)
            ->and(fn () => CarrierAccountScope::create([
                'carrier_account_id' => $account->id,
                'data_source_id' => $connection->id,
            ]))->toThrow(DomainException::class);
    });

    it('derives the Amazon carrier row for a connection and ignores a caller-supplied one', function (): void {
        $usps = Carrier::firstOrCreate(['name' => 'USPS']);
        $connection = DataSource::factory()->unassigned()->amazon()->create();

        $scope = new CarrierAccountScope(['data_source_id' => $connection->id]);
        $scope->carrier_id = $usps->id;
        $scope->save();

        expect($scope->carrier->name)->toBe(AmazonBuyShippingAdapter::SOURCE_NAME);
    });

    it('never lets a connection rate shop', function (): void {
        $scope = CarrierAccountScope::create([
            'data_source_id' => DataSource::factory()->unassigned()->amazon()->create()->id,
            'rate_shop' => true,
        ]);

        expect($scope->refresh()->rate_shop)->toBeFalse();
    });

    it('refuses a carrier account scope on the Amazon row', function (): void {
        $amazon = Carrier::firstOrCreate(['name' => AmazonBuyShippingAdapter::SOURCE_NAME]);
        // A legacy row: the model now refuses an account on the Amazon carrier.
        $account = CarrierAccount::withoutEvents(fn () => CarrierAccount::factory()->create(['carrier_id' => $amazon->id]));

        expect(fn () => CarrierAccountScope::create(['carrier_account_id' => $account->id]))
            ->toThrow(DomainException::class);
    });

    it('refuses a connection that is not Amazon', function (): void {
        expect(fn () => CarrierAccountScope::create(['data_source_id' => createShopifyDataSource()->id]))
            ->toThrow(DomainException::class);
    });

    it('scopes a client-assigned connection only to its own client', function (): void {
        $client = Client::factory()->create();
        $connection = DataSource::factory()->amazon()->create(['client_id' => $client->id]);
        $location = Location::factory()->create();

        expect(fn (): CarrierAccountScope => scopeConnectionTo($connection))->toThrow(DomainException::class)
            ->and(fn (): CarrierAccountScope => scopeConnectionTo($connection, location: $location))->toThrow(DomainException::class)
            ->and(fn (): CarrierAccountScope => scopeConnectionTo($connection, client: Client::factory()->create()))->toThrow(DomainException::class);

        scopeConnectionTo($connection, client: $client);
        scopeConnectionTo($connection, $location, $client);

        expect($connection->offAmazonShippingScopes()->count())->toBe(2);
    });

    it('lets an unassigned connection take any scope', function (): void {
        $connection = DataSource::factory()->unassigned()->amazon()->create();

        scopeConnectionTo($connection);
        scopeConnectionTo($connection, location: Location::factory()->create());
        scopeConnectionTo($connection, client: Client::factory()->create());

        expect($connection->offAmazonShippingScopes()->count())->toBe(3);
    });

    it('drops the rows outside the client a connection is moved to', function (): void {
        $client = Client::factory()->create();
        $connection = DataSource::factory()->unassigned()->amazon()->create();

        scopeConnectionTo($connection);
        $kept = scopeConnectionTo($connection, client: $client);
        scopeConnectionTo($connection, client: Client::factory()->create());

        $connection->update(['client_id' => $client->id]);

        expect($connection->offAmazonShippingScopes()->pluck('id')->all())->toBe([$kept->id]);
    });
});

describe('default scope', function (): void {
    it('gives an unassigned connection the global row', function (): void {
        $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();

        expect($connection->ensureDefaultOffAmazonShippingScope())->toBeTrue();

        $scope = $connection->offAmazonShippingScopes()->sole();
        expect($scope->location_id)->toBeNull()
            ->and($scope->client_id)->toBeNull();
    });

    it('gives a client-assigned connection a row for its own client at every location', function (): void {
        $client = Client::factory()->create();
        $connection = DataSource::factory()->offeringOffAmazonShipping()->create(['client_id' => $client->id]);

        expect($connection->ensureDefaultOffAmazonShippingScope())->toBeTrue();

        $scope = $connection->offAmazonShippingScopes()->sole();
        expect($scope->location_id)->toBeNull()
            ->and($scope->client_id)->toBe($client->id);
    });

    it('creates nothing when the slot is taken', function (): void {
        $holder = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
        scopeConnectionTo($holder);

        $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();

        expect($connection->ensureDefaultOffAmazonShippingScope())->toBeFalse()
            ->and($connection->offAmazonShippingScopes()->count())->toBe(0);
    });

    it('leaves a connection that already has rows alone', function (): void {
        $client = Client::factory()->create();
        $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
        scopeConnectionTo($connection, client: $client);

        expect($connection->ensureDefaultOffAmazonShippingScope())->toBeTrue()
            ->and($connection->offAmazonShippingScopes()->count())->toBe(1);
    });
});
