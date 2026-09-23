<?php

use App\DataTransferObjects\Shipping\RateRequest;
use App\Enums\OffAmazonShippingStatus;
use App\Enums\PostageSource;
use App\Exceptions\Carriers\CarrierUnavailableException;
use App\Filament\Pages\Ship;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Models\CarrierAccountScope;
use App\Models\DataSource;
use App\Models\ObservedService;
use App\Models\Package;
use App\Models\Product;
use App\Models\ShippingOffer;
use App\Models\User;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\SettingsService;
use App\Services\ShippingRateService;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Senders\GuzzleSender;
use Saloon\Laravel\Facades\Saloon;

/**
 * `amazon-shipping-external-orders/05`: a Package whose order did not come from
 * Amazon is rated `channelType: EXTERNAL` on the Amazon connection scoped to
 * sell it Amazon Shipping, and its rates are Offers beside everyone else's.
 */
beforeEach(function (): void {
    Cache::put('amazon_sp_api_access_token_'.md5('external-refresh-token'), 'external-access-token', 3600);

    $this->connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create([
        'name' => 'Amazon Shipping (3PL)',
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER'],
        'secret_settings' => ['refresh_token' => 'external-refresh-token'],
    ]);
    CarrierAccountScope::create(['data_source_id' => $this->connection->id]);

    $this->shopify = createShopifyDataSource();
});

function externalA101Response(): MockResponse
{
    return MockResponse::make(['errors' => [[
        'code' => 'Unauthorized',
        'message' => 'Access to requested resource is denied.',
        'details' => 'Access denied for this account. Please contact support. (A-101)',
    ]]], 403);
}

/**
 * @return array<string, mixed>
 */
function sentExternalBody(): array
{
    $body = null;

    Saloon::assertSent(function (GetShippingRates $request) use (&$body): bool {
        $body = $request->body()->all();

        return true;
    });

    return $body;
}

it('offers Amazon Shipping to a Shopify- or Database-imported package through rate shopping', function (?string $origin): void {
    Saloon::fake([GetShippingRates::class => externalRatesResponse()]);

    $source = match ($origin) {
        'shopify' => $this->shopify,
        'database' => DataSource::factory()->create(),
        default => null,
    };

    $rates = app(ShippingRateService::class)->getShippingRates(externalPackage($source)->id);

    expect($rates)->toHaveCount(1)
        ->and($rates->first()->carrier)->toBe('Amazon Shipping')
        ->and($rates->first()->serviceName)->toBe('Amazon Shipping Ground')
        ->and($rates->first()->price)->toBe(7.9)
        ->and($rates->first()->offerId)->not->toBeNull();
})->with(['shopify', 'database', 'manual' => null]);

it('sends an EXTERNAL body with no order ID that conforms to the published Shipping v2 schema', function (): void {
    Saloon::fake([GetShippingRates::class => externalRatesResponse()]);

    $package = externalPackage($this->shopify);
    app(ShippingRateService::class)->getShippingRates($package->id);

    $body = sentExternalBody();
    assertMatchesSpApiSchema($body, 'GetRatesRequest', 'shippingV2');

    expect($body['channelDetails'])->toBe(['channelType' => 'EXTERNAL'])
        ->and($body['packages'])->toHaveCount(1)
        ->and($body['packages'][0]['insuredValue'])->toEqual(['unit' => 'USD', 'value' => 0])
        ->and($body['packages'][0]['packageClientReferenceId'])->toBe((string) $package->id)
        ->and($body['packages'][0]['items'])->toBe([[
            'itemValue' => ['value' => 12.5, 'unit' => 'USD'],
            'description' => 'Widget 1',
            'itemIdentifier' => 'SKU-1',
            'quantity' => 1,
            'weight' => ['unit' => 'POUND', 'value' => 0.5],
        ]])
        ->and($body['shipFrom']['postalCode'])->toBe($package->location->postal_code)
        ->and($body['shipTo']['postalCode'])->toBe($package->shipment->postal_code);
});

