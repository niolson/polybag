<?php

use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\DataSource;
use App\Models\Setting;
use App\Models\Shipment;
use App\Services\SettingsService;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * `capacity-audit/02`: a run holds every Amazon order it reads, so it reads at most
 * `max_amazon_orders_per_run` and keeps its pagination token for the next run, as it
 * does when Amazon throttles the search.
 */
beforeEach(function (): void {
    Setting::updateOrCreate(['key' => 'require_mfa'], ['value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();
    Cache::put('amazon_sp_api_access_token_'.md5('test-refresh-token'), 'test-access-token', 3600);

    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00'));

    $this->dataSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'name' => 'Amazon',
        'settings' => ['channel_name' => 'Amazon', 'marketplace_id' => 'ATVPDKIKX0DER', 'lookback_days' => 30],
        'secret_settings' => ['client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'test-refresh-token'],
    ]);

    tap(Channel::factory()->create(['name' => 'Amazon']), fn (Channel $channel) => ChannelAlias::firstOrCreate(
        ['reference' => 'Amazon'],
        ['channel_id' => $channel->id],
    ));

    config(['shipment-import.behavior.auto_update_products' => false]);
});

/**
 * Amazon's search, answering `$total` open orders 100 to a page by pagination token.
 *
 * @return ArrayObject<int, int> the page of every request, appended as it happens
 */
function fakeAmazonOrderSearch(int $total): ArrayObject
{
    $requested = new ArrayObject;

    Saloon::fake(['*' => function ($pending) use ($total, $requested): MockResponse {
        $page = (int) ($pending->query()->get('paginationToken') ?? 0);
        $requested->append($page);
        $from = $page * 100 + 1;
        $count = min(100, $total - $page * 100);

        $body = ['orders' => array_map(fn (int $n): array => [
            'orderId' => sprintf('111-%07d-0000000', $n),
            'createdTime' => '2026-10-01T15:00:00Z',
            'lastUpdatedTime' => '2026-10-13T11:58:00Z',
            'salesChannel' => ['channelName' => 'AMAZON', 'marketplaceId' => 'ATVPDKIKX0DER'],
            'fulfillment' => ['fulfillmentStatus' => 'UNSHIPPED', 'fulfilledBy' => 'MERCHANT'],
            'recipient' => ['deliveryAddress' => [
                'name' => 'Jane Smith', 'addressLine1' => '456 Oak Ave', 'city' => 'Seattle',
                'stateOrRegion' => 'WA', 'postalCode' => '98101', 'countryCode' => 'US',
            ]],
            'orderItems' => [[
                'orderItemId' => "ITEM-{$n}",
                'quantityOrdered' => 1,
                'product' => ['title' => 'Widget', 'sellerSku' => 'SKU-'.($n % 10)],
                'fulfillment' => ['quantityFulfilled' => 0, 'quantityUnfulfilled' => 1],
            ]],
        ], range($from, $from + $count - 1))];

        if (($page + 1) * 100 < $total) {
            $body['pagination'] = ['nextToken' => (string) ($page + 1)];
        }

        return MockResponse::make($body);
    }]);

    return $requested;
}

function importAmazon(DataSource $dataSource): int
{
    $before = Shipment::count();
    $result = ShipmentImportService::forRecord($dataSource->fresh())->import();

    expect($result->hasErrors())->toBeFalse();

    return Shipment::count() - $before;
}

it('reads a long search across runs, each taking at most the limit', function (): void {
    config(['shipment-import.behavior.max_amazon_orders_per_run' => 200]);
    fakeAmazonOrderSearch(450);

    $imported = [importAmazon($this->dataSource), importAmazon($this->dataSource), importAmazon($this->dataSource)];

    expect($imported)->toBe([200, 200, 50])
        ->and(Shipment::count())->toBe(450);
});

it('leaves a cut-short read for the next run rather than treating it as finished', function (): void {
    config(['shipment-import.behavior.max_amazon_orders_per_run' => 100]);
    $requested = fakeAmazonOrderSearch(250);

    importAmazon($this->dataSource);

    $source = $this->dataSource->fresh();

    expect($source->import_updated_through)->toBeNull()
        ->and($source->import_reads['sweep']['token'] ?? null)->toBe('1')
        ->and($requested->getArrayCopy())->toBe([0]);
});

it('reads the whole search in one run when the limit is 0', function (): void {
    config(['shipment-import.behavior.max_amazon_orders_per_run' => 0]);
    fakeAmazonOrderSearch(450);

    expect(importAmazon($this->dataSource))->toBe(450);
});

it('takes a search that ends exactly at the limit as finished', function (): void {
    config(['shipment-import.behavior.max_amazon_orders_per_run' => 200]);
    fakeAmazonOrderSearch(200);

    expect(importAmazon($this->dataSource))->toBe(200)
        ->and($this->dataSource->fresh()->import_reads)->toBeNull();
});
