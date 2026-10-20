<?php

use App\Enums\DutiesTerms;
use App\Enums\RecipientTaxIdType;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\DataSource;
use App\Models\DataSourceLocation;
use App\Models\Location;
use App\Models\Product;
use App\Models\Shipment;
use App\Services\ClientContext;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\ShopifyGoodsFingerprint;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/**
 * A Shopify connection importing fulfillment orders, with `$settings` merged
 * over the defaults and its one Shopify location mapped.
 *
 * @param  array<string, mixed>  $settings
 */
function customsFieldsShopifySource(array $settings = []): DataSource
{
    Cache::put('shopify_access_token_'.md5('test-shop.myshopify.com'), 'shpat_test_token', 3600);

    tap(Channel::factory()->create(['name' => 'Shopify']), fn ($channel) => ChannelAlias::firstOrCreate([
        'reference' => 'Shopify',
    ], ['channel_id' => $channel->id]));

    $dataSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify',
        'settings' => array_merge([
            'channel_name' => 'Shopify',
            'shop_domain' => 'test-shop.myshopify.com',
            'on_existing' => 'update_if_changed',
        ], $settings),
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
        ],
    ]);

    DataSourceLocation::factory()->create([
        'data_source_id' => $dataSource,
        'external_id' => 'gid://shopify/Location/1',
        'location_id' => Location::factory()->create(),
    ]);

    return $dataSource;
}

/**
 * An open fulfillment order with one line item whose variant is `$variant`.
 *
 * @param  array<string, mixed>  $variant
 * @return array<string, mixed>
 */
function customsFieldsFulfillmentOrder(array $variant = []): array
{
    return [
        'id' => 'gid://shopify/FulfillmentOrder/1001',
        'status' => 'OPEN',
        'order' => ['id' => 'gid://shopify/Order/1001', 'name' => '#1001', 'email' => 'test@example.com'],
        'destination' => [
            'firstName' => 'Jane', 'lastName' => 'Smith', 'address1' => 'Hauptstrasse 1',
            'city' => 'Berlin', 'province' => null, 'zip' => '10115', 'countryCode' => 'DE',
        ],
        'assignedLocation' => [
            'name' => 'Warehouse',
            'location' => [
                'id' => 'gid://shopify/Location/1',
                'name' => 'Warehouse',
                'isActive' => true,
                'address' => ['city' => 'Seattle', 'countryCode' => 'US'],
            ],
        ],
        'lineItems' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [[
                'id' => 'gid://shopify/FulfillmentOrderLineItem/1001',
                'sku' => 'WIDGET-1', 'productTitle' => 'Widget', 'remainingQuantity' => 2,
                'requiresShipping' => true, 'weight' => null,
                'variant' => array_merge(['id' => 'gid://shopify/ProductVariant/1', 'barcodes' => ['nodes' => []]], $variant),
                'lineItem' => ['originalUnitPriceSet' => ['shopMoney' => ['amount' => '10.00']]],
            ]],
        ],
    ];
}

/**
 * Import `$fulfillmentOrder` through `$dataSource`, returning the bodies of
 * the GraphQL requests other than the scope check.
 *
 * @param  array<string, mixed>  $fulfillmentOrder
 * @return list<array{query: string, variables: array<string, mixed>}>
 */
function importCustomsFieldsFulfillmentOrder(DataSource $dataSource, array $fulfillmentOrder): array
{
    $requests = [];

    Saloon::fake([GraphQL::class => function (PendingRequest $pendingRequest) use (&$requests, $fulfillmentOrder): MockResponse {
        $body = $pendingRequest->body()?->all() ?? [];

        if (str_contains((string) ($body['query'] ?? ''), 'currentAppInstallation')) {
            return shopifyAccessScopesResponse();
        }

        $requests[] = ['query' => (string) $body['query'], 'variables' => $body['variables'] ?? []];

        return MockResponse::make(['data' => ['fulfillmentOrders' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [$fulfillmentOrder],
        ]]]);
    }]);

    // Every run reads every open fulfillment order, as a first run does,
    // rather than searching for what changed since the last.
    DataSource::query()->whereKey($dataSource->id)->update(['import_updated_through' => null]);

    $result = ShipmentImportService::forRecord($dataSource)->import();

    expect($result->errors)->toBe([]);

    return $requests;
}

