<?php

use App\Enums\OffAmazonShippingStatus;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Models\CarrierAccountScope;
use App\Models\DataSource;
use App\Models\Location;
use App\Services\PostageSources\OffAmazonShippingCheck;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/**
 * `amazon-shipping-external-orders/04`: whether an Amazon connection's account
 * can ship orders from other channels, asked with a free production `EXTERNAL`
 * `getRates` and recorded on the connection.
 */
beforeEach(function (): void {
    Cache::put('amazon_sp_api_access_token_'.md5('check-refresh-token'), 'check-access-token', 3600);

    $this->connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create([
        'off_amazon_shipping_status' => null,
        'off_amazon_shipping_checked_at' => null,
        'secret_settings' => ['refresh_token' => 'check-refresh-token'],
    ]);
    $this->location = Location::factory()->create(['is_default' => true]);
});

function a101Response(): MockResponse
{
    return MockResponse::make(['errors' => [[
        'code' => 'Unauthorized',
        'message' => 'Access to requested resource is denied.',
        'details' => 'Access denied for this account. Please contact support. (A-101)',
    ]]], 403);
}

it('records enabled when Amazon quotes the account', function (): void {
    Saloon::fake([GetShippingRates::class => MockResponse::make(['payload' => ['rates' => []]], 200)]);

    $result = app(OffAmazonShippingCheck::class)->check($this->connection);

    expect($result->status)->toBe(OffAmazonShippingStatus::Enabled)
        ->and($this->connection->refresh()->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::Enabled)
        ->and($this->connection->off_amazon_shipping_checked_at)->not->toBeNull();
});

it('records not set up, with a sign-up warning, for a 403 A-101', function (): void {
    Saloon::fake([GetShippingRates::class => a101Response()]);

    $result = app(OffAmazonShippingCheck::class)->check($this->connection);

    expect($result->status)->toBe(OffAmazonShippingStatus::NotSetUp)
        ->and($result->message)->toContain('Amazon Shipping sign-up in Seller Central')
        ->and($this->connection->refresh()->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::NotSetUp);
});

it('records unknown for any other refusal', function (int $status): void {
    Saloon::fake([GetShippingRates::class => MockResponse::make(['errors' => [['code' => 'InvalidInput', 'message' => 'Bad']]], $status)]);

    expect(app(OffAmazonShippingCheck::class)->check($this->connection)->status)->toBe(OffAmazonShippingStatus::Unknown)
        ->and($this->connection->refresh()->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::Unknown);
})->with([400, 403, 500]);

it('records unknown when Amazon cannot be reached, once, with a short timeout', function (): void {
    $attempts = 0;
    $timeouts = [];

    Saloon::fake([GetShippingRates::class => function (PendingRequest $pending) use (&$attempts, &$timeouts): MockResponse {
        $attempts++;
        $timeouts = [$pending->config()->get('connect_timeout'), $pending->config()->get('timeout')];

        return MockResponse::make()->throw(new FatalRequestException(new RuntimeException('Connection timed out'), $pending));
    }]);

    $result = app(OffAmazonShippingCheck::class)->check($this->connection);

    expect($result->status)->toBe(OffAmazonShippingStatus::Unknown)
        ->and($attempts)->toBe(1)
        ->and($timeouts)->toBe([5, 10])
        ->and($this->connection->refresh()->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::Unknown);
});

it('sends a valid EXTERNAL body from a location the connection serves', function (): void {
    Saloon::fake([GetShippingRates::class => MockResponse::make(['payload' => ['rates' => []]], 200)]);

    app(OffAmazonShippingCheck::class)->check($this->connection);

    Saloon::assertSent(function (GetShippingRates $request): bool {
        $body = $request->body()->all();
        $package = $body['packages'][0];
        $itemsWeight = collect($package['items'])->sum(fn (array $item): float => $item['weight']['value'] * $item['quantity']);

        return $body['channelDetails'] === ['channelType' => 'EXTERNAL']
            && count($body['packages']) === 1
            && $itemsWeight > 0
            && $itemsWeight <= $package['weight']['value']
            && isset($package['insuredValue'])
            && $body['shipFrom']['addressLine1'] === $this->location->address1
            && $body['shipFrom']['postalCode'] === $this->location->postal_code
            && $body['shipTo']['postalCode'] === OffAmazonShippingCheck::SHIP_TO['postalCode'];
    });
});

it('ships from a location the connection is scoped to before any other', function (): void {
    $scoped = Location::factory()->create();
    CarrierAccountScope::create(['data_source_id' => $this->connection->id, 'location_id' => $scoped->id]);

    Saloon::fake([GetShippingRates::class => MockResponse::make(['payload' => ['rates' => []]], 200)]);

    app(OffAmazonShippingCheck::class)->check($this->connection);

    Saloon::assertSent(fn (GetShippingRates $request): bool => $request->body()->get('shipFrom')['addressLine1'] === $scoped->address1);
});

it('records unknown without calling Amazon in sandbox mode', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true);
    Saloon::fake([GetShippingRates::class => MockResponse::make([], 200)]);

    $result = app(OffAmazonShippingCheck::class)->check($this->connection);

    expect($result->status)->toBe(OffAmazonShippingStatus::Unknown)
        ->and($result->message)->toContain('sandbox mode')
        ->and($this->connection->refresh()->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::Unknown);
    Saloon::assertNothingSent();
});

it('records unknown without calling Amazon when no location has an address', function (): void {
    Location::query()->update(['address1' => '']);
    Saloon::fake([GetShippingRates::class => MockResponse::make([], 200)]);

    $result = app(OffAmazonShippingCheck::class)->check($this->connection);

    expect($result->status)->toBe(OffAmazonShippingStatus::Unknown)
        ->and($result->message)->toContain('no location has a complete address');
    Saloon::assertNothingSent();
});
