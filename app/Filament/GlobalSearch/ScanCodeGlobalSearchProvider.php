<?php

namespace App\Filament\GlobalSearch;

use App\Enums\ScanCodeType;
use App\Filament\Resources\PackageResource;
use App\Filament\Resources\ShipmentResource;
use App\Services\Scanning\ScanCode;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;

/**
 * Global search that resolves a PolyBag code to exactly its record, and
 * leaves every other search to Filament (ADR-0007, decision 2). A scanned
 * pack slip must open its own Shipment, never something whose text matches.
 */
class ScanCodeGlobalSearchProvider implements GlobalSearchProvider
{
    public function __construct(private readonly DefaultGlobalSearchProvider $default) {}

    public function getResults(string $query): ?GlobalSearchResults
    {
        $code = ScanCode::parse($query);

        if ($code === null) {
            return $this->default->getResults($query);
        }

        $results = GlobalSearchResults::make();

        $resource = match ($code->type) {
            ScanCodeType::Shipment => ShipmentResource::class,
            ScanCodeType::Package => PackageResource::class,
            default => null,
        };

        if ($resource === null || ! $resource::canGloballySearch()) {
            return $results;
        }

        $record = $resource::getGlobalSearchEloquentQuery()->whereKey($code->id)->first();
        $url = $record ? $resource::getGlobalSearchResultUrl($record) : null;

        if ($record === null || blank($url)) {
            return $results;
        }

        return $results->category($resource::getPluralModelLabel(), [
            new GlobalSearchResult(
                title: $resource::getGlobalSearchResultTitle($record),
                url: $url,
                details: $resource::getGlobalSearchResultDetails($record),
            ),
        ]);
    }
}