function importedWidget(): Product
{
    return Product::where('sku', 'WIDGET-1')->firstOrFail();
}

describe('part number', function (): void {
    it('reads a variant metafield on the variant only', function (): void {
        $dataSource = customsFieldsShopifySource([
            'part_number_metafield' => ['owner' => 'variant', 'namespace' => 'custom', 'key' => 'mpn'],
        ]);

        $requests = importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder([
            'partNumber' => ['jsonValue' => '  MPN-123  '],
        ]));

        expect(importedWidget()->manufacturer_part_number)->toBe('MPN-123')
            ->and($requests[0]['query'])->toContain('partNumber: metafield(namespace: $pnNamespace, key: $pnKey)')
            ->and($requests[0]['query'])->not->toContain('product {')
            ->and($requests[0]['variables'])->toMatchArray(['pnNamespace' => 'custom', 'pnKey' => 'mpn']);
    });

    it('reads a product metafield on the product only', function (): void {
        $dataSource = customsFieldsShopifySource([
            'part_number_metafield' => ['owner' => 'product', 'namespace' => 'google', 'key' => 'mpn'],
        ]);

        $requests = importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder([
            // A variant value is not read when the setting names the product.
            'partNumber' => ['jsonValue' => 'VARIANT-VALUE'],
            'product' => ['partNumber' => ['jsonValue' => 'PRODUCT-MPN']],
        ]));

        expect(importedWidget()->manufacturer_part_number)->toBe('PRODUCT-MPN')
            ->and($requests[0]['query'])->toContain('product { partNumber: metafield(namespace: $pnNamespace, key: $pnKey) { jsonValue } }')
            ->and(substr_count($requests[0]['query'], 'metafield('))->toBe(1)
            ->and($requests[0]['variables'])->toMatchArray(['pnNamespace' => 'google', 'pnKey' => 'mpn']);
    });

    it('takes the first entry of a list metafield', function (): void {
        $dataSource = customsFieldsShopifySource([
            'part_number_metafield' => ['owner' => 'variant', 'namespace' => 'custom', 'key' => 'mpns'],
        ]);

        importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder([
            'partNumber' => ['jsonValue' => ['FIRST', 'SECOND']],
        ]));

        expect(importedWidget()->manufacturer_part_number)->toBe('FIRST');
    });

    it('reads an integer metafield as text', function (): void {
        $dataSource = customsFieldsShopifySource([
            'part_number_metafield' => ['owner' => 'variant', 'namespace' => 'custom', 'key' => 'mpn'],
        ]);

        importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder([
            'partNumber' => ['jsonValue' => 4711],
        ]));

        expect(importedWidget()->manufacturer_part_number)->toBe('4711');
    });

    it('cuts a value over 100 characters', function (): void {
        $dataSource = customsFieldsShopifySource([
            'part_number_metafield' => ['owner' => 'variant', 'namespace' => 'custom', 'key' => 'mpn'],
        ]);

        importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder([
            'partNumber' => ['jsonValue' => str_repeat('é', 120)],
        ]));

        expect(importedWidget()->manufacturer_part_number)->toBe(str_repeat('é', 100));
    });

    it('selects no metafield and sends no variables for it without the setting', function (?array $setting): void {
        $dataSource = customsFieldsShopifySource(['part_number_metafield' => $setting]);

        $requests = importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder());

        expect($requests[0]['query'])->not->toContain('metafield')
            ->and($requests[0]['query'])->not->toContain('$pn')
            ->and($requests[0]['query'])->not->toContain('{{')
            ->and($requests[0]['variables'])->not->toHaveKeys(['pnNamespace', 'pnKey']);
    })->with([
        'absent' => [null],
        'cleared' => [[]],
    ]);

    it('keeps the namespace and key out of the query text', function (): void {
        $dataSource = customsFieldsShopifySource([
            'part_number_metafield' => ['owner' => 'variant', 'namespace' => '$app:mpn-data', 'key' => 'part_no'],
        ]);

        $requests = importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder());

        expect($requests[0]['query'])->not->toContain('mpn-data')
            ->and($requests[0]['query'])->not->toContain('part_no')
            ->and($requests[0]['variables'])->toMatchArray(['pnNamespace' => '$app:mpn-data', 'pnKey' => 'part_no']);
    });

    it('cannot alter the query with quotes or braces in a stored setting', function (): void {
        $dataSource = customsFieldsShopifySource([
            'part_number_metafield' => ['owner' => 'variant', 'namespace' => 'custom", key: "x") { id } shop { name', 'key' => 'mpn"}'],
        ]);

        $requests = importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder());

        // A setting the form could never have saved is not read at all.
        expect($requests[0]['query'])->not->toContain('shop {')
            ->and($requests[0]['query'])->not->toContain('metafield')
            ->and($requests[0]['variables'])->not->toHaveKeys(['pnNamespace', 'pnKey']);
    });

    it('leaves a hand-entered part number alone when the metafield is empty', function (): void {
        $dataSource = customsFieldsShopifySource([
            'part_number_metafield' => ['owner' => 'variant', 'namespace' => 'custom', 'key' => 'mpn'],
        ]);
        Product::factory()->create([
            'client_id' => app(ClientContext::class)->default()->id,
            'sku' => 'WIDGET-1',
            'manufacturer_part_number' => 'TYPED-BY-HAND',
        ]);

        importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder([
            'partNumber' => ['jsonValue' => '   '],
        ]));

        expect(importedWidget()->manufacturer_part_number)->toBe('TYPED-BY-HAND');
    });

    it('overwrites a hand-entered part number with the metafield value', function (): void {
        $dataSource = customsFieldsShopifySource([
            'part_number_metafield' => ['owner' => 'variant', 'namespace' => 'custom', 'key' => 'mpn'],
        ]);
        Product::factory()->create([
            'client_id' => app(ClientContext::class)->default()->id,
            'sku' => 'WIDGET-1',
            'manufacturer_part_number' => 'TYPED-BY-HAND',
        ]);

        importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder([
            'partNumber' => ['jsonValue' => 'FROM-SHOPIFY'],
        ]));

        expect(importedWidget()->manufacturer_part_number)->toBe('FROM-SHOPIFY');
    });
});

