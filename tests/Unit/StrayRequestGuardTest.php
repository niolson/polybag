<?php

use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Http\Integrations\Shopify\ShopifyConnector;
use Illuminate\Http\Client\StrayRequestException as HttpStrayRequestException;
use Illuminate\Support\Facades\Http;
use Saloon\Exceptions\StrayRequestException;
use Saloon\Http\Response;
use Tests\TestCase;

/**
 * `order-reconciliation/06`: no test may reach a live API. Every Shopify
 * import now ends with a lookup, and a test that did not mock it must fail.
 */
it('refuses an HTTP client request nothing faked', function (): void {
    Http::get('https://example.com');
})->throws(HttpStrayRequestException::class);

it('refuses a Saloon request with no mock client, and remembers it for teardown even when caught', function (): void {
    $straySaloonRequest = new ReflectionProperty(TestCase::class, 'straySaloonRequest');

    expect(fn (): Response => (new ShopifyConnector('test-shop.myshopify.com', 'id', 'secret', accessToken: 'shpat_test'))
        ->send(new GraphQL('{ shop { id } }')))
        ->toThrow(StrayRequestException::class)
        // What teardown fails the test with, as an import that catches the
        // exception would otherwise pass it.
        ->and($straySaloonRequest->getValue())->toContain('test-shop.myshopify.com');

    // This test sent it on purpose.
    $straySaloonRequest->setValue(null, null);
});
