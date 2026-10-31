<?php

use App\Http\Integrations\Amazon\Requests\GetMarketplaceParticipations;
use App\Models\DataSource;
use App\Services\AmazonMarketplaceDiscoveryService;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function amazonMarketplaceParticipation(
    string $id,
    string $name,
    string $countryCode,
    bool $isParticipating = true,
    bool $hasSuspendedListings = false,
): array {
    return [
        'marketplace' => [
            'id' => $id,
            'name' => $name,
            'countryCode' => $countryCode,
        ],
        'participation' => [
            'isParticipating' => $isParticipating,
            'hasSuspendedListings' => $hasSuspendedListings,
        ],
    ];
}

/**
 * @param  list<string>  $marketplaceIds
 */
function amazonMarketplaceSource(array $marketplaceIds = []): DataSource
{
    return DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => array_filter([
            'auth_mode' => 'authorization_code',
            'marketplace_ids' => $marketplaceIds,
        ]),
        'secret_settings' => ['refresh_token' => 'marketplace-refresh-token'],
    ]);
}

beforeEach(function (): void {
    Cache::put('amazon_sp_api_access_token_'.md5('marketplace-refresh-token'), 'marketplace-access-token', 3600);
});

it('discovers and automatically selects a single supported Amazon marketplace', function (): void {
    $source = amazonMarketplaceSource();

    Saloon::fake([
        GetMarketplaceParticipations::class => MockResponse::make([
            'payload' => [
                amazonMarketplaceParticipation('ATVPDKIKX0DER', 'Amazon.com', 'US'),
            ],
        ]),
    ]);

    $result = app(AmazonMarketplaceDiscoveryService::class)->discover($source);

    $source->refresh();
    expect($result->succeeded)->toBeTrue()
        ->and($result->selectionRequired)->toBeFalse()
        ->and($result->selectedMarketplaceIds)->toBe(['ATVPDKIKX0DER'])
        ->and($source->settings['marketplace_ids'])->toBe(['ATVPDKIKX0DER'])
        ->and($source->settings['primary_marketplace_id'])->toBe('ATVPDKIKX0DER')
        ->and($source->settings['amazon_marketplaces'])->toHaveCount(1)
        ->and($source->settings['amazon_marketplaces_synced_at'])->not->toBeEmpty();

    Saloon::assertSent(fn (GetMarketplaceParticipations $request): bool => $request->resolveEndpoint() === '/sellers/v1/marketplaceParticipations');
});

it('preserves a valid selection when multiple marketplaces are discovered', function (): void {
    $source = amazonMarketplaceSource(['A2Q3Y263D00KWC']);

    Saloon::fake([
        GetMarketplaceParticipations::class => MockResponse::make([
            'payload' => [
                amazonMarketplaceParticipation('ATVPDKIKX0DER', 'Amazon.com', 'US', hasSuspendedListings: true),
                amazonMarketplaceParticipation('A2Q3Y263D00KWC', 'Amazon.com.br', 'BR', hasSuspendedListings: true),
            ],
        ]),
    ]);

    $result = app(AmazonMarketplaceDiscoveryService::class)->discover($source);

    $source->refresh();
    expect($result->selectionRequired)->toBeFalse()
        ->and($source->settings['marketplace_ids'])->toBe(['A2Q3Y263D00KWC'])
        ->and($source->settings['amazon_marketplaces'][0]['has_suspended_listings'])->toBeTrue()
        ->and($source->settings['amazon_marketplaces'][1]['has_suspended_listings'])->toBeTrue();
});

it('selects none when the seller has several marketplaces and none is chosen', function (): void {
    $source = amazonMarketplaceSource();

    Saloon::fake([
        GetMarketplaceParticipations::class => MockResponse::make([
            'payload' => [
                amazonMarketplaceParticipation('ATVPDKIKX0DER', 'Amazon.com', 'US'),
                amazonMarketplaceParticipation('A2EUQ1WTGCTBG2', 'Amazon.ca', 'CA'),
            ],
        ]),
    ]);

    $result = app(AmazonMarketplaceDiscoveryService::class)->discover($source);

    $source->refresh();
    expect($result->selectionRequired)->toBeTrue()
        ->and($result->selectedMarketplaceIds)->toBe([])
        ->and($source->settings)->not->toHaveKey('marketplace_ids')
        ->and($source->settings)->not->toHaveKey('primary_marketplace_id');
});