describe('HS code and origin', function (): void {
    it('imports both from the inventory item', function (): void {
        $requests = importCustomsFieldsFulfillmentOrder(customsFieldsShopifySource(), customsFieldsFulfillmentOrder([
            'inventoryItem' => ['harmonizedSystemCode' => '610910', 'countryCodeOfOrigin' => 'PT'],
        ]));

        $product = importedWidget();

        expect($product->hs_tariff_number)->toBe('610910')
            ->and($product->country_of_origin)->toBe('PT')
            ->and($requests[0]['query'])->toContain('inventoryItem { harmonizedSystemCode countryCodeOfOrigin }');
    });

    it('leaves hand-entered values alone when Shopify has none', function (): void {
        Product::factory()->create([
            'client_id' => app(ClientContext::class)->default()->id,
            'sku' => 'WIDGET-1',
            'hs_tariff_number' => '620520',
            'country_of_origin' => 'VN',
        ]);

        importCustomsFieldsFulfillmentOrder(customsFieldsShopifySource(), customsFieldsFulfillmentOrder([
            'inventoryItem' => ['harmonizedSystemCode' => '', 'countryCodeOfOrigin' => null],
        ]));

        $product = importedWidget();

        expect($product->hs_tariff_number)->toBe('620520')
            ->and($product->country_of_origin)->toBe('VN');
    });
});

