<?php

namespace App\Services;

use App\DataTransferObjects\AmazonMarketplaceDiscoveryResult;
use App\Enums\AmazonMarketplace;
use App\Http\Integrations\Amazon\AmazonSpApiConnector;
use App\Http\Integrations\Amazon\AmazonSpApiEnvironment;
use App\Http\Integrations\Amazon\Requests\GetMarketplaceParticipations;
use App\Models\DataSource;
use Illuminate\Support\Facades\Log;
use Throwable;

class AmazonMarketplaceDiscoveryService
{
    /**
     * Discover the supported retail marketplaces available to an Amazon seller.
     *
     * Refused where the seller's data is read from the sandbox, which knows
     * nothing of the seller's marketplaces. In Demo it is read from production
     * ({@see AmazonSpApiEnvironment}), so discovery runs there.
     */
    public function discover(DataSource $dataSource): AmazonMarketplaceDiscoveryResult
    {
        if (app(AmazonSpApiEnvironment::class)->readsFromSandbox()) {
            return new AmazonMarketplaceDiscoveryResult(
                succeeded: false,
                marketplaces: [],
                selectedMarketplaceIds: $dataSource->amazonMarketplaceIds(),
                selectionRequired: false,
                error: 'Disable sandbox mode before refreshing Amazon marketplaces.',
            );
        }

        try {
            $response = AmazonSpApiConnector::fromSettings([
                ...($dataSource->settings ?? []),
                ...($dataSource->secret_settings ?? []),
                '_data_source_id' => $dataSource->id,
            ])->send(new GetMarketplaceParticipations);

            if (! $response->successful()) {
                return $this->recordFailure(
                    $dataSource,
                    "Amazon marketplace discovery failed with HTTP {$response->status()}.",
                );
            }

            $participations = $response->json('payload');

            if (! is_array($participations)) {
                return $this->recordFailure($dataSource, 'Amazon marketplace discovery returned an invalid response.');
            }

            $marketplaces = $this->normalizeMarketplaces($participations);
            $settings = $dataSource->settings ?? [];
            $existingMarketplaceIds = DataSource::amazonMarketplaceIdsIn($settings);
            $availableIds = array_column($marketplaces, 'id');

            // A selection is kept, and what Amazon no longer reports is
            // flagged rather than dropped. With none, only a seller's sole
            // marketplace is chosen for them: an order from another country
            // is an international shipment, so nobody imports one unasked.
            $selectedMarketplaceIds = $existingMarketplaceIds !== []
                ? $existingMarketplaceIds
                : (count($marketplaces) === 1 ? [$marketplaces[0]['id']] : []);

            if ($selectedMarketplaceIds === []) {
                unset($settings['marketplace_ids'], $settings['primary_marketplace_id']);
            } else {
                $settings['primary_marketplace_id'] = DataSource::primaryAmazonMarketplaceIdIn([
                    ...$settings,
                    'marketplace_ids' => $selectedMarketplaceIds,
                ]);
                $settings['marketplace_ids'] = $selectedMarketplaceIds;
            }

            $settings['amazon_marketplaces'] = $marketplaces;
            $settings['amazon_marketplaces_synced_at'] = now()->toIso8601String();
            unset($settings['amazon_marketplaces_sync_error']);

            $dataSource->settings = $settings;
            $dataSource->save();

            return new AmazonMarketplaceDiscoveryResult(
                succeeded: true,
                marketplaces: $marketplaces,
                selectedMarketplaceIds: $selectedMarketplaceIds,
                selectionRequired: count($marketplaces) > 1 && $existingMarketplaceIds === [],
                unavailableMarketplaceIds: array_values(array_diff($existingMarketplaceIds, $availableIds)),
            );
        } catch (Throwable $exception) {
            Log::warning('Amazon marketplace discovery failed.', [
                'data_source_id' => $dataSource->id,
                'exception_class' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return $this->recordFailure($dataSource, 'Amazon marketplace discovery failed: '.$exception->getMessage());
        }
    }

    /**
     * @param  array<int, mixed>  $participations
     * @return list<array{id: string, name: string, country_code: string, is_participating: bool, has_suspended_listings: bool}>
     */
    private function normalizeMarketplaces(array $participations): array
    {
        $marketplaces = [];

        foreach ($participations as $participation) {
            if (! is_array($participation)) {
                continue;
            }

            $marketplaceData = $participation['marketplace'] ?? null;
            $participationData = $participation['participation'] ?? null;

            if (! is_array($marketplaceData) || ! is_array($participationData)) {
                continue;
            }

            $marketplace = AmazonMarketplace::tryFrom((string) ($marketplaceData['id'] ?? ''));
            $isParticipating = (bool) ($participationData['isParticipating'] ?? false);

            if ($marketplace === null || ! $isParticipating) {
                continue;
            }

            $marketplaces[] = [
                'id' => $marketplace->value,
                'name' => $marketplace->label(),
                'country_code' => $marketplace->countryCode(),
                'is_participating' => true,
                'has_suspended_listings' => (bool) ($participationData['hasSuspendedListings'] ?? false),
            ];
        }

        return $marketplaces;
    }

    private function recordFailure(DataSource $dataSource, string $message): AmazonMarketplaceDiscoveryResult
    {
        $settings = $dataSource->settings ?? [];
        $settings['amazon_marketplaces_sync_error'] = $message;
        $dataSource->settings = $settings;
        $dataSource->save();

        return new AmazonMarketplaceDiscoveryResult(
            succeeded: false,
            marketplaces: $settings['amazon_marketplaces'] ?? [],
            selectedMarketplaceIds: DataSource::amazonMarketplaceIdsIn($settings),
            selectionRequired: false,
            error: $message,
        );
    }
}
