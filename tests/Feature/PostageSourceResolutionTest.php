<?php

use App\Contracts\CarrierAdapterInterface;
use App\Contracts\CarrierPolicy;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\PostageSource;
use App\Enums\ServiceCapability;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\CarrierService;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\PostageSources\PostageSourceResolver;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShopifyShippingLabelService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * ADR-0002 decision 9 and ADR-0006 decision 4: which postage source instances
 * are asked for a package.
 */
function packageFrom(?DataSource $source = null, array $shipment = [], array $package = []): Package
{
    return Package::factory()->create(array_merge([
        'shipment_id' => Shipment::factory()->create(array_merge([
            'data_source_id' => $source?->id,
        ], $shipment))->id,
    ], $package));
}

function scopeAccountTo(CarrierAccount $account, ?Location $location, ?Client $client, bool $rateShop = false): CarrierAccountScope
{
    return CarrierAccountScope::create([
        'carrier_account_id' => $account->id,
        'location_id' => $location?->id,
        'client_id' => $client?->id,
        'rate_shop' => $rateShop,
    ]);
}

/**
 * A carrier of record we hold policy for and buy nothing from.
 *
 * ADR-0002 option D leaves this open on purpose: Shopify may pick a courier we
 * have no account with, and its cutoffs and manifest behavior still have to come
 * out right. Being able to answer those questions is not being able to sell a
 * label, which is the distinction `policyFor()` cannot draw.
 */
class PolicyOnlyCarrierAdapter implements CarrierAdapterInterface, CarrierPolicy
{
    public const CARRIER_NAME = 'DHL Express';

    public function getCarrierName(): string
    {
        return self::CARRIER_NAME;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function offerCapability(string $serviceCode): ServiceCapability
    {
        return ServiceCapability::NotImplemented;
    }

    public function offerDeclaredValueCap(): ?float
    {
        return null;
    }

    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        return collect();
    }

    public function createShipment(ShipRequest $request): ShipResponse
    {
        return ShipResponse::failure('We hold no account with this carrier.');
    }

    public function resolvePreSelectedRate(RateResponse $rate, Package $package): RateResponse
    {
        return $rate;
    }

    public function packagingRequirementFor(RateResponse $rate): PackagingRequirement
    {
        return PackagingRequirement::shipperPackaging();
    }

    public function customsDocumentDelivery(AddressData $from, AddressData $to, ?RateResponse $rate = null): CustomsDocumentDelivery
    {
        return CustomsDocumentDelivery::None;
    }

    public function serviceCapability(string $serviceCode): ServiceCapability
    {
        return ServiceCapability::NotImplemented;
    }

    public function declaredValueCap(): ?float
    {
        return null;
    }

    public function supportsMultiPackage(): bool
    {
        return false;
    }

    public function supportsCarrierManifest(): bool
    {
        return false;
    }

    public function supportsTracking(): bool
    {
        return false;
    }
}

describe('channel binding', function (): void {
    it('binds a Shopify shipment to the source it was imported from and to no other', function (): void {
        $bought = createShopifyDataSource(['shop_domain' => 'shop-a.myshopify.com']);
        $other = createShopifyDataSource(['shop_domain' => 'shop-b.myshopify.com']);

        $package = packageFrom($bought);

        // Not a preference between two candidates: source B is never a candidate.
        // Its credentials read another shop's fulfillment orders entirely.
        expect(app(PostageSourceResolver::class)->channelSourceFor($package)?->id)->toBe($bought->id)
            ->and($other->id)->not->toBe($bought->id);
    });

    it('resolves no channel source for a shipment that came from nowhere that sells postage', function (): void {
        $database = DataSource::create([
            'name' => 'Warehouse DB',
            'source_type' => DatabaseSource::class,
            'active' => true,
            'settings' => ['db_host' => 'localhost'],
        ]);

        expect(app(PostageSourceResolver::class)->channelSourceFor(packageFrom($database)))->toBeNull()
            ->and(app(PostageSourceResolver::class)->channelSourceFor(packageFrom()))->toBeNull();
    });

    it('resolves no channel source once the source that sold postage is deactivated', function (): void {
        $source = createShopifyDataSource();
        $package = packageFrom($source);

        $source->update(['active' => false]);

        expect(app(PostageSourceResolver::class)->channelSourceFor($package->refresh()))->toBeNull();
    });

    it('still resolves the originating channel source once its order import is turned off', function (): void {
        $source = createShopifyDataSource();
        $package = packageFrom($source);

        // The order already exists in PolyBag, so buying its postage through
        // the connection it came from needs the connection active, not importing.
        $source->update(['import_enabled' => false]);

        expect(app(PostageSourceResolver::class)->channelSourceFor($package->refresh())?->id)->toBe($source->id);
    });

    it('offers the bound channel source as a candidate with no carrier of its own', function (): void {
        $source = createShopifyDataSource();

        $resolution = app(PostageSourceResolver::class)->resolve(packageFrom($source));
        $channel = $resolution->channel();

        // A blind-purchase offer names no carrier until the label comes back,
        // so it belongs to no carrier's candidate set (ADR-0003 decisions 5-6).
        expect($channel?->kind)->toBe(PostageSource::PostageDataSource)
            ->and($channel?->postageDataSourceId)->toBe($source->id)
            ->and($channel?->carrier)->toBeNull()
            ->and($resolution->candidates->filter(fn ($candidate): bool => $candidate->carrier === null)->values()->all())
            ->toBe([$channel]);
    });

    it('answers the Shopify label service through the same rule rather than its own', function (): void {
        $shopify = createShopifyDataSource();
        $labelService = app(ShopifyShippingLabelService::class);

        expect($labelService->dataSourceFor(packageFrom($shopify))?->id)->toBe($shopify->id)
            ->and($labelService->dataSourceFor(packageFrom()))->toBeNull();
    });
});