it('updates an open shipment and its product once on re-import, keeping the goods fingerprint', function (): void {
    $dataSource = customsFieldsShopifySource([
        'part_number_metafield' => ['owner' => 'variant', 'namespace' => 'custom', 'key' => 'mpn'],
    ]);

    // As imported before Shopify's customs fields were read.
    importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder());
    $shipment = Shipment::where('source_record_id', 'gid://shopify/FulfillmentOrder/1001')->firstOrFail();
    $fingerprint = $shipment->metadata[ShopifyGoodsFingerprint::METADATA_KEY];
    $checksum = $shipment->source_checksum;

    $withCustomsFields = customsFieldsFulfillmentOrder([
        'inventoryItem' => ['harmonizedSystemCode' => '610910', 'countryCodeOfOrigin' => 'PT'],
        'partNumber' => ['jsonValue' => 'MPN-123'],
    ]);

    importCustomsFieldsFulfillmentOrder($dataSource, $withCustomsFields);
    $product = importedWidget();
    $shipment->refresh();

    expect($product->manufacturer_part_number)->toBe('MPN-123')
        ->and($product->hs_tariff_number)->toBe('610910')
        ->and($product->country_of_origin)->toBe('PT')
        ->and($shipment->source_checksum)->not->toBe($checksum)
        ->and($shipment->metadata[ShopifyGoodsFingerprint::METADATA_KEY])->toBe($fingerprint);

    $this->travel(1)->minutes();
    importCustomsFieldsFulfillmentOrder($dataSource, $withCustomsFields);

    expect(importedWidget()->updated_at->equalTo($product->updated_at))->toBeTrue()
        ->and(Shipment::count())->toBe(1);
});

/**
 * The imported Shipment for an order carrying `$orderFields` over the usual one.
 *
 * @param  array<string, mixed>  $orderFields
 */
function importedShipmentForOrder(array $orderFields, ?DataSource $dataSource = null): Shipment
{
    $fulfillmentOrder = customsFieldsFulfillmentOrder();
    $fulfillmentOrder['order'] = array_merge($fulfillmentOrder['order'], $orderFields);

    importCustomsFieldsFulfillmentOrder($dataSource ?? customsFieldsShopifySource(), $fulfillmentOrder);

    return Shipment::where('shipment_reference', '#1001')->firstOrFail();
}

describe('duties terms and recipient tax ID', function (): void {
    it('ships DDP when Shopify charged duties, whatever dutiesIncluded says', function (): void {
        $shipment = importedShipmentForOrder([
            'dutiesIncluded' => false,
            'originalTotalDutiesSet' => ['shopMoney' => ['amount' => '3.36']],
        ]);

        expect($shipment->duties_terms)->toBe(DutiesTerms::Ddp);
    });

    it('leaves the terms null when the order has no duties, and never says DDU', function (?array $duties): void {
        $shipment = importedShipmentForOrder(['originalTotalDutiesSet' => $duties]);

        expect($shipment->duties_terms)->toBeNull();
    })->with([
        'null, as Shopify returns it' => [null],
        'zero' => [['shopMoney' => ['amount' => '0.0']]],
    ]);

    it('keeps terms a manager set when a later import sees no duties', function (): void {
        $dataSource = customsFieldsShopifySource();
        importedShipmentForOrder([], $dataSource)->update(['duties_terms' => DutiesTerms::Ddu]);

        expect(importedShipmentForOrder([], $dataSource)->duties_terms)->toBe(DutiesTerms::Ddu);
    });

    it('imports a Brazilian CPF and strips its punctuation', function (): void {
        $shipment = importedShipmentForOrder(['localizedFields' => ['nodes' => [
            ['key' => 'SHIPPING_CREDENTIAL_BR', 'value' => '007.408.869-67'],
        ]]]);

        expect($shipment->recipient_tax_id_type)->toBe(RecipientTaxIdType::Cpf)
            ->and($shipment->recipient_tax_id)->toBe('00740886967');
    });

    it('tells a CNPJ from a CPF by its length', function (): void {
        $shipment = importedShipmentForOrder(['localizedFields' => ['nodes' => [
            ['key' => 'SHIPPING_CREDENTIAL_BR', 'value' => '11.222.333/0001-81'],
        ]]]);

        expect($shipment->recipient_tax_id_type)->toBe(RecipientTaxIdType::Cnpj)
            ->and($shipment->recipient_tax_id)->toBe('11222333000181');
    });

    it('imports a Korean PCCC', function (): void {
        $shipment = importedShipmentForOrder(['localizedFields' => ['nodes' => [
            ['key' => 'SHIPPING_CREDENTIAL_KR', 'value' => 'P123456789012'],
        ]]]);

        expect($shipment->recipient_tax_id_type)->toBe(RecipientTaxIdType::Pccc)
            ->and($shipment->recipient_tax_id)->toBe('P123456789012');
    });

    it('reports a malformed ID instead of storing it', function (): void {
        $shipment = importedShipmentForOrder(['localizedFields' => ['nodes' => [
            ['key' => 'SHIPPING_CREDENTIAL_BR', 'value' => '123.456.789-00'],
        ]]]);

        expect($shipment->recipient_tax_id)->toBeNull()
            ->and($shipment->recipient_tax_id_type)->toBeNull();
    });

    it('ignores credentials for other countries and leaves the ID alone', function (): void {
        $shipment = importedShipmentForOrder(['localizedFields' => ['nodes' => [
            ['key' => 'TAX_CREDENTIAL_IT', 'value' => 'RSSMRA80A01H501U'],
        ]]]);

        expect($shipment->recipient_tax_id)->toBeNull();
    });

    it('asks Shopify for the duties and localized fields', function (): void {
        $dataSource = customsFieldsShopifySource();
        $fulfillmentOrder = customsFieldsFulfillmentOrder();

        $requests = importCustomsFieldsFulfillmentOrder($dataSource, $fulfillmentOrder);

        expect($requests[0]['query'])->toContain('originalTotalDutiesSet { shopMoney { amount } }')
            ->and($requests[0]['query'])->toContain('localizedFields(first: 10) { nodes { key value } }');
    });
});

