<?php

namespace App\Filament\Resources\ShipmentResource\Pages;

use App\Enums\Deliverability;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\ShipmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListShipments extends ListRecords
{
    protected static string $resource = ShipmentResource::class;

    #[Url(as: 'status_tab')]
    public ?string $activeStatusTab = 'all';

    #[Url(as: 'deliverability_tab')]
    public ?string $activeDeliverabilityTab = 'all';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.components.list-record-tab-groups')
                    ->viewData([
                        'groups' => $this->getTabGroups(),
                    ]),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->modifyQueryWithStatusTab($query))
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->modifyQueryWithDeliverabilityTab($query));
    }

    public function updatedActiveStatusTab(): void
    {
        $this->resetPage();
    }

    public function updatedActiveDeliverabilityTab(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getTabGroups(): array
    {
        return [
            [
                'label' => 'Shipment Status',
                'alignment' => 'start',
                'property' => 'activeStatusTab',
                'tabs' => [
                    'all' => 'All',
                    ShipmentStatus::Open->value => 'Open',
                    ShipmentStatus::Shipped->value => 'Shipped',
                    ShipmentStatus::Void->value => 'Void',
                ],
            ],
            [
                'label' => 'Deliverability',
                'alignment' => 'end',
                'property' => 'activeDeliverabilityTab',
                'tabs' => [
                    'all' => 'All',
                    Deliverability::NotChecked->value => 'Not Checked',
                    Deliverability::Yes->value => 'Yes',
                    Deliverability::Maybe->value => 'Maybe',
                    Deliverability::No->value => 'No',
                ],
            ],
        ];
    }

    protected function modifyQueryWithStatusTab(Builder $query): Builder
    {
        return match ($this->activeStatusTab) {
            ShipmentStatus::Open->value => $query->where('status', ShipmentStatus::Open),
            ShipmentStatus::Shipped->value => $query->where('status', ShipmentStatus::Shipped),
            ShipmentStatus::Void->value => $query->where('status', ShipmentStatus::Void),
            default => $query,
        };
    }

    protected function modifyQueryWithDeliverabilityTab(Builder $query): Builder
    {
        return match ($this->activeDeliverabilityTab) {
            Deliverability::NotChecked->value => $query->where('deliverability', Deliverability::NotChecked),
            Deliverability::Yes->value => $query->where('deliverability', Deliverability::Yes),
            Deliverability::Maybe->value => $query->where('deliverability', Deliverability::Maybe),
            Deliverability::No->value => $query->where('deliverability', Deliverability::No),
            default => $query,
        };
    }
}