describe('carrier account precedence', function (): void {
    beforeEach(function (): void {
        $this->carrier = Carrier::firstOrCreate(['name' => 'USPS']);
        $this->location = Location::factory()->create();
        $this->client = Client::factory()->create();

        $this->account = fn (string $name) => CarrierAccount::create([
            'carrier_id' => $this->carrier->id,
            'name' => $name,
            'active' => true,
        ]);
    });

    it('prefers a client-scoped account over the global default', function (): void {
        $global = ($this->account)('Global USPS');
        $clientOwn = ($this->account)('Client USPS');

        scopeAccountTo($global, null, null);
        scopeAccountTo($clientOwn, null, $this->client);

        $package = packageFrom(shipment: ['client_id' => $this->client->id], package: ['location_id' => null]);

        $candidates = app(PostageSourceResolver::class)->resolve($package)->forCarrier('USPS');

        expect($candidates)->toHaveCount(1)
            ->and($candidates->first()->carrierAccountId)->toBe($clientOwn->id)
            ->and($candidates->first()->kind)->toBe(PostageSource::CarrierAccount);
    });

    it('walks the full precedence chain, most specific first', function (): void {
        $global = ($this->account)('Global USPS');
        $clientOwn = ($this->account)('Client USPS');
        $locationOwn = ($this->account)('Location USPS');
        $both = ($this->account)('Location + client USPS');

        scopeAccountTo($global, null, null);
        scopeAccountTo($clientOwn, null, $this->client);
        scopeAccountTo($locationOwn, $this->location, null);
        scopeAccountTo($both, $this->location, $this->client);

        $package = packageFrom(
            shipment: ['client_id' => $this->client->id],
            package: ['location_id' => $this->location->id],
        );

        $winner = fn (): ?int => app(PostageSourceResolver::class)
            ->resolve($package->refresh())
            ->forCarrier('USPS')
            ->first()?->carrierAccountId;

        expect($winner())->toBe($both->id);

        $both->delete();
        expect($winner())->toBe($locationOwn->id);

        $locationOwn->delete();
        expect($winner())->toBe($clientOwn->id);

        $clientOwn->delete();
        expect($winner())->toBe($global->id);
    });

    it('quotes one source per carrier by default', function (): void {
        $winner = ($this->account)('Location + client USPS');
        $locationDefault = ($this->account)('Location USPS');

        scopeAccountTo($winner, $this->location, $this->client);
        scopeAccountTo($locationDefault, $this->location, null);

        $package = packageFrom(
            shipment: ['client_id' => $this->client->id],
            package: ['location_id' => $this->location->id],
        );

        // Both accounts could sell USPS postage. Asking both is a second API
        // call on the packer's critical path, so it stays opt-in.
        expect(app(PostageSourceResolver::class)->resolve($package)->forCarrier('USPS'))
            ->toHaveCount(1);
    });

    it('quotes the first account only, even when the winning scope opts into rate shopping', function (): void {
        $winner = ($this->account)('Location + client USPS');
        $locationDefault = ($this->account)('Location USPS');

        scopeAccountTo($winner, $this->location, $this->client, rateShop: true);
        scopeAccountTo($locationDefault, $this->location, null);

        $package = packageFrom(
            shipment: ['client_id' => $this->client->id],
            package: ['location_id' => $this->location->id],
        );

        $candidates = app(PostageSourceResolver::class)->resolve($package)->forCarrier('USPS');

        // The purchase path checks an offer against the first account only, so
        // an offer from the second would be refused as "Carrier Account
        // Changed" (ADR-0006, *Foreseen, not decided*).
        expect($candidates->pluck('carrierAccountId')->all())->toBe([$winner->id]);
    });

    it('ignores an inactive account and falls through to the next scope', function (): void {
        $global = ($this->account)('Global USPS');
        $clientOwn = ($this->account)('Client USPS');

        scopeAccountTo($global, null, null);
        scopeAccountTo($clientOwn, null, $this->client);
        $clientOwn->update(['active' => false]);

        $package = packageFrom(shipment: ['client_id' => $this->client->id], package: ['location_id' => null]);

        expect(app(PostageSourceResolver::class)->resolve($package)->forCarrier('USPS')->first()?->carrierAccountId)
            ->toBe($global->id);
    });

    it('offers a carrier nothing is scoped to with no account', function (): void {
        Carrier::firstOrCreate(['name' => 'FedEx']);

        // The integration is still asked, as it always was: a real one quotes
        // nothing without an account, and a fake carrier quotes anyway.
        $fedex = app(PostageSourceResolver::class)->resolve(packageFrom())->forCarrier('FedEx');

        expect($fedex)->toHaveCount(1)
            ->and($fedex->first()->carrierAccountId)->toBeNull()
            ->and($fedex->first()->kind)->toBe(PostageSource::CarrierAccount);
    });

    it('resolves only the direct carriers a shipping method needs', function (): void {
        $global = ($this->account)('Global USPS');
        scopeAccountTo($global, null, null);

        $method = ShippingMethod::factory()->create();
        $method->carrierServices()->attach(CarrierService::factory()->uspsGroundAdvantage()->for($this->carrier)->create());
        $method->carrierServices()->attach(
            CarrierService::factory()->for(Carrier::firstOrCreate(['name' => ShopifyAdapter::CARRIER_NAME]))->create()
        );

        $resolution = app(PostageSourceResolver::class)->resolve(packageFrom(), $method);

        // Shopify's row is on the method, but it is no direct carrier: its
        // postage comes from the order's own connection, if at all.
        expect($resolution->candidates->pluck('carrier')->all())->toBe(['USPS'])
            ->and($resolution->forCarrier('USPS')->first()?->carrierAccountId)->toBe($global->id);
    });
});