describe('typed barcodes', function (): void {
    function typedBarcodes(array ...$barcodes): array
    {
        return ['barcodes' => ['nodes' => array_map(
            fn (array $barcode): array => ['type' => $barcode[0], 'value' => $barcode[1]],
            $barcodes,
        )]];
    }

    it('imports the EAN as barcode and gtin and the NS_PID as part number', function (): void {
        $requests = importCustomsFieldsFulfillmentOrder(customsFieldsShopifySource(), customsFieldsFulfillmentOrder(
            typedBarcodes(['EAN', '4006381333931'], ['NS_PID', 'MPN-9']),
        ));

        expect(importedWidget()->only(['barcode', 'gtin', 'manufacturer_part_number']))
            ->toBe(['barcode' => '4006381333931', 'gtin' => '4006381333931', 'manufacturer_part_number' => 'MPN-9'])
            ->and($requests[0]['query'])->toContain('barcodes(first: 5) { nodes { type value } }')
            ->and($requests[0]['query'])->not->toContain('metafield');
    });

    it('does not scan the part number when the NS_PID is listed first', function (): void {
        importCustomsFieldsFulfillmentOrder(customsFieldsShopifySource(), customsFieldsFulfillmentOrder(
            typedBarcodes(['NS_PID', 'MPN-9'], ['EAN', '4006381333931']),
        ));

        expect(importedWidget()->only(['barcode', 'manufacturer_part_number']))->toBe(['barcode' => '4006381333931', 'manufacturer_part_number' => 'MPN-9']);
    });

    it('prefers the NS_PID over the metafield and falls back to it', function (): void {
        $dataSource = customsFieldsShopifySource([
            'part_number_metafield' => ['owner' => 'variant', 'namespace' => 'custom', 'key' => 'mpn'],
        ]);

        importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder([
            'partNumber' => ['jsonValue' => 'META-MPN'],
            ...typedBarcodes(['NS_PID', 'TYPED-MPN']),
        ]));
        expect(importedWidget()->manufacturer_part_number)->toBe('TYPED-MPN');

        importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder([
            'partNumber' => ['jsonValue' => 'META-MPN'],
            ...typedBarcodes(['EAN', '4006381333931']),
        ]));
        expect(importedWidget()->manufacturer_part_number)->toBe('META-MPN');
    });

    it('fills gtin only from a valid GTIN-family type', function (): void {
        importCustomsFieldsFulfillmentOrder(customsFieldsShopifySource(), customsFieldsFulfillmentOrder(
            typedBarcodes(['EAN', '4006381333932'], ['ASIN', 'B000000000'], [null, '036000291452']),
        ));

        expect(importedWidget()->only(['gtin', 'barcode']))->toBe(['gtin' => null, 'barcode' => '4006381333932']);
    });

    it('fills barcode only from an untyped valid GTIN', function (): void {
        importCustomsFieldsFulfillmentOrder(customsFieldsShopifySource(), customsFieldsFulfillmentOrder(
            typedBarcodes([null, '036000291452']),
        ));

        expect(importedWidget()->only(['barcode', 'gtin']))->toBe(['barcode' => '036000291452', 'gtin' => null]);
    });

    it('leaves the barcode alone when the only barcode is an NS_PID', function (): void {
        Product::factory()->create(['sku' => 'WIDGET-1', 'barcode' => 'KEEP-ME']);

        importCustomsFieldsFulfillmentOrder(customsFieldsShopifySource(), customsFieldsFulfillmentOrder(
            typedBarcodes(['NS_PID', 'MPN-9']),
        ));

        expect(importedWidget()->only(['barcode', 'manufacturer_part_number']))->toBe(['barcode' => 'KEEP-ME', 'manufacturer_part_number' => 'MPN-9']);
    });
});

