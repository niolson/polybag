<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\Concerns\NotifiesUser;
use App\Models\Manifest;
use App\Services\ManifestService;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Pages\Page;

class EndOfDay extends Page
{
    use NotifiesUser;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sun';

    protected static ?string $navigationLabel = 'End of Day';

    protected static \UnitEnum|string|null $navigationGroup = 'Ship';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.end-of-day';

    /** @var array<int, array{carrier: string, count: int, supports_manifest: bool}> */
    public array $carrierSummary = [];

    /** @var array<int, array<string, mixed>> */
    public array $todaysManifests = [];

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
        $this->carrierSummary = app(ManifestService::class)->getUnmanifestedSummary()->all();

        $this->todaysManifests = Manifest::query()
            ->whereDate('manifest_date', today())
            ->latest()
            ->get()
            ->map(fn ($manifest) => [
                'id' => $manifest->id,
                'carrier' => $manifest->carrier,
                'manifest_number' => $manifest->manifest_number,
                'package_count' => $manifest->package_count,
                'created_at' => $manifest->created_at->format('g:i A'),
                'has_image' => ! empty($manifest->image),
            ])
            ->all();
    }

    public function generateManifest(string $carrier): void
    {
        $packages = app(ManifestService::class)->getUnmanifestedPackages()->get($carrier);

        if (! $packages || $packages->isEmpty()) {
            $this->notifyWarning('No Packages', "No unmanifested packages found for {$carrier}.");

            return;
        }

        $response = app(ManifestService::class)->createManifest($carrier, $packages);

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

    public function markAsManifested(string $carrier): void
    {
        $count = app(ManifestService::class)->markAsManifested($carrier);

        if ($count === 0) {
            $this->notifyWarning('No Packages', "No unmanifested packages found for {$carrier}.");

            return;
        }

        $this->notifySuccess('Marked as Manifested', "{$count} {$carrier} ".str('package')->plural($count).' marked as manifested.');

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