describe('accounts on carriers we do not sell directly', function (): void {
    it('never reads an account left on a resale channel or a policy-only carrier', function (): void {
        // Rows made before `CarrierAccount` refused them. Only carriers with a
        // direct integration are walked, so neither is read, nor reported: a
        // policy-only carrier's policy is not an account we can buy on, and
        // Shopify's postage comes from the order's connection.
        app(CarrierRegistry::class)->register(
            PolicyOnlyCarrierAdapter::CARRIER_NAME,
            PolicyOnlyCarrierAdapter::class,
        );

        foreach ([ShopifyAdapter::CARRIER_NAME, PolicyOnlyCarrierAdapter::CARRIER_NAME] as $name) {
            scopeAccountTo(CarrierAccount::withoutEvents(fn () => CarrierAccount::create([
                'carrier_id' => Carrier::firstOrCreate(['name' => $name])->id,
                'name' => "{$name} account",
                'active' => true,
            ])), null, null);
        }

        $source = createShopifyDataSource();
        $resolution = app(PostageSourceResolver::class)->resolve(packageFrom($source));

        expect($resolution->forCarrier(ShopifyAdapter::CARRIER_NAME))->toBeEmpty()
            ->and($resolution->forCarrier(PolicyOnlyCarrierAdapter::CARRIER_NAME))->toBeEmpty()
            ->and($resolution->channel()?->postageDataSourceId)->toBe($source->id);
    });
});

describe('unresolvable ties', function (): void {
    it('cannot be given two accounts at one precedence to arbitrate between', function (): void {
        $carrier = Carrier::firstOrCreate(['name' => 'USPS']);
        $client = Client::factory()->create();

        $account = fn (string $name) => CarrierAccount::create([
            'carrier_id' => $carrier->id,
            'name' => $name,
            'active' => true,
        ]);

        scopeAccountTo($account('Contract USPS'), null, $client);

        // The schema, not a check in the resolver, is what makes "never an
        // arbitrary pick" true for direct carriers: carrier_account_scopes is
        // unique on (carrier, location, client), so each precedence band holds
        // at most one scope and the walk has nothing to arbitrate.
        expect(fn (): CarrierAccountScope => scopeAccountTo($account('Retail USPS'), null, $client))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});