describe('ship by', function (): void {
    it('reads fulfillBy as a date in the default location timezone', function (): void {
        Location::factory()->create(['is_default' => true, 'timezone' => 'America/New_York']);
        Location::clearDefaultCache();

        $dataSource = customsFieldsShopifySource();
        $fulfillmentOrder = customsFieldsFulfillmentOrder() + ['fulfillBy' => '2026-10-22T02:30:00Z'];

        $requests = importCustomsFieldsFulfillmentOrder($dataSource, $fulfillmentOrder);

        expect($requests[0]['query'])->toContain('fulfillBy')
            ->and(Shipment::firstOrFail()->ship_by->toDateString())->toBe('2026-10-21');
    });

    it('leaves ship_by null when no fulfillBy is set, and clears it when removed', function (): void {
        $dataSource = customsFieldsShopifySource();

        importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder() + ['fulfillBy' => '2026-10-22T12:00:00Z']);
        expect(Shipment::firstOrFail()->ship_by)->not->toBeNull();

        importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder() + ['fulfillBy' => null]);
        expect(Shipment::firstOrFail()->ship_by)->toBeNull();
    });
});

describe('deliver by', function (): void {
    it('reads the delivery method maxDeliveryDateTime as a date in the default location timezone', function (): void {
        Location::factory()->create(['is_default' => true, 'timezone' => 'America/New_York']);
        Location::clearDefaultCache();

        $fulfillmentOrder = customsFieldsFulfillmentOrder() + [
            'deliveryMethod' => ['maxDeliveryDateTime' => '2026-10-27T03:00:00Z'],
        ];

        $requests = importCustomsFieldsFulfillmentOrder(customsFieldsShopifySource(), $fulfillmentOrder);

        expect($requests[0]['query'])->toContain('maxDeliveryDateTime')
            ->and(Shipment::firstOrFail()->deliver_by->toDateString())->toBe('2026-10-26');
    });

    it('leaves deliver_by null for a merchant-defined rate with no estimate or no delivery method', function (): void {
        $dataSource = customsFieldsShopifySource();

        importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder() + [
            'deliveryMethod' => ['maxDeliveryDateTime' => null],
        ]);
        expect(Shipment::firstOrFail()->deliver_by)->toBeNull();

        importCustomsFieldsFulfillmentOrder($dataSource, customsFieldsFulfillmentOrder() + ['deliveryMethod' => null]);
        expect(Shipment::firstOrFail()->deliver_by)->toBeNull();
    });
});