it('scales item weights down to fit the scanned package weight when product weights overshoot it', function (): void {
    Saloon::fake([GetShippingRates::class => externalRatesResponse()]);

    // `01`'s D-703 case: 3 × 0.6 lb declared inside a 1.52 lb parcel, plus a
    // second line whose product record is plainly wrong.
    $package = externalPackage($this->shopify, weight: 1.52, items: [[0.6, 3], [4.0, 1]]);
    app(ShippingRateService::class)->getShippingRates($package->id);

    $parcel = sentExternalBody()['packages'][0];
    $total = collect($parcel['items'])->sum(fn (array $item): float => $item['weight']['value'] * $item['quantity']);

    expect($parcel['weight']['value'])->toBe(1.52)
        ->and($total)->toBeLessThanOrEqual(1.52)
        ->and($total)->toBeGreaterThan(1.4)
        ->and(collect($parcel['items'])->pluck('quantity')->all())->toBe([3, 1])
        // Proportions survive the scaling: the 4 lb line stays the heavier one.
        ->and($parcel['items'][1]['weight']['value'])->toBeGreaterThan($parcel['items'][0]['weight']['value']);
});

it('leaves item weights alone when they already fit, and sends a missing weight as zero', function (): void {
    Saloon::fake([GetShippingRates::class => externalRatesResponse()]);

    app(ShippingRateService::class)->getShippingRates(
        externalPackage($this->shopify, weight: 2.0, items: [[0.5, 2], [null, 1]])->id
    );

    expect(collect(sentExternalBody()['packages'][0]['items'])->pluck('weight.value')->all())->toBe([0.5, 0.0]);
});

it('describes a package with nothing packed as one weightless item', function (): void {
    Saloon::fake([GetShippingRates::class => externalRatesResponse()]);

    app(ShippingRateService::class)->getShippingRates(externalPackage($this->shopify, items: [])->id);

    $body = sentExternalBody();
    assertMatchesSpApiSchema($body, 'GetRatesRequest', 'shippingV2');

    expect($body['packages'][0]['items'])->toBe([[
        'description' => 'Merchandise',
        'quantity' => 1,
        'weight' => ['unit' => 'POUND', 'value' => 0],
    ]]);
});

it('binds the offer to the scoped connection, never the shipment\'s import source', function (): void {
    Saloon::fake([GetShippingRates::class => externalRatesResponse()]);

    $package = externalPackage($this->shopify);
    $rate = app(ShippingRateService::class)->getShippingRates($package->id)->first();

    $offer = ShippingOffer::where('public_id', $rate->offerId)->sole();

    expect($offer->postage_source)->toBe(PostageSource::PostageDataSource)
        ->and($offer->postage_data_source_id)->toBe($this->connection->id)
        ->and($offer->postage_data_source_id)->not->toBe($package->shipment->data_source_id)
        ->and($offer->purchase_context)->toBe([
            'requestToken' => 'amzn1.rq.external-request-token',
            'rateId' => 'b1a4a1f0-0c4f-4a47-9d2e-5c6f0a1e7a11',
        ])
        ->and($offer->rate_quote_id)->not->toBeNull()
        ->and($offer->quote_fingerprint)->not->toBeNull();
});

it('records the observed services an EXTERNAL reply names, so mapping and approval apply', function (): void {
    Saloon::fake([GetShippingRates::class => externalRatesResponse()]);

    $rate = app(ShippingRateService::class)->getShippingRates(externalPackage($this->shopify)->id)->first();

    $observed = ObservedService::sole();

    expect($observed->source)->toBe(AmazonBuyShippingAdapter::OBSERVATION_SOURCE)
        ->and($observed->external_carrier_id)->toBe('AMZN_US')
        ->and($observed->external_service_id)->toBe('std-us-swa-mfn')
        ->and($observed->carrier_service_id)->toBeNull()
        ->and($rate->observedService?->externalServiceId)->toBe('std-us-swa-mfn');
});

