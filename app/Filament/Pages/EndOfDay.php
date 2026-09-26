<?php

namespace App\Filament\Pages;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Concerns\NotifiesUser;
use App\Models\Carrier;
use App\Models\Location;
use App\Models\Manifest;
use App\Models\Package;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\CarrierRegistry;
use App\Services\ManifestService;
use App\Services\SettingsService;
use App\Services\ShipDateService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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

    /** @var array<int, array{carrier_id: int, carrier: string, label: string, package_count: int, unmanifested_count: int, supports_manifest: bool, ship_date: string, next_ship_date: string}> */
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

    /**
     * One row per carrier, because a carrier dates every label it is expected
     * to carry, whichever source sold it (ADR-0006, guideline 12). A carrier
     * with no direct integration, such as OnTrac, is listed too, so its day
     * can be ended.
     *
     * The package count is by carrier of record, from any source: a Shopify
     * `auto` label dated by USPS that Shopify put on UPS counts under UPS,
     * whose driver takes it. A label counts if its ship date is the carrier's
     * current one, or it was bought since the carrier's day was ended for the
     * current batch. The second test is for that `auto` label: its date came
     * from USPS's policy and need not equal UPS's current date.
     *
     * The manifest count stays by ship date and direct labels only, because
     * a manifest is created on our own carrier account for one ship date.
     */
    public function loadData(): void
    {
        $shipDateService = app(ShipDateService::class);
        $registry = app(CarrierRegistry::class);
        $multiLocation = (bool) app(SettingsService::class)->get('multi_location_enabled', false);

        $this->carrierSummary = Carrier::active()
            // Transitional: the fake `Amazon` row dates nothing any more, and
            // leaves in `carrier-catalog-reset/12`.
            ->where('name', '!=', AmazonBuyShippingAdapter::SOURCE_NAME)
            ->orderBy('name')
            ->get()
            ->map(function (Carrier $carrier) use ($shipDateService, $registry, $multiLocation): array {
                $locationId = $multiLocation ? $this->locationId : null;
                $shipDate = $shipDateService->getShipDate($carrier, $locationId);
                $nextShipDate = $shipDateService->getNextPickupDay($carrier, $locationId);
                // The carrier-level question — does this carrier run a manifest
                // programme? Whether a given package may go on one we create is
                // a postage-source question, enforced by boughtOnCarrierAccount()
                // on the manifest count below.
                $supportsManifest = $registry->policyFor($carrier->name)?->supportsCarrierManifest() ?? false;

                $endedAt = $shipDateService->lastEndOfDayForCurrentBatch($carrier, $locationId);

                $packageCount = $this->shippedPackages($carrier, $locationId)
                    ->where(fn (Builder $query): Builder => $query
                        ->whereDate('ship_date', $shipDate)
                        ->when($endedAt, fn (Builder $query): Builder => $query
                            ->orWhere('shipped_at', '>', $endedAt->setTimezone(config('app.timezone')))))
                    ->count();

                $unmanifestedCount = $supportsManifest
                    ? $this->manifestablePackages($carrier, $shipDate, $locationId)->count()
                    : 0;

                return [
                    'carrier_id' => $carrier->id,
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

    public function endShippingDay(int $carrierId): void
    {
        $carrier = Carrier::find($carrierId);

        if (! $carrier) {
            return;
        }

        $shipDateService = app(ShipDateService::class);
        $multiLocation = (bool) app(SettingsService::class)->get('multi_location_enabled', false);
        $locationId = $multiLocation ? $this->locationId : null;

        $shipDateService->endShippingDay($carrier, $locationId);

        $nextDate = $shipDateService->getShipDate($carrier, $locationId);
        $this->notifySuccess('Shipping Day Ended', "{$carrier->label()} ship date advanced to {$nextDate->format('M j')}.");

        $this->loadData();
    }

    public function generateManifest(int $carrierId): void
    {
        $carrier = Carrier::find($carrierId);

        if (! $carrier) {
            return;
        }

        $multiLocation = (bool) app(SettingsService::class)->get('multi_location_enabled', false);
        $locationId = $multiLocation ? $this->locationId : null;
        $shipDate = app(ShipDateService::class)->getShipDate($carrier, $locationId);

        $packages = $this->manifestablePackages($carrier, $shipDate, $locationId)->get();

        if ($packages->isEmpty()) {
            $this->notifyWarning('No Packages', "No packages to manifest for {$carrier->label()}.");

            return;
        }

        $response = app(ManifestService::class)->createManifest($carrier->name, $packages, $shipDate, $locationId);

        if (! $response->success) {
            $this->notifyError('Manifest Error', $response->errorMessage ?? 'Failed to create manifest.');

            return;
        }

        if ($response->image && ! app(SettingsService::class)->get('suppress_printing', false)) {
            $this->dispatch('print-report', data: $response->image);
        }

        $this->notifySuccess('Manifest Created', "Manifest {$response->manifestNumber} created for {$carrier->label()}.");

        $this->loadData();
    }

    /**
     * Shipped labels this carrier carries, read by the carrier of record
     * rather than by the name a source reported. The location is null unless
     * multi-location is on.
     *
     * @return Builder<Package>
     */
    private function shippedPackages(Carrier $carrier, ?int $locationId): Builder
    {
        return Package::query()
            ->where('normalized_carrier_id', $carrier->id)
            ->where('status', PackageStatus::Shipped)
            ->whereNotNull('tracking_number')
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId));
    }

    /**
     * The labels a manifest for this ship date would take: bought on our own
     * carrier account and on no manifest yet.
     *
     * @return Builder<Package>
     */
    private function manifestablePackages(Carrier $carrier, CarbonImmutable $shipDate, ?int $locationId): Builder
    {
        return $this->shippedPackages($carrier, $locationId)
            ->boughtOnCarrierAccount()
            ->whereNull('manifest_id')
            ->whereDate('ship_date', $shipDate);
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
