<?php

use App\Enums\VoidReason;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Models\Package;
use App\Services\ShopifyLabelCostRecorder;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->recorder = app(ShopifyLabelCostRecorder::class);
});

/**
 * A Shopify-bought package whose shipment knows its order.
 */
function costlessShopifyPackage(array $attributes = []): Package
{
    $package = shippedShopifyPackage($attributes);

    $package->shipment->update(['metadata' => array_merge($package->shipment->metadata ?? [], [
        'shopify_order_id' => 'gid://shopify/Order/500',
    ])]);

    return $package->refresh();
}

/**
 * One `shipping_label_created_success` event, shaped as the Admin API returns it.
 *
 * @return array<string, mixed>
 */
function labelPurchaseEvent(
    string $message = 'PolyBag purchased a shipping label for $5.97.',
    int $labelId = 1,
    ?string $trackingNumber = '9400111899223197428490',
): array {
    $pairs = [[
        'type' => 'key_value_pair',
        'key' => [['type' => 'text', 'content' => 'Carrier', 'options' => []]],
        'value' => [['type' => 'text', 'content' => 'USPS Ground Advantage', 'options' => []]],
        'options' => [],
    ]];

    if ($trackingNumber !== null) {
        $pairs[] = [
            'type' => 'key_value_pair',
            'key' => [['type' => 'text', 'content' => 'Tracking number', 'options' => []]],
            'value' => [['type' => 'text', 'content' => $trackingNumber, 'options' => []]],
            'options' => [],
        ];
    }

    return [
        'action' => 'shipping_label_created_success',
        'message' => $message,
        'arguments' => [$labelId, 'shipping_label_1001.pdf', 'api_client_id', 9],
        // A JSON string, not an object: that is how the field comes back.
        'additionalContent' => json_encode(['root_component' => [
            'type' => 'root_component',
            'content' => [['type' => 'key_value_list', 'content' => $pairs, 'options' => []]],
        ]]),
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $events
 */
function labelEventsResponse(array $events, string $currency = 'USD', string $moneyFormat = '${{amount}}'): MockResponse
{
    return MockResponse::make(['data' => [
        'shop' => ['currencyCode' => $currency, 'currencyFormats' => ['moneyFormat' => $moneyFormat]],
        'order' => ['events' => ['nodes' => $events]],
    ]]);
}

it('records the timeline price on the label and the package', function (): void {
    $package = costlessShopifyPackage();

    Saloon::fake([labelEventsResponse([labelPurchaseEvent()])]);

    $result = $this->recorder->sync();

    expect($result)->toMatchArray(['checked' => 1, 'costed' => 1, 'failed' => 0])
        ->and($package->labels()->sole()->cost)->toBe('5.97')
        ->and($package->refresh()->cost)->toBe('5.97');
});

it('asks for the timeline in English, whatever the shop speaks', function (): void {
    costlessShopifyPackage();

    Saloon::fake([labelEventsResponse([labelPurchaseEvent()])]);

    $this->recorder->sync();

    Saloon::assertSent(fn (GraphQL $request): bool => $request->headers()->get('Accept-Language') === 'en');
});

it('matches the event to the label by ID, not by order', function (): void {
    $package = costlessShopifyPackage();

    // Another purchase on the same order — a voided earlier label, or a
    // second package — must not lend this one its price.
    Saloon::fake([labelEventsResponse([
        labelPurchaseEvent('PolyBag purchased a shipping label for $20.94.', 2, '1Z0000000000000000'),
        labelPurchaseEvent('PolyBag purchased a shipping label for $5.97.', 1),
    ])]);

    $this->recorder->sync();

    expect($package->refresh()->cost)->toBe('5.97');
});

it('leaves the cost null when no event names the label', function (): void {
    $package = costlessShopifyPackage();

    Saloon::fake([labelEventsResponse([labelPurchaseEvent(labelId: 2)])]);

    $result = $this->recorder->sync();

    expect($result['costed'])->toBe(0)
        ->and($package->refresh()->cost)->toBeNull();
});

it('refuses a match whose event names a different tracking number', function (): void {
    $package = costlessShopifyPackage();

    Saloon::fake([labelEventsResponse([labelPurchaseEvent(trackingNumber: '9400100000000000000000')])]);

    $this->recorder->sync();

    expect($package->refresh()->cost)->toBeNull();
});

it('records nothing for a label priced in another currency', function (): void {
    $package = costlessShopifyPackage();

    Saloon::fake([labelEventsResponse([labelPurchaseEvent('PolyBag purchased a shipping label for £2.24 GBP.')])]);

    $this->recorder->sync();

    expect($package->refresh()->cost)->toBeNull();
});

it('records nothing for a bare amount in a shop that does not bill in USD', function (): void {
    $package = costlessShopifyPackage();

    // A CAD shop prints its own currency bare, with the same glyph.
    Saloon::fake([labelEventsResponse([labelPurchaseEvent()], 'CAD', '${{amount}}')]);

    $this->recorder->sync();

    expect($package->refresh()->cost)->toBeNull();
});

it('puts a voided label\'s cost on the label but not on the package', function (): void {
    $package = costlessShopifyPackage();
    $package->clearShipping(VoidReason::VoidedUpstream);

    Saloon::fake([labelEventsResponse([labelPurchaseEvent()])]);

    $this->recorder->sync();

    expect($package->labels()->sole()->cost)->toBe('5.97')
        ->and($package->refresh()->cost)->toBeNull();
});

it('never overwrites a cost already recorded', function (): void {
    $package = costlessShopifyPackage(['cost' => 7.5]);

    Saloon::fake([]);

    $result = $this->recorder->sync();

    expect($result['checked'])->toBe(0)
        ->and($package->refresh()->cost)->toBe('7.50');
});

it('stops asking about labels older than the window', function (): void {
    costlessShopifyPackage(['shipped_at' => now()->subDays(8)]);

    Saloon::fake([]);

    expect($this->recorder->candidates())->toBeEmpty()
        ->and($this->recorder->candidates(days: 10))->toHaveCount(1);
});

it('does not use the credentials of a disabled connection', function (): void {
    $package = costlessShopifyPackage();
    $package->postageDataSource->update(['active' => false]);

    Saloon::fake([]);

    expect($this->recorder->sync()['checked'])->toBe(0);

    Saloon::assertNothingSent();
});

it('counts a failed lookup without touching the package', function (): void {
    $package = costlessShopifyPackage();

    Saloon::fake([MockResponse::make(['errors' => [['message' => 'Throttled']]])]);

    $result = $this->recorder->sync();

    expect($result)->toMatchArray(['checked' => 1, 'costed' => 0, 'failed' => 1])
        ->and($package->refresh()->cost)->toBeNull();
});

it('parses the sentence Shopify writes', function (string $message, string $currency, string $format, ?array $expected): void {
    expect($this->recorder->priceFrom($message, $currency, $format))->toBe($expected);
})->with([
    'bare, shop currency' => ['PolyBag purchased a shipping label for $5.68.', 'USD', '${{amount}}', ['amount' => '5.68', 'currency' => 'USD']],
    'thousands separator' => ['PolyBag purchased a shipping label for $1,204.50.', 'USD', '${{amount}}', ['amount' => '1204.50', 'currency' => 'USD']],
    'coded, other currency' => ['PolyBag purchased a shipping label for £2.24 GBP.', 'USD', '${{amount}}', ['amount' => '2.24', 'currency' => 'GBP']],
    'coded USD in a CAD shop' => ['PolyBag purchased a shipping label for $5.68 USD.', 'CAD', '${{amount}}', ['amount' => '5.68', 'currency' => 'USD']],
    'a person bought it' => ['A Staff Member purchased a shipping label for $5.69.', 'USD', '${{amount}}', ['amount' => '5.69', 'currency' => 'USD']],
    'glyph disagrees with the shop format' => ['PolyBag purchased a shipping label for €5.68.', 'USD', '${{amount}}', null],
    'shop format not plain' => ['PolyBag purchased a shipping label for $5.68.', 'USD', '<span class=money>${{amount}}</span>', null],
    'another language' => ['PolyBag a acheté une étiquette d’expédition de 2,24 £ GBP.', 'USD', '${{amount}}', null],
    'a void, not a purchase' => ['A Staff Member voided a $5.97 shipping label.', 'USD', '${{amount}}', null],
    'no decimals' => ['PolyBag purchased a shipping label for $6.', 'USD', '${{amount}}', null],
]);

it('runs from the command', function (): void {
    $package = costlessShopifyPackage();

    Saloon::fake([labelEventsResponse([labelPurchaseEvent()])]);

    $this->artisan('packages:sync-shopify-label-costs')
        ->expectsOutputToContain('Checked 1 label(s): 1 costed, 0 failed.')
        ->assertSuccessful();

    expect($package->refresh()->cost)->toBe('5.97');
});
