<?php

namespace App\Filament\Pages;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Concerns\NotifiesUser;
use App\Models\Carrier;
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

    /** @var array<int, array{carrier: string, package_count: int, unmanifested_count: int, supports_manifest: bool, ship_date: string, next_ship_date: string}> */
    public array $carrierSummary = [];

    public static function canAccess(): bool
    {
        return auth()->user()->role->isAtLeast(Role::Manager);
    }

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $shipDateService = app(ShipDateService::class);
        $registry = app(CarrierRegistry::class);

        $this->carrierSummary = Carrier::active()
            ->orderBy('name')
            ->get()
            ->map(function ($carrier) use ($shipDateService, $registry) {
                $shipDate = $shipDateService->getShipDate($carrier->name);
                $nextShipDate = $shipDateService->getNextPickupDay($carrier->name);
                $supportsManifest = $registry->has($carrier->name)
                    && $registry->get($carrier->name)->supportsManifest();

                $packageCount = Package::query()
                    ->where('carrier', $carrier->name)
                    ->where('status', PackageStatus::Shipped)
                    ->whereNotNull('tracking_number')
                    ->whereDate('ship_date', $shipDate)
                    ->count();

                $unmanifestedCount = 0;
                if ($supportsManifest && $packageCount > 0) {
                    $unmanifestedCount = Package::query()
                        ->where('carrier', $carrier->name)
                        ->where('status', PackageStatus::Shipped)
                        ->whereNotNull('tracking_number')
                        ->whereNull('manifest_id')
                        ->whereDate('ship_date', $shipDate)
                        ->count();
                }

                return [
                    'carrier' => $carrier->name,
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
        return Manifest::query()
            ->latest()
            ->paginate(10);
    }

    public function endShippingDay(string $carrier): void
    {
        $shipDateService = app(ShipDateService::class);

        $shipDateService->endShippingDay($carrier);

        $nextDate = $shipDateService->getShipDate($carrier);
        $this->notifySuccess('Shipping Day Ended', "{$carrier} ship date advanced to {$nextDate->format('M j')}.");

        $this->loadData();
    }

    public function generateManifest(string $carrier): void
    {
        $shipDate = app(ShipDateService::class)->getShipDate($carrier);

        $packages = Package::query()
            ->where('carrier', $carrier)
            ->where('status', PackageStatus::Shipped)
            ->whereNotNull('tracking_number')
            ->whereNull('manifest_id')
            ->whereDate('ship_date', $shipDate)
            ->get();

        if ($packages->isEmpty()) {
            $this->notifyWarning('No Packages', "No packages to manifest for {$carrier}.");

            return;
        }

        $response = app(ManifestService::class)->createManifest($carrier, $packages, $shipDate);

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
