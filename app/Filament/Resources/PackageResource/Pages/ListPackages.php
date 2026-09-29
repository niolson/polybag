<?php

namespace App\Filament\Resources\PackageResource\Pages;

use App\Enums\PackageStatus;
use App\Enums\TrackingStatus;
use App\Filament\Concerns\KeepsStatusTabInStepWithStatusFilter;
use App\Filament\Concerns\NotifiesUser;
use App\Filament\Concerns\PrintsLabels;
use App\Filament\Resources\PackageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListPackages extends ListRecords
{
    use KeepsStatusTabInStepWithStatusFilter, NotifiesUser, PrintsLabels;

    protected static string $resource = PackageResource::class;

    protected string $view = 'filament.resources.package-resource.pages.list-packages';

    #[Url(as: 'status_tab')]
    public ?string $activeStatusTab = 'all';

    #[Url(as: 'tracking_tab')]
    public ?string $activeTrackingTab = 'all';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->modifyQueryWithStatusTab($query))
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->modifyQueryWithTrackingTab($query));
    }

    public function updatedActiveStatusTab(): void
    {
        $this->clearStatusFilterForStatusTab();
        $this->resetPage();
    }

    public function updatedActiveTrackingTab(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTabGroups(): array
    {
        return [
            [
                'label' => 'Package Status',
                'alignment' => 'start',
                'property' => 'activeStatusTab',
                'tabs' => [
                    'all' => 'All',
                    PackageStatus::Unshipped->value => 'Unshipped',
                    PackageStatus::Shipped->value => 'Shipped',
                    PackageStatus::Void->value => 'Void',
                ],
            ],
            [
                'label' => 'Tracking Status',
                'alignment' => 'end',
                'property' => 'activeTrackingTab',
                'tabs' => [
                    'all' => 'All',
                    TrackingStatus::Exception->value => 'Exception',
                    TrackingStatus::PreTransit->value => 'Pre-Transit',
                    TrackingStatus::InTransit->value => 'In Transit',
                    TrackingStatus::OutForDelivery->value => 'Out for Delivery',
                    TrackingStatus::Delivered->value => 'Delivered',
                    TrackingStatus::Returned->value => 'Returned',
                ],
            ],
        ];
    }

    protected function modifyQueryWithStatusTab(Builder $query): Builder
    {
        $status = PackageStatus::tryFrom($this->activeStatusTab ?? '');

        if ($status === null || $this->hasStatusFilter()) {
            return $query;
        }

        return $query->where('status', $status);
    }

    protected function modifyQueryWithTrackingTab(Builder $query): Builder
    {
        $status = TrackingStatus::tryFrom($this->activeTrackingTab ?? '');

        if ($status === null) {
            return $query;
        }

        return $query->where('tracking_status', $status);
    }
}
