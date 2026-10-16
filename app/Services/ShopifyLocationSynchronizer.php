<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\DataSourceLocation;
use App\Models\Location;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\Sources\ShopifySource;
use DomainException;
use InvalidArgumentException;

class ShopifyLocationSynchronizer
{
    public function __construct(
        private readonly DataSourceFactory $factory,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{synced: int, deactivated: int, auto_mapped: int}
     */
    public function synchronize(DataSource $dataSource): array
    {
        $source = $this->factory->make($dataSource);

        if (! $source instanceof ShopifySource) {
            throw new InvalidArgumentException('Only Shopify connections can synchronize locations.');
        }

        /**
         * Ask Shopify what the token can actually do rather than trusting
         * `oauth_scopes` cached at connect time. That cache goes stale whenever
         * the app's scopes change in the Shopify Dev Dashboard — and for apps
         * with declared scopes Shopify ignores the scope parameter sent during
         * authorization, so the cached value can be empty or wrong while the
         * live token is perfectly capable. An import checks the same way.
         */
        $missingScopes = array_values(array_diff(
            ShopifySource::REQUIRED_SCOPES,
            $source->fetchAccessScopes(),
        ));

        if ($missingScopes !== []) {
            throw new DomainException('Reconnect Shopify with the required scopes before synchronizing locations: '.implode(', ', $missingScopes).'.');
        }

        $source->validateConfiguration();
        $locations = $source->fetchLocations();
        $seen = [];

        foreach ($locations as $location) {
            $seen[] = $location['external_id'];
            DataSourceLocation::updateOrCreate(
                [
                    'data_source_id' => $dataSource->id,
                    'external_id' => $location['external_id'],
                ],
                [
                    'external_code' => $location['external_code'] ?? null,
                    'name' => $location['name'],
                    'address' => $location['address'] ?? null,
                    'is_active' => true,
                    'last_seen_at' => now(),
                ],
            );
        }

        $deactivated = $dataSource->locations()
            ->where('is_active', true)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('external_id', $seen))
            ->update(['is_active' => false]);

        $autoMapped = 0;
        $targetLocationId = count($seen) === 1 ? $this->soleLocationId() : null;
        if ($targetLocationId !== null) {
            $sourceLocation = $dataSource->locations()
                ->where('external_id', $seen[0])
                ->whereNull('location_id')
                ->whereNull('ignored_at')
                ->first();

            if ($sourceLocation !== null) {
                $sourceLocation->update(['location_id' => $targetLocationId]);
                $autoMapped = 1;
            }
        }

        return [
            'synced' => count($seen),
            'deactivated' => $deactivated,
            'auto_mapped' => $autoMapped,
        ];
    }

    /**
     * The PolyBag Location a store's only location can only mean: the default
     * one in single-location mode, otherwise the only active one.
     */
    private function soleLocationId(): ?int
    {
        if (! $this->settings->get('multi_location_enabled', false)) {
            return Location::getDefault()?->id;
        }

        $activeLocationIds = Location::active()->limit(2)->pluck('id');

        return $activeLocationIds->count() === 1 ? $activeLocationIds->first() : null;
    }
}
