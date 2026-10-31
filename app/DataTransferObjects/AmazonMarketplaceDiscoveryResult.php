<?php

namespace App\DataTransferObjects;

final readonly class AmazonMarketplaceDiscoveryResult
{
    /**
     * @param  list<array{id: string, name: string, country_code: string, is_participating: bool, has_suspended_listings: bool}>  $marketplaces
     * @param  list<string>  $selectedMarketplaceIds  the marketplaces the connection imports from afterwards
     * @param  list<string>  $unavailableMarketplaceIds  selected marketplaces discovery no longer reports; they stay selected
     */
    public function __construct(
        public bool $succeeded,
        public array $marketplaces,
        public array $selectedMarketplaceIds,
        public bool $selectionRequired,
        public array $unavailableMarketplaceIds = [],
        public ?string $error = null,
    ) {}
}