it('treats an empty rate list as no offers, not an error', function (): void {
    Saloon::fake([GetShippingRates::class => externalRatesResponse([])]);

    $service = app(ShippingRateService::class);

    expect($service->getShippingRates(externalPackage($this->shopify)->id))->toBeEmpty()
        ->and($service->getExclusions())->toBe([]);
});

it('answers a 403 A-101 with no offers and a not-set-up message, and marks the connection', function (): void {
    Saloon::fake([GetShippingRates::class => externalA101Response()]);

    $service = app(ShippingRateService::class);
    $rates = $service->getShippingRates(externalPackage($this->shopify)->id);

    expect($rates)->toBeEmpty()
        ->and(ShippingOffer::count())->toBe(0)
        ->and($service->getExclusions())->toHaveCount(1)
        ->and($service->getExclusions()[0]['carrier'])->toBe('Amazon')
        ->and($service->getExclusions()[0]['reason'])
        ->toContain('Amazon Shipping (3PL)')
        ->toContain('A-101')
        ->toContain('Amazon Shipping sign-up in Seller Central')
        ->and($this->connection->refresh()->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::NotSetUp)
        ->and($this->connection->off_amazon_shipping_checked_at)->not->toBeNull();
});

/**
 * A real Guzzle client answering with one canned reply. Saloon's fakes send
 * everything synchronously, and the concurrent path is the one where a 4xx
 * would otherwise become a rejected promise.
 */
function guzzleSenderAnswering(int $status, array $body): GuzzleSender
{
    $reply = new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($body));

    return new class($reply) extends GuzzleSender
    {
        public function __construct(private readonly Psr7Response $reply)
        {
            parent::__construct();
        }

        protected function defaultHandlerStack(): HandlerStack
        {
            return HandlerStack::create(new MockHandler([fn (): Psr7Response => $this->reply]));
        }
    };
}

function a101Body(): array
{
    return ['errors' => [[
        'code' => 'Unauthorized',
        'message' => 'Access to requested resource is denied.',
        'details' => 'Access denied for this account. Please contact support. (A-101)',
    ]]];
}

it('hears A-101 on the concurrent path as a response, not as a rejected promise', function (): void {
    $adapter = new AmazonBuyShippingAdapter;
    $request = RateRequest::fromPackage(externalPackage($this->shopify));
    $response = guzzleSenderAnswering(403, a101Body())
        ->sendAsync($adapter->prepareRateRequest($request, [])->pendingRequest)
        ->wait();

    expect($response->status())->toBe(403)
        ->and(fn (): Collection => $adapter->parseRateResponse($response, $request, []))
        ->toThrow(CarrierUnavailableException::class, 'A-101');
});

describe('a scope changed while the request is in flight', function (): void {
    beforeEach(function (): void {
        $this->adapter = new AmazonBuyShippingAdapter;
        $this->request = RateRequest::fromPackage(externalPackage($this->shopify));
        $this->pending = $this->adapter->prepareRateRequest($this->request, [])->pendingRequest;

        // Between prepare and parse: the global slot moves to another
        // connection, and the one that was asked is switched off.
        $this->other = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
        CarrierAccountScope::query()->delete();
        CarrierAccountScope::create(['data_source_id' => $this->other->id]);
        $this->connection->update(['active' => false]);
    });

    it('binds the offer to the connection that was asked', function (): void {
        $response = guzzleSenderAnswering(200, ['payload' => [
            'requestToken' => 'amzn1.rq.external-request-token',
            'rates' => [amazonShippingGroundRate()],
            'ineligibleRates' => [],
        ]])->sendAsync($this->pending)->wait();

        $rate = $this->adapter->parseRateResponse($response, $this->request, [])->sole();

        expect(ShippingOffer::where('public_id', $rate->offerId)->sole()->postage_data_source_id)
            ->toBe($this->connection->id);
    });

    it('records the A-101 against the connection that was asked', function (): void {
        $response = guzzleSenderAnswering(403, a101Body())->sendAsync($this->pending)->wait();

        expect(fn (): Collection => $this->adapter->parseRateResponse($response, $this->request, []))
            ->toThrow(CarrierUnavailableException::class, 'Amazon Shipping (3PL)')
            ->and($this->connection->refresh()->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::NotSetUp)
            ->and($this->other->refresh()->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::Enabled);
    });
});

