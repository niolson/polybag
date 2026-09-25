<?php

namespace App\Filament\Pages;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Concerns\NotifiesUser;
use App\Models\Carrier;
use App\Models\Location;
use App\Models\Manifest;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use App\Services\ManifestService;
use App\Services\SettingsService;
use App\Services\ShipDateService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

class EndOfDay extends Page
{
    use NotifiesUser;
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sun';

    protected static ?string $navigationLabel = 'End of Day';

    protected static \UnitEnum|string|null $navigationGroup = 'Ship';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.end-of-day';

    /** @var array<int, array{carrier: string, label: string, package_count: int, unmanifested_count: int, supports_manifest: bool, ship_date: string, next_ship_date: string}> */
    public array $carrierSummary = [];

    public ?int $locationId = null;

    public static function canAccess(): bool
    {
        return auth()->user()->role->isAtLeast(Role::Manager);
    }

    public function mount(): void
    {
        $this->locationId = auth()->user()->resolveLocation()?->id;
        $this->loadData();
    }

    public function updatedLocationId(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $shipDateService = app(ShipDateService::class);
        $registry = app(CarrierRegistry::class);
        $multiLocation = (bool) app(SettingsService::class)->get('multi_location_enabled', false);

        $this->carrierSummary = Carrier::active()
            ->orderBy('name')
            ->get()
            ->map(function ($carrier) use ($shipDateService, $registry, $multiLocation): array {
                $locationId = $multiLocation ? $this->locationId : null;
                $shipDate = $shipDateService->getShipDate($carrier->name, $locationId);
                $nextShipDate = $shipDateService->getNextPickupDay($carrier->name, $locationId);
                // The carrier-level question — does this carrier run a manifest
                // programme? Whether a given package may go on one we create is
                // a postage-source question, enforced by boughtOnCarrierAccount()
                // on the counts below.
                $supportsManifest = $registry->policyFor($carrier->name)?->supportsCarrierManifest() ?? false;

                $packageCount = Package::query()
                    ->where('carrier', $carrier->name)
                    ->where('status', PackageStatus::Shipped)
                    ->boughtOnCarrierAccount()
                    ->whereNotNull('tracking_number')
                    ->whereDate('ship_date', $shipDate)
                    ->when($multiLocation && $locationId, fn ($q) => $q->where('location_id', $locationId))
                    ->count();

                $unmanifestedCount = 0;
                if ($supportsManifest && $packageCount > 0) {
                    $unmanifestedCount = Package::query()
                        ->where('carrier', $carrier->name)
                        ->where('status', PackageStatus::Shipped)
                        ->boughtOnCarrierAccount()
                        ->whereNotNull('tracking_number')
                        ->whereNull('manifest_id')
                        ->whereDate('ship_date', $shipDate)
                        ->when($multiLocation && $locationId, fn ($q) => $q->where('location_id', $locationId))
                        ->count();
                }

                return [
                    'carrier' => $carrier->name,
                    'label' => $carrier->label(),
                    'package_count' => $packageCount,
                    'unmanifested_count' => $unmanifestedCount,
                    'supports_manifest' => $supportsManifest,
                    'ship_date' => $shipDate->format('M j'),
                    'next_ship_date' => $nextShipDate->format('M j'),
                ];
            })
            ->all();
    }

    public function getManifestsProperty(): LengthAwarePaginator
    {
        $multiLocation = (bool) app(SettingsService::class)->get('multi_location_enabled', false);

        return Manifest::query()
            ->with('location')
            ->when($multiLocation && $this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->latest()
            ->paginate(10);
    }

    /** @return array<int|string, string> */
    public function getLocationsProperty(): array
    {
        return Location::active()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function endShippingDay(string $carrier): void
    {
        $shipDateService = app(ShipDateService::class);
        $multiLocation = (bool) app(SettingsService::class)->get('multi_location_enabled', false);
        $locationId = $multiLocation ? $this->locationId : null;

        $shipDateService->endShippingDay($carrier, $locationId);

        $nextDate = $shipDateService->getShipDate($carrier, $locationId);
        $this->notifySuccess('Shipping Day Ended', "{$carrier} ship date advanced to {$nextDate->format('M j')}.");

        $this->loadData();
    }

    public function generateManifest(string $carrier): void
    {
        $multiLocation = (bool) app(SettingsService::class)->get('multi_location_enabled', false);
        $locationId = $multiLocation ? $this->locationId : null;
        $shipDate = app(ShipDateService::class)->getShipDate($carrier, $locationId);

        $packages = Package::query()
            ->where('carrier', $carrier)
            ->where('status', PackageStatus::Shipped)
            ->boughtOnCarrierAccount()
            ->whereNotNull('tracking_number')
            ->whereNull('manifest_id')
            ->whereDate('ship_date', $shipDate)
            ->when($multiLocation && $locationId, fn ($q) => $q->where('location_id', $locationId))
            ->get();

        if ($packages->isEmpty()) {
            $this->notifyWarning('No Packages', "No packages to manifest for {$carrier}.");

            return;
        }

        $response = app(ManifestService::class)->createManifest($carrier, $packages, $shipDate, $locationId);

        if (! $response->success) {
            $this->notifyError('Manifest Error', $response->errorMessage ?? 'Failed to create manifest.');

            return;
        }

        if ($response->image && ! app(SettingsService::class)->get('suppress_printing', false)) {
            $this->dispatch('print-report', data: $response->image);
        }

        $this->notifySuccess('Manifest Created', "Manifest {$response->manifestNumber} created for {$carrier}.");

        $this->loadData();
    }

    public function reprintManifest(int $manifestId): void
    {
        $manifest = Manifest::find($manifestId);

        if (! $manifest || empty($manifest->image)) {
            $this->notifyError('Reprint Error', 'Manifest image not available.');

            return;
        }

        $this->dispatch('print-report', data: $manifest->image);

        $this->notifySuccess('Reprinting', "Manifest {$manifest->manifest_number} sent to printer.");
    }
}
