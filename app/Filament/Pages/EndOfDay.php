<?php

namespace App\Filament\Pages;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Concerns\NotifiesUser;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Manifest;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use App\Services\ManifestService;
use App\Services\SettingsService;
use App\Services\ShipDateService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\WithPagination;

class EndOfDay extends Page
{
    use NotifiesUser;
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sun';

    protected static ?string $navigationLabel = 'End of Day';

    protected static ?string $title = 'End of Day';

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
     * One row per carrier this warehouse ships with: one with an active
     * account (a `CarrierAccount`, or for Amazon Shipping an Amazon connection
     * scoped to sell it), or with a label on its current ship date. A carrier
     * dates every label it is expected to carry, whichever source sold it
     * (ADR-0006, decision 1 as amended 2026-10-16), so a carrier with no
     * account, such as OnTrac reached through Amazon Buy Shipping, appears
     * once it has a label to date. So does the carrier a Shopify connection
     * dates its labels by.
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
        $carriersWithAccount = $this->carrierIdsWithAccount();

        $this->carrierSummary = Carrier::active()
            ->orderBy('name')
            ->get()
            ->map(function (Carrier $carrier) use ($shipDateService, $registry, $multiLocation, $carriersWithAccount): ?array {
                $locationId = $multiLocation ? $this->locationId : null;
                $shipDate = $shipDateService->getShipDate($carrier, $locationId);
                $nextShipDate = $shipDateService->getNextPickupDay($carrier, $locationId);
                // The carrier-level question — does this carrier run a manifest
                // program? Whether a given package may go on one we create is
                // a postage-source question, enforced by boughtOnCarrierAccount()
                // on the manifest count below.
                $supportsManifest = $registry->policyFor($carrier->name)?->supportsCarrierManifest() ?? false;

                $batchStartedAt = $shipDateService->currentBatchStartedAt($carrier, $locationId);

                $packageCount = $this->shippedPackages($carrier, $locationId)
                    ->where(fn (Builder $query): Builder => $query
                        ->whereDate('ship_date', $shipDate)
                        ->orWhere('shipped_at', '>', $batchStartedAt->setTimezone(config('app.timezone'))))
                    ->count();

                $unmanifestedCount = $supportsManifest
                    ? $this->manifestablePackages($carrier, $shipDate, $locationId)->count()
                    : 0;

                if ($packageCount === 0 && $unmanifestedCount === 0 && ! in_array($carrier->id, $carriersWithAccount, true)) {
                    return null;
                }

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
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Carriers with an active account here: a `CarrierAccount`, or, for a
     * carrier whose account is a connection (Amazon Shipping), an active
     * Amazon connection with a scope to sell it. The carrier an active Shopify
     * connection that sells postage dates its labels by counts too, since
     * ending that carrier's day is how Shopify's day is ended.
     *
     * @return list<int>
     */
    private function carrierIdsWithAccount(): array
    {
        $ids = CarrierAccount::active()->distinct()->pluck('carrier_id')->map(fn ($id): int => (int) $id)->all();

        $shopifyDateCarrierIds = DataSource::query()
            ->where('active', true)
            ->where('source_type', ShopifySource::class)
            ->get()
            ->filter(fn (DataSource $source): bool => $source->sellsPostage())
            ->map(fn (DataSource $source): ?int => $source->shipDateCarrier()?->id)
            ->filter()
            ->all();

        $ids = [...$ids, ...$shopifyDateCarrierIds];

        $hasConnectionAccount = CarrierAccountScope::query()
            ->whereHas('dataSource', fn (Builder $query): Builder => $query
                ->where('active', true)
                ->where('source_type', AmazonSource::class)
                ->where('offers_off_amazon_shipping', true))
            ->exists();

        if ($hasConnectionAccount) {
            $connectionCarrierIds = Carrier::query()
                ->whereIn('name', CarrierRegistry::connectionCarrierNames())
                ->pluck('id')
                ->all();

            $ids = [...$ids, ...$connectionCarrierIds];
        }

        return array_values(array_unique($ids));
    }

    public function endShippingDayAction(): Action
    {
        return Action::make('endShippingDay')
            ->label('End Shipping Day')
            ->icon('heroicon-o-sun')
            ->size(Size::Small)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'End '.($this->summaryFor($arguments)['label'] ?? 'the').' shipping day?')
            ->modalDescription(function (array $arguments): string {
                $summary = $this->summaryFor($arguments);

                return $summary
                    ? "New {$summary['label']} labels will be dated {$summary['next_ship_date']} instead of {$summary['ship_date']}."
                    : '';
            })
            ->modalSubmitActionLabel('End Shipping Day')
            ->action(fn (array $arguments) => $this->endShippingDay((int) ($arguments['carrier'] ?? 0)));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{carrier_id: int, carrier: string, label: string, package_count: int, unmanifested_count: int, supports_manifest: bool, ship_date: string, next_ship_date: string}|null
     */
    private function summaryFor(array $arguments): ?array
    {
        return collect($this->carrierSummary)->firstWhere('carrier_id', (int) ($arguments['carrier'] ?? 0));
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
        $this->loadData();

        $unmanifested = collect($this->carrierSummary)->firstWhere('carrier_id', $carrier->id)['unmanifested_count'] ?? 0;

        if ($unmanifested > 0) {
            $this->notifyWarning(
                'Shipping Day Ended',
                "{$carrier->label()} ship date advanced to {$nextDate->format('M j')}. "
                    .trans_choice('{1} 1 label is|[2,*] :count labels are', $unmanifested, ['count' => $unmanifested])
                    .' not on a manifest yet. Generate the manifest before midnight.',
            );

            return;
        }

        $this->notifySuccess('Shipping Day Ended', "{$carrier->label()} ship date advanced to {$nextDate->format('M j')}.");
    }

    /**
     * Create the manifest for this carrier's labels, and with `$print` send
     * every form it made to the document printer. One run can make several:
     * one per ship date, and for USPS one SCAN form per account. The
     * `suppress_printing` setting overrides `$print`.
     */
    public function generateManifest(int $carrierId, bool $print = true): void
    {
        $carrier = Carrier::find($carrierId);

        if (! $carrier) {
            return;
        }

        $multiLocation = (bool) app(SettingsService::class)->get('multi_location_enabled', false);
        $locationId = $multiLocation ? $this->locationId : null;
        $shipDate = app(ShipDateService::class)->getShipDate($carrier, $locationId);
        $print = $print && ! $this->printingSuppressed();

        $packages = $this->manifestablePackages($carrier, $shipDate, $locationId)->get();

        if ($packages->isEmpty()) {
            $this->notifyWarning('No Packages', "No packages to manifest for {$carrier->label()}.");

            return;
        }

        // One form per mailing date: labels still dated today after the
        // cutoff or End Shipping Day, and any already dated for the next pickup.
        foreach ($packages->groupBy(fn (Package $package): string => $package->ship_date->toDateString())->sortKeys() as $date => $datedPackages) {
            $response = app(ManifestService::class)->createManifest($carrier->name, $datedPackages, CarbonImmutable::parse($date), $locationId);

            if (! $response->success) {
                $this->notifyError('Manifest Error', $response->errorMessage ?? 'Failed to create manifest.');
                $this->loadData();

                return;
            }

            $printed = 0;

            if ($print) {
                foreach ($response->manifests as $manifest) {
                    if (! empty($manifest->image)) {
                        $this->dispatch('print-report', data: $manifest->image);
                        $printed++;
                    }
                }
            }

            $this->notifySuccess('Manifest Created', $this->manifestCreatedMessage($carrier, $response->manifests, $printed));
        }

        $this->loadData();
    }

    /**
     * @param  list<Manifest>  $manifests
     */
    private function manifestCreatedMessage(Carrier $carrier, array $manifests, int $printed): string
    {
        $count = count($manifests);
        $numbers = implode(', ', array_map(fn (Manifest $manifest): string => (string) $manifest->manifest_number, $manifests));
        $sent = $printed === $count ? ' and sent to the printer' : '';

        $message = $count === 1
            ? "Manifest {$numbers} created for {$carrier->label()}{$sent}."
            : "{$count} manifests created for {$carrier->label()}{$sent}: {$numbers}.";

        return $printed > 0 && $printed < $count
            ? "{$message} {$printed} of {$count} sent to the printer."
            : $message;
    }

    public function printingSuppressed(): bool
    {
        return (bool) app(SettingsService::class)->get('suppress_printing', false);
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
     * The labels a manifest would take: bought on our own carrier account, on
     * no manifest yet, and dated from today through the carrier's current
     * ship date.
     *
     * The current ship date moves past today at the carrier's cutoff and when
     * someone ends the shipping day, and today's labels must still reach a
     * form then. Earlier dates are left out: their mailing date has passed.
     *
     * @return Builder<Package>
     */
    private function manifestablePackages(Carrier $carrier, CarbonImmutable $shipDate, ?int $locationId): Builder
    {
        $today = app(ShipDateService::class)->today($locationId);

        return $this->shippedPackages($carrier, $locationId)
            ->boughtOnCarrierAccount()
            ->whereNull('manifest_id')
            ->whereDate('ship_date', '>=', $today->min($shipDate)->toDateString())
            ->whereDate('ship_date', '<=', $shipDate->toDateString());
    }

    public function reprintManifest(int $manifestId): void
    {
        $manifest = Manifest::find($manifestId);

        if (! $manifest || empty($manifest->image)) {
            $this->notifyError('Reprint Error', 'This manifest has no image to print.');

            return;
        }

        $this->dispatch('print-report', data: $manifest->image);

        $this->notifySuccess('Reprinting', "Manifest {$manifest->manifest_number} sent to printer.");
    }
}