it('does not ask again when Amazon refuses, since the refusal would not change', function (): void {
    Saloon::fake([GetShippingRates::class => externalA101Response()]);

    app(ShippingRateService::class)->getShippingRates(externalPackage($this->shopify)->id);

    Saloon::assertSentCount(1);
});

it('reports any other refusal as no offers without touching the connection', function (): void {
    Saloon::fake([GetShippingRates::class => MockResponse::make(['errors' => [['code' => 'InvalidInput', 'message' => 'Bad']]], 400)]);

    $service = app(ShippingRateService::class);

    expect($service->getShippingRates(externalPackage($this->shopify)->id))->toBeEmpty()
        ->and($service->getExclusions())->toBe([])
        ->and($this->connection->refresh()->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::Enabled);
});

it('clears a stale not-set-up once a production quote succeeds', function (): void {
    $this->connection->update(['off_amazon_shipping_status' => OffAmazonShippingStatus::NotSetUp]);
    Saloon::fake([GetShippingRates::class => externalRatesResponse()]);

    app(ShippingRateService::class)->getShippingRates(externalPackage($this->shopify)->id);

    expect($this->connection->refresh()->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::Enabled);
});

it('records nothing about the connection from a sandbox quote, which answers for any account', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true);
    $this->connection->update(['off_amazon_shipping_status' => OffAmazonShippingStatus::Unknown]);
    Saloon::fake([GetShippingRates::class => externalRatesResponse()]);

    $rates = app(ShippingRateService::class)->getShippingRates(externalPackage($this->shopify)->id);

    expect($rates)->toHaveCount(1)
        ->and($this->connection->refresh()->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::Unknown);
});

it('still rates an Amazon order on its own connection as AMAZON, whatever is scoped', function (): void {
    $origin = DataSource::factory()->unassigned()->amazon()->create([
        'secret_settings' => ['refresh_token' => 'external-refresh-token'],
    ]);
    $package = externalPackage($origin);
    $package->shipment->update(['metadata' => ['amazon_order_id' => '111-2222222-3333333']]);
    $package->shipment->shipmentItems()->update(['source_item_id' => 'AMAZON-ITEM-123']);

    Saloon::fake([GetShippingRates::class => externalRatesResponse()]);

    $rate = app(ShippingRateService::class)->getShippingRates($package->id)->first();

    expect(sentExternalBody()['channelDetails'])->toBe([
        'channelType' => 'AMAZON',
        'amazonOrderDetails' => ['orderId' => '111-2222222-3333333'],
    ])
        ->and(ShippingOffer::where('public_id', $rate->offerId)->sole()->postage_data_source_id)->toBe($origin->id);
});

it('asks nothing of Amazon for an order from another channel with no connection scoped to it', function (): void {
    CarrierAccountScope::query()->delete();
    Saloon::fake([GetShippingRates::class => externalRatesResponse()]);

    $package = externalPackage($this->shopify);

    expect((new AmazonBuyShippingAdapter)->getRates(RateRequest::fromPackage($package), []))->toBeEmpty();
    Saloon::assertNothingSent();
});

describe('Ship page', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->create());
    });

    it('lists Amazon Shipping Offers for an off-Amazon package', function (): void {
        Saloon::fake([GetShippingRates::class => externalRatesResponse()]);

        $component = Livewire::test(Ship::class, ['package_id' => externalPackage($this->shopify)->id]);

        expect(collect($component->get('rateOptions'))->pluck('serviceName')->all())->toBe(['Amazon Shipping Ground']);
    });

    it('tells the packer the connection is not set up when Amazon answers A-101', function (): void {
        Saloon::fake([GetShippingRates::class => externalA101Response()]);

        $component = Livewire::test(Ship::class, ['package_id' => externalPackage($this->shopify)->id]);

        $component->assertNotified('Amazon excluded');
        expect($component->get('rateOptions'))->toBe([]);
    });
});
