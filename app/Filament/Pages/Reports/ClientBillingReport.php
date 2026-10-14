<?php

namespace App\Filament\Pages\Reports;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Shipment;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ClientBillingReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Client Billing';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 35;

    protected string $view = 'filament.pages.reports.client-billing-report';

    public string $viewMode = 'summary';

    public ?int $clientId = null;

    public static function canAccess(): bool
    {
        if (! (auth()->user()?->role->isAtLeast(Role::Manager) ?? false)) {
            return false;
        }

        return app(SettingsService::class)->get('multi_client_enabled', false);
    }

    public function updatedViewMode(): void
    {
        if ($this->viewMode === 'detail' && ! $this->clientId) {
            $this->clientId = Client::where('active', true)->orderBy('name')->first()?->id;
        }

        $this->resetTable();
    }

    public function updatedClientId(): void
    {
        if ($this->clientId !== null && ! Client::where('id', $this->clientId)->where('active', true)->exists()) {
            $this->clientId = null;
        }

        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        if ($this->viewMode === 'detail' && $this->clientId) {
            return $this->detailTable($table);
        }

        return $this->summaryTable($table);
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        if ($this->viewMode === 'detail') {
            return Shipment::where('id', $key)->where('client_id', $this->clientId)->first();
        }

        return Client::find($key);
    }

    #[Computed]
    public function clientOptions(): array
    {
        return Client::orderBy('name')->pluck('name', 'id')->toArray();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    /**
     * The first day of last month. Without overflow, so that on October 31
     * last month is September, not October 1.
     */
    public static function defaultFrom(): string
    {
        return now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d');
    }

    public static function defaultUntil(): string
    {
        return now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d');
    }

    public function exportCsv(): StreamedResponse
    {
        [$from, $until] = $this->billingPeriod();

        if ($this->viewMode === 'detail' && $this->clientId) {
            $unpricedOnly = (bool) ($this->getTableFilterState('unpriced_postage')['isActive'] ?? false);

            return $this->streamDetailCsv($this->clientId, $from, $until, $unpricedOnly);
        }

        return $this->streamSummaryCsv($from, $until);
    }

    /**
     * The period the date filter names, as `[from, until]`.
     *
     * The table and both exports bill the same period: a cleared date is no
     * bound, not a fallback to some other period.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function billingPeriod(): array
    {
        $filters = $this->getTableFilterState('date_range') ?? ['from' => self::defaultFrom(), 'until' => self::defaultUntil()];

        // A date input cleared in the browser arrives as '', not null.
        return [
            filled($filters['from'] ?? null) ? $filters['from'] : null,
            filled($filters['until'] ?? null) ? $filters['until'] : null,
        ];
    }

    private function summaryTable(Table $table): Table
    {
        return $table
            // The period bounds each package inside the query, so the date
            // filter carries no query of its own (`release-polish/28`).
            ->query(fn (): Builder => $this->summaryQuery(...$this->billingPeriod()))
            ->defaultSort('client_name')
            ->defaultKeySort(false)
            ->columns([
                Tables\Columns\TextColumn::make('client_name')
                    ->label('Client')
                    ->sortable(),
                Tables\Columns\TextColumn::make('order_count')
                    ->label('Orders')
                    ->sortable(),
                Tables\Columns\TextColumn::make('package_count')
                    ->label('Packages')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_postage')
                    ->label('Postage')
                    ->money('USD')
                    ->description(fn (Model $record): ?string => $this->uncostedNote($record))
                    ->sortable(),
                Tables\Columns\TextColumn::make('label_fees')
                    ->label('Label Fees')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('pick_fees')
                    ->label('Pick Fees')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_materials')
                    ->label('Materials')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('product_surcharges')
                    ->label('Surcharges')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_billable')
                    ->label('Total Billable')
                    ->money('USD')
                    ->sortable()
                    ->weight('bold'),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->live(onBlur: true)
                            ->default(self::defaultFrom()),
                        DatePicker::make('until')
                            ->live(onBlur: true)
                            ->default(self::defaultUntil()),
                    ])
                    ->columns(2)
                    ->default(),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(2);
    }

    private function detailTable(Table $table): Table
    {
        $client = Client::find($this->clientId);

        return $table
            ->query(fn (): Builder => $this->detailQuery($this->clientId, $client, ...$this->billingPeriod()))
            ->defaultSort('pkg.shipped_at', 'desc')
            ->defaultKeySort(false)
            ->columns([
                Tables\Columns\TextColumn::make('shipment_reference')
                    ->label('Reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('shipped_at')
                    ->label('Date')
                    ->dateTime('M j, Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('package_count')
                    ->label('Pkgs'),
                Tables\Columns\TextColumn::make('postage')
                    ->label('Postage')
                    ->money('USD')
                    ->description(fn (Model $record): ?string => $this->uncostedNote($record)),
                Tables\Columns\TextColumn::make('label_fee')
                    ->label('Label Fee')
                    ->money('USD'),
                Tables\Columns\TextColumn::make('pick_fee_base')
                    ->label('Pick Fee')
                    ->money('USD'),
                Tables\Columns\TextColumn::make('pick_fee_extra')
                    ->label('Extra Items')
                    ->money('USD'),
                Tables\Columns\TextColumn::make('materials')
                    ->label('Materials')
                    ->money('USD'),
                Tables\Columns\TextColumn::make('product_surcharges')
                    ->label('Surcharges')
                    ->money('USD'),
                Tables\Columns\TextColumn::make('has_items')
                    ->label('')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn ($state): ?string => $state ? null : 'No item data'),
                Tables\Columns\TextColumn::make('uncosted_package_count')
                    ->label('')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn ($state): ?string => (int) $state > 0 ? 'Unpriced postage' : null),
                Tables\Columns\TextColumn::make('line_total')
                    ->label('Line Total')
                    ->money('USD')
                    ->weight('bold'),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->live(onBlur: true)
                            ->default(self::defaultFrom()),
                        DatePicker::make('until')
                            ->live(onBlur: true)
                            ->default(self::defaultUntil()),
                    ])
                    ->columns(2)
                    ->default(),
                Tables\Filters\Filter::make('unpriced_postage')
                    ->label('Unpriced postage only')
                    ->toggle()
                    ->query(fn ($query) => $query->where('pkg.uncosted_package_count', '>', 0)),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(2);
    }

    private function summaryQuery(?string $from = null, ?string $until = null): Builder
    {
        $billing = $this->buildBillingBase($from, $until);

        return Client::query()
            ->leftJoinSub($billing, 'billing', 'clients.id', '=', 'billing.client_id')
            ->select([
                'clients.id',
                DB::raw('clients.name as client_name'),
                DB::raw('COALESCE(SUM(billing.carries_shipment_fees), 0) as order_count'),
                DB::raw('COALESCE(SUM(billing.package_count), 0) as package_count'),
                DB::raw('COALESCE(SUM(billing.postage), 0) as total_postage'),
                DB::raw('COALESCE(SUM(billing.uncosted_package_count), 0) as uncosted_package_count'),
                DB::raw('COALESCE(SUM(billing.label_fee), 0) as label_fees'),
                DB::raw('COALESCE(SUM(billing.pick_fee_base + billing.pick_fee_extra), 0) as pick_fees'),
                DB::raw('COALESCE(SUM(billing.materials), 0) as total_materials'),
                DB::raw('COALESCE(SUM(billing.product_surcharges), 0) as product_surcharges'),
                DB::raw('COALESCE(SUM(billing.line_total), 0) as total_billable'),
            ])
            ->where('clients.active', true)
            // A bounded period lists only the clients that shipped in it.
            ->when($from !== null || $until !== null, fn (Builder $query): Builder => $query->whereNotNull('billing.shipment_id'))
            ->groupBy('clients.id', 'clients.name');
    }

    private function detailQuery(int $clientId, ?Client $client, ?string $from = null, ?string $until = null): Builder
    {
        $firstItemFee = number_format((float) ($client?->pick_fee_first_item ?? 0), 2, '.', '');
        $additionalItemFee = number_format((float) ($client?->pick_fee_additional_item ?? 0), 2, '.', '');
        $labelFee = number_format((float) ($client?->label_fee_per_package ?? 0), 2, '.', '');

        return Shipment::query()
            ->where('shipments.client_id', $clientId)
            ->joinSub($this->buildPackageSubquery($from, $until), 'pkg', 'shipments.id', '=', 'pkg.shipment_id')
            ->leftJoinSub($this->buildItemSubquery(), 'itm', 'shipments.id', '=', 'itm.shipment_id')
            ->select([
                'shipments.id',
                'shipments.shipment_reference',
                'pkg.shipped_at',
                'pkg.package_count',
                'pkg.postage',
                'pkg.uncosted_package_count',
                'pkg.weight',
                'pkg.materials',
                DB::raw('CASE WHEN itm.shipment_id IS NOT NULL THEN 1 ELSE 0 END as has_items'),
                DB::raw('pkg.carries_shipment_fees * COALESCE(itm.product_surcharges, 0) as product_surcharges'),
                DB::raw('pkg.package_count * '.$labelFee.' as label_fee'),
                DB::raw('pkg.carries_shipment_fees * '.$firstItemFee.' as pick_fee_base'),
                DB::raw('pkg.carries_shipment_fees * GREATEST(0, COALESCE(itm.total_qty, 0) - 1) * '.$additionalItemFee.' as pick_fee_extra'),
                DB::raw(
                    'pkg.postage + '.
                    'pkg.package_count * '.$labelFee.' + '.
                    'pkg.carries_shipment_fees * ('.
                    $firstItemFee.' + '.
                    'GREATEST(0, COALESCE(itm.total_qty, 0) - 1) * '.$additionalItemFee.' + '.
                    'COALESCE(itm.product_surcharges, 0)) + '.
                    'pkg.materials as line_total'
                ),
            ]);
    }

    /**
     * Each shipment's shipped packages in the period, one row per shipment.
     *
     * Every package bills in the period it shipped, so a shipment whose
     * packages ship in two periods has a line in each (`release-polish/28`).
     * The per-shipment fees — the pick fees and the product surcharges — fall
     * once, on the line of the period its first package shipped in:
     * `carries_shipment_fees` is 1 on that line and 0 on any later one.
     */
    private function buildPackageSubquery(?string $from = null, ?string $until = null): \Illuminate\Database\Query\Builder
    {
        $inPeriod = $this->periodCondition('p.shipped_at', $from, $until);
        $firstInPeriod = $this->periodCondition('MIN(p.shipped_at)', $from, $until);

        return DB::table('packages as p')
            ->leftJoin('box_sizes as bs', 'p.box_size_id', '=', 'bs.id')
            ->where('p.status', PackageStatus::Shipped->value)
            ->select('p.shipment_id')
            ->selectRaw("MIN(CASE WHEN {$inPeriod['sql']} THEN p.shipped_at END) as shipped_at", $inPeriod['bindings'])
            ->selectRaw("SUM(CASE WHEN {$inPeriod['sql']} THEN 1 ELSE 0 END) as package_count", $inPeriod['bindings'])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$inPeriod['sql']} THEN p.cost END), 0) as postage", $inPeriod['bindings'])
            ->selectRaw("SUM(CASE WHEN {$inPeriod['sql']} AND p.cost IS NULL THEN 1 ELSE 0 END) as uncosted_package_count", $inPeriod['bindings'])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$inPeriod['sql']} THEN p.weight END), 0) as weight", $inPeriod['bindings'])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$inPeriod['sql']} THEN COALESCE(bs.materials_cost, 0) END), 0) as materials", $inPeriod['bindings'])
            ->selectRaw("CASE WHEN {$firstInPeriod['sql']} THEN 1 ELSE 0 END as carries_shipment_fees", $firstInPeriod['bindings'])
            ->groupBy('p.shipment_id')
            ->havingRaw("SUM(CASE WHEN {$inPeriod['sql']} THEN 1 ELSE 0 END) > 0", $inPeriod['bindings']);
    }

    /**
     * A SQL condition placing `$column` inside the period, with its bindings.
     * An open bound is no condition.
     *
     * @return array{sql: string, bindings: array<int, string>}
     */
    private function periodCondition(string $column, ?string $from, ?string $until): array
    {
        $conditions = [];
        $bindings = [];

        if ($from !== null) {
            $conditions[] = "{$column} >= ?";
            $bindings[] = $from;
        }

        if ($until !== null) {
            $conditions[] = "{$column} <= ?";
            $bindings[] = $until.' 23:59:59';
        }

        return ['sql' => $conditions === [] ? '1 = 1' : '('.implode(' AND ', $conditions).')', 'bindings' => $bindings];
    }

    /**
     * Packages on the line that reported no postage.
     *
     * `packages.cost` is null when the seller reports no price — a Shopify
     * Shipping label always, a manual ship or a failed cost write sometimes.
     * The billing queries sum that as $0.00, so the line under-invoices the
     * client by the whole postage amount while every other fee charges
     * normally. Until the charging half of that is decided, the gap is at
     * least visible on the row it is missing from.
     */
    private function uncostedNote(Model $record): ?string
    {
        $uncosted = (int) ($record->uncosted_package_count ?? 0);

        if ($uncosted < 1) {
            return null;
        }

        return number_format($uncosted).' billed at $0.00 — no reported postage';
    }

    private function buildItemSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('shipment_items as si')
            ->leftJoin('products as pr', 'si.product_id', '=', 'pr.id')
            ->select([
                'si.shipment_id',
                DB::raw('SUM(si.quantity) as total_qty'),
                DB::raw('COALESCE(SUM(si.quantity * COALESCE(pr.handling_surcharge, 0)), 0) as product_surcharges'),
            ])
            ->groupBy('si.shipment_id');
    }

    private function buildBillingBase(?string $from = null, ?string $until = null): \Illuminate\Database\Query\Builder
    {
        return DB::table('shipments as s')
            ->join('clients as c', 's.client_id', '=', 'c.id')
            ->joinSub($this->buildPackageSubquery($from, $until), 'pkg', 's.id', '=', 'pkg.shipment_id')
            ->leftJoinSub($this->buildItemSubquery(), 'itm', 's.id', '=', 'itm.shipment_id')
            ->select([
                's.id as shipment_id',
                's.client_id',
                'pkg.shipped_at',
                'pkg.package_count',
                'pkg.postage',
                'pkg.uncosted_package_count',
                'pkg.materials',
                'pkg.carries_shipment_fees',
                DB::raw('pkg.carries_shipment_fees * COALESCE(itm.product_surcharges, 0) as product_surcharges'),
                DB::raw('pkg.package_count * COALESCE(c.label_fee_per_package, 0) as label_fee'),
                DB::raw('pkg.carries_shipment_fees * COALESCE(c.pick_fee_first_item, 0) as pick_fee_base'),
                DB::raw('pkg.carries_shipment_fees * GREATEST(0, COALESCE(itm.total_qty, 0) - 1) * COALESCE(c.pick_fee_additional_item, 0) as pick_fee_extra'),
                DB::raw(
                    'pkg.postage + '.
                    'pkg.package_count * COALESCE(c.label_fee_per_package, 0) + '.
                    'pkg.carries_shipment_fees * ('.
                    'COALESCE(c.pick_fee_first_item, 0) + '.
                    'GREATEST(0, COALESCE(itm.total_qty, 0) - 1) * COALESCE(c.pick_fee_additional_item, 0) + '.
                    'COALESCE(itm.product_surcharges, 0)) + '.
                    'pkg.materials as line_total'
                ),
            ]);
    }

    private function streamSummaryCsv(?string $from, ?string $until): StreamedResponse
    {
        $rows = $this->summaryQuery($from, $until)->get();

        $filename = 'client-billing-summary-'.($from ?? 'start').'-to-'.($until ?? 'today').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Client', 'Orders', 'Packages', 'Postage', 'Unpriced Packages', 'Label Fees', 'Pick Fees', 'Materials', 'Surcharges', 'Total Billable']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->client_name,
                    $row->order_count,
                    $row->package_count,
                    number_format((float) $row->total_postage, 2),
                    (int) $row->uncosted_package_count,
                    number_format((float) $row->label_fees, 2),
                    number_format((float) $row->pick_fees, 2),
                    number_format((float) $row->total_materials, 2),
                    number_format((float) $row->product_surcharges, 2),
                    number_format((float) $row->total_billable, 2),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function streamDetailCsv(int $clientId, ?string $from, ?string $until, bool $unpricedOnly = false): StreamedResponse
    {
        $client = Client::find($clientId);

        $rows = $this->detailQuery($clientId, $client, $from, $until)
            ->when($unpricedOnly, fn ($q) => $q->where('pkg.uncosted_package_count', '>', 0))
            ->orderBy('pkg.shipped_at')
            ->get();

        $filename = 'client-billing-'.$client?->code.'-'.($from ?? 'start').'-to-'.($until ?? 'today').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Date', 'Packages', 'Postage', 'Unpriced Packages', 'Label Fee', 'Pick Fee', 'Extra Items', 'Materials', 'Surcharges', 'No Item Data', 'Line Total']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->shipment_reference,
                    $row->shipped_at,
                    $row->package_count,
                    number_format((float) $row->postage, 2),
                    (int) $row->uncosted_package_count,
                    number_format((float) $row->label_fee, 2),
                    number_format((float) $row->pick_fee_base, 2),
                    number_format((float) $row->pick_fee_extra, 2),
                    number_format((float) $row->materials, 2),
                    number_format((float) $row->product_surcharges, 2),
                    $row->has_items ? '' : 'yes',
                    number_format((float) $row->line_total, 2),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
