<?php

use App\Enums\PackageStatus;
use App\Filament\GlobalSearch\ScanCodeGlobalSearchProvider;
use App\Filament\Resources\PackageResource;
use App\Filament\Resources\ShipmentResource;
use App\Models\Client;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Scanning\ShipmentReferenceResolver;
use Filament\GlobalSearch\GlobalSearchResult;

beforeEach(function (): void {
    $this->actingAs(User::factory()->manager()->create());
});

/**
 * @return array<string, list<string>> Category => result URLs
 */
function scanSearch(string $query): array
{
    return app(ScanCodeGlobalSearchProvider::class)->getResults($query)
        ->getCategories()
        ->map(fn ($results): array => collect($results)->map(fn (GlobalSearchResult $result): string => $result->url)->all())
        ->all();
}

it('resolves an order reference to every shipment sharing it', function (): void {
    $first = Shipment::factory()->create(['shipment_reference' => '#1001', 'client_id' => Client::factory()]);
    $second = Shipment::factory()->create(['shipment_reference' => '#1001', 'client_id' => Client::factory()]);
    Shipment::factory()->create(['shipment_reference' => '#1002']);

    expect(app(ShipmentReferenceResolver::class)->matching('#1001')->modelKeys())->toBe([$first->id, $second->id])
        ->and(app(ShipmentReferenceResolver::class)->matching('  '))->toBeEmpty();
});

it('finds exactly the shipment a code names in global search', function (): void {
    $shipment = Shipment::factory()->create(['shipment_reference' => '#1247']);

    expect(scanSearch("%S{$shipment->id}"))->toBe([
        ShipmentResource::getPluralModelLabel() => [ShipmentResource::getUrl('view', ['record' => $shipment])],
    ]);
});

it('finds exactly the package a code names in global search', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);

    expect(scanSearch("%P{$package->id}"))->toBe([
        PackageResource::getPluralModelLabel() => [PackageResource::getUrl('view', ['record' => $package])],
    ]);
});

it('never answers a code with a record whose text merely matches it', function (): void {
    $deletedId = Shipment::factory()->create()->id;
    Shipment::query()->whereKey($deletedId)->delete();
    Shipment::factory()->create(['shipment_reference' => "%S{$deletedId}"]);

    expect(scanSearch("%S{$deletedId}"))->toBe([])
        ->and(scanSearch('%X12'))->toBe([]);
});

it('leaves any other search to Filament', function (): void {
    $shipment = Shipment::factory()->create(['shipment_reference' => '#1247']);

    expect(scanSearch('#1247'))->toBe([
        ShipmentResource::getPluralModelLabel() => [ShipmentResource::getUrl('view', ['record' => $shipment])],
    ]);
});

it('names the client, connection and status in shipment search results', function (): void {
    $client = Client::factory()->create(['name' => 'Acme Outfitters']);
    Shipment::factory()->create(['shipment_reference' => '#1001', 'client_id' => $client->id]);

    $details = ShipmentResource::getGlobalSearchResults('#1001')->first()?->details;

    expect($details)->toMatchArray(['Client' => 'Acme Outfitters', 'Status' => 'Open']);
});
