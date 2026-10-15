<?php

namespace App\Filament\Resources\LabelBatchResource\Pages;

use App\Enums\LabelBatchStatus;
use App\Filament\Resources\LabelBatchResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListLabelBatches extends ListRecords
{
    protected static string $resource = LabelBatchResource::class;

    #[Url(as: 'status_tab')]
    public ?string $activeStatusTab = 'all';

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->modifyQueryWithStatusTab($query));
    }

    public function updatedActiveStatusTab(): void
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
                'label' => 'Status',
                'alignment' => 'start',
                'property' => 'activeStatusTab',
                'tabs' => [
                    'all' => 'All',
                    LabelBatchStatus::Pending->value => 'Pending',
                    LabelBatchStatus::Processing->value => 'Processing',
                    LabelBatchStatus::Completed->value => 'Completed',
                    LabelBatchStatus::CompletedWithErrors->value => 'Completed with Errors',
                    LabelBatchStatus::Failed->value => 'Failed',
                ],
            ],
        ];
    }

    protected function modifyQueryWithStatusTab(Builder $query): Builder
    {
        $status = LabelBatchStatus::tryFrom($this->activeStatusTab ?? '');

        if ($status === null) {
            return $query;
        }

        return $query->where('status', $status);
    }
}
