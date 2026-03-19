<?php

namespace App\Filament\Pages\Reports;

use App\Enums\Deliverability;
use App\Enums\LabelBatchItemStatus;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Models\LabelBatchItem;
use App\Models\Package;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PackingValidationReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Packing Validation';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.reports.packing-validation-report';

    public string $section = 'weight_mismatches';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    public function updatedSection(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return match ($this->section) {
            'batch_failures' => $this->batchFailuresTable($table),
            'validation_issues' => $this->validationIssuesTable($table),
            default => $this->weightMismatchTable($table),
        };
    }

    private function weightMismatchTable(Table $table): Table
    {
        // Uses the pre-computed weight_mismatch flag (set at pack time,
        // backfilled via packages:backfill-weight-mismatch). No JOINs needed.
        return $table
            ->query(
                Package::query()
                    ->where('status', PackageStatus::Shipped)
                    ->where('weight_mismatch', true)
                    ->with('shipment')
            )
            ->defaultSort('shipped_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('shipment.shipment_reference')
                    ->label('Reference'),
                Tables\Columns\TextColumn::make('shipped_at')
                    ->label('Date')
                    ->dateTime('M j, Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('weight')
                    ->label('Actual Weight')
                    ->suffix(' lbs')
                    ->sortable(),
                Tables\Columns\TextColumn::make('expected_weight')
                    ->label('Expected Weight')
                    ->suffix(' lbs')
                    ->state(fn (Package $record) => number_format($this->calculateExpectedWeight($record), 2)),
                Tables\Columns\TextColumn::make('difference')
                    ->label('Difference %')
                    ->state(function (Package $record) {
                        $expected = $this->calculateExpectedWeight($record);
                        if ($expected == 0) {
                            return '—';
                        }
                        $diff = abs((float) $record->weight - $expected) / max((float) $record->weight, 0.01) * 100;

                        return number_format($diff, 1).'%';
                    })
                    ->color('warning'),
                Tables\Columns\TextColumn::make('carrier')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->default(now()->subDays(7)->format('Y-m-d')),
                        DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->default()
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->where('shipped_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->where('shipped_at', '<=', $date));
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(2);
    }

    private function batchFailuresTable(Table $table): Table
    {
        return $table
            ->query(
                LabelBatchItem::query()
                    ->where('status', LabelBatchItemStatus::Failed)
                    ->with(['shipment', 'labelBatch'])
            )
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('shipment.shipment_reference')
                    ->label('Reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                Tables\Columns\TextColumn::make('labelBatch.id')
                    ->label('Batch #'),
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(80)
                    ->tooltip(fn ($record) => $record->error_message),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->default(now()->subDays(7)->format('Y-m-d')),
                        DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->default()
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->where('created_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->where('created_at', '<=', $date));
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(2);
    }

    private function validationIssuesTable(Table $table): Table
    {
        return $table
            ->query(
                Package::query()
                    ->where('packages.status', PackageStatus::Shipped->value)
                    ->join('shipments', 'packages.shipment_id', '=', 'shipments.id')
                    ->where('shipments.deliverability', '!=', Deliverability::Yes)
                    ->select([
                        'packages.id',
                        'packages.shipment_id',
                        'packages.shipped_at',
                        'packages.carrier',
                        'packages.tracking_number',
                        'shipments.shipment_reference',
                        'shipments.deliverability',
                        'shipments.validation_message',
                    ])
            )
            ->defaultSort('shipped_at', 'desc')
            ->defaultKeySort(false)
            ->columns([
                Tables\Columns\TextColumn::make('shipment_reference')
                    ->label('Reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('shipped_at')
                    ->label('Date')
                    ->dateTime('M j, Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('deliverability')
                    ->badge(),
                Tables\Columns\TextColumn::make('validation_message')
                    ->label('Validation Message')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->validation_message),
                Tables\Columns\TextColumn::make('carrier'),
                Tables\Columns\TextColumn::make('tracking_number')
                    ->label('Tracking'),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->default(now()->subDays(7)->format('Y-m-d')),
                        DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->default()
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->where('packages.shipped_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->where('packages.shipped_at', '<=', $date));
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(2);
    }

    private function calculateExpectedWeight(Package $record): float
    {
        $record->loadMissing('packageItems.product');

        return (float) $record->packageItems->sum(
            fn ($item) => ($item->product?->weight ?? 0) * $item->quantity
        );
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        if ($this->section === 'batch_failures') {
            return LabelBatchItem::find($key);
        }

        return Package::find($key);
    }

    public function getWeightMismatchCount(): int
    {
        return Package::query()
            ->where('status', PackageStatus::Shipped)
            ->where('weight_mismatch', true)
            ->where('shipped_at', '>=', now()->subDays(7))
            ->count();
    }

    public function getBatchFailureCount(): int
    {
        return LabelBatchItem::where('status', LabelBatchItemStatus::Failed)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
    }

    public function getValidationIssueCount(): int
    {
        return Package::query()
            ->where('packages.status', PackageStatus::Shipped->value)
            ->where('packages.shipped_at', '>=', now()->subDays(7))
            ->join('shipments', 'packages.shipment_id', '=', 'shipments.id')
            ->where('shipments.deliverability', '!=', Deliverability::Yes)
            ->count();
    }
}
