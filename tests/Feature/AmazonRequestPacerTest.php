<?php

use App\Http\Integrations\Amazon\AmazonRequestPacer;
use App\Http\Integrations\Amazon\AmazonSpApiConnector;
use App\Http\Integrations\Amazon\Requests\SearchCatalogItems;
use App\Http\Integrations\Amazon\Requests\SearchListingsItems;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * A pacer whose requests each take `$latencyMilliseconds`, answered at
 * `$rateLimit` a second.
 */
function pacerWithLatency(int $latencyMilliseconds, string $rateLimit = '5.0'): AmazonRequestPacer
{
    Cache::put('amazon_sp_api_access_token_'.md5('synthetic-refresh-token'), 'synthetic-access-token', 3600);

    $answer = function () use ($latencyMilliseconds, $rateLimit): MockResponse {
        Carbon::setTestNow(now()->addMilliseconds($latencyMilliseconds));

        return MockResponse::make(['items' => []], 200, ['x-amzn-RateLimit-Limit' => $rateLimit]);
    };

    Saloon::fake([SearchListingsItems::class => $answer, SearchCatalogItems::class => $answer]);

    return new AmazonRequestPacer(AmazonSpApiConnector::fromSettings(['refresh_token' => 'synthetic-refresh-token']));
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-28 00:00:00.000');
    Sleep::fake();
});

it('sends the next request at once when the last one took longer than the interval', function (): void {
    $pacer = pacerWithLatency(300);

    $pacer->send(new SearchListingsItems('A0SYNTHSELLER1'), SearchListingsItems::DEFAULT_RATE_LIMIT);
    $pacer->send(new SearchListingsItems('A0SYNTHSELLER1'), SearchListingsItems::DEFAULT_RATE_LIMIT);

    Sleep::assertNeverSlept();
});

it('waits only what is left of the interval since the last request started', function (): void {
    $pacer = pacerWithLatency(50);

    $pacer->send(new SearchListingsItems('A0SYNTHSELLER1'), SearchListingsItems::DEFAULT_RATE_LIMIT);
    $pacer->send(new SearchListingsItems('A0SYNTHSELLER1'), SearchListingsItems::DEFAULT_RATE_LIMIT);

    Sleep::assertSequence([Sleep::for(150)->milliseconds()]);
});

it('paces each operation on its own', function (): void {
    $pacer = pacerWithLatency(0);

    $pacer->send(new SearchListingsItems('A0SYNTHSELLER1'), SearchListingsItems::DEFAULT_RATE_LIMIT);
    $pacer->send(new SearchCatalogItems, SearchCatalogItems::DEFAULT_RATE_LIMIT);

    Sleep::assertNeverSlept();
});