it('flags each selected marketplace discovery no longer reports, keeping them all', function (): void {
    $source = amazonMarketplaceSource(['A2EUQ1WTGCTBG2', 'ATVPDKIKX0DER', 'A1AM78C64UM0Y8']);

    Saloon::fake([
        GetMarketplaceParticipations::class => MockResponse::make([
            'payload' => [
                amazonMarketplaceParticipation('ATVPDKIKX0DER', 'Amazon.com', 'US'),
            ],
        ]),
    ]);

    $result = app(AmazonMarketplaceDiscoveryService::class)->discover($source);

    $source->refresh();
    expect($result->unavailableMarketplaceIds)->toBe(['A2EUQ1WTGCTBG2', 'A1AM78C64UM0Y8'])
        ->and($source->settings['marketplace_ids'])->toBe(['A2EUQ1WTGCTBG2', 'ATVPDKIKX0DER', 'A1AM78C64UM0Y8'])
        ->and($source->settings['primary_marketplace_id'])->toBe('ATVPDKIKX0DER');
});

it('filters internal marketplaces and preserves an existing selection omitted by discovery', function (): void {
    $source = amazonMarketplaceSource(['A1MQXOICRS2Z7M']);

    Saloon::fake([
        GetMarketplaceParticipations::class => MockResponse::make([
            'payload' => [
                amazonMarketplaceParticipation('A1MQXOICRS2Z7M', 'Non-Amazon CA', 'CA'),
                amazonMarketplaceParticipation('ATVPDKIKX0DER', 'Amazon.com', 'US'),
                amazonMarketplaceParticipation('A2Q3Y263D00KWC', 'Amazon.com.br', 'BR'),
            ],
        ]),
    ]);

    $result = app(AmazonMarketplaceDiscoveryService::class)->discover($source);

    $source->refresh();
    expect($result->selectionRequired)->toBeFalse()
        ->and($result->unavailableMarketplaceIds)->toBe(['A1MQXOICRS2Z7M'])
        ->and($result->selectedMarketplaceIds)->toBe(['A1MQXOICRS2Z7M'])
        ->and($source->settings['marketplace_ids'])->toBe(['A1MQXOICRS2Z7M'])
        ->and(array_column($source->settings['amazon_marketplaces'], 'id'))->toBe([
            'ATVPDKIKX0DER',
            'A2Q3Y263D00KWC',
        ]);
});

it('keeps the OAuth connection and existing marketplace when none are usable', function (): void {
    $source = amazonMarketplaceSource(['ATVPDKIKX0DER']);

    Saloon::fake([
        GetMarketplaceParticipations::class => MockResponse::make([
            'payload' => [
                amazonMarketplaceParticipation('A1MQXOICRS2Z7M', 'Non-Amazon CA', 'CA'),
            ],
        ]),
    ]);

    $result = app(AmazonMarketplaceDiscoveryService::class)->discover($source);

    $source->refresh();
    expect($result->succeeded)->toBeTrue()
        ->and($result->marketplaces)->toBe([])
        ->and($result->unavailableMarketplaceIds)->toBe(['ATVPDKIKX0DER'])
        ->and($source->settings['marketplace_ids'])->toBe(['ATVPDKIKX0DER'])
        ->and($source->secret('refresh_token'))->toBe('marketplace-refresh-token');
});

it('does not discover or change marketplaces while sandbox mode is enabled', function (): void {
    $source = amazonMarketplaceSource(['ATVPDKIKX0DER']);
    app(SettingsService::class)->set('sandbox_mode', true);
    Saloon::fake([]);

    $result = app(AmazonMarketplaceDiscoveryService::class)->discover($source);

    $source->refresh();
    expect($result->succeeded)->toBeFalse()
        ->and($result->error)->toBe('Disable sandbox mode before refreshing Amazon marketplaces.')
        ->and($source->settings['marketplace_ids'])->toBe(['ATVPDKIKX0DER'])
        ->and($source->settings)->not->toHaveKey('amazon_marketplaces')
        ->and($source->settings)->not->toHaveKey('amazon_marketplaces_sync_error');
    Saloon::assertNothingSent();
});

it('records a discovery failure without changing the connection or existing marketplace', function (): void {
    $source = amazonMarketplaceSource(['ATVPDKIKX0DER']);

    Saloon::fake([
        GetMarketplaceParticipations::class => MockResponse::make([
            'errors' => [['code' => 'Unauthorized', 'message' => 'Access denied']],
        ], 403),
    ]);

    $result = app(AmazonMarketplaceDiscoveryService::class)->discover($source);

    $source->refresh();
    expect($result->succeeded)->toBeFalse()
        ->and($result->error)->toContain('403')
        ->and($source->settings['marketplace_ids'])->toBe(['ATVPDKIKX0DER'])
        ->and($source->settings['amazon_marketplaces_sync_error'])->toContain('403')
        ->and($source->secret('refresh_token'))->toBe('marketplace-refresh-token');
});
