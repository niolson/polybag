<?php

namespace App\Filament\Pages\Reports;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Models\Package;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class RateComparison extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Rate Comparison';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.reports.rate-comparison';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    public function table(Table $table): Table
    {
        // JOIN rate_quotes directly (not as a derived table) so the date
        // filter on packages.shipped_at applies BEFORE the aggregation.
        // MySQL uses the (status, shipped_at) composite index to narrow
        // packages first, then aggregates only their rate_quotes.
        return $table
            ->query(
                Package::query()
                    ->where('packages.status', PackageStatus::Shipped->value)
                    ->join('rate_quotes as rq_all', 'rq_all.package_id', '=', 'packages.id')
                    ->select([
                        'packages.id',
                        'packages.shipment_id',
                        'packages.shipped_at',
                        'packages.carrier',
                        DB::raw('MAX(CASE WHEN rq_all.selected = 1 THEN rq_all.quoted_price END) as selected_price'),
                        DB::raw('MAX(CASE WHEN rq_all.selected = 1 THEN rq_all.carrier END) as selected_carrier'),
                        DB::raw('MIN(rq_all.quoted_price) as cheapest_price'),
                        DB::raw('COUNT(rq_all.id) as rate_quotes_count'),
                        DB::raw('(SELECT rq.carrier FROM rate_quotes rq WHERE rq.package_id = packages.id ORDER BY rq.quoted_price ASC LIMIT 1) as cheapest_carrier'),
                    ])
                    ->groupBy('packages.id', 'packages.shipment_id', 'packages.shipped_at', 'packages.carrier')
                    ->havingRaw('COUNT(rq_all.id) >= 2 AND MAX(CASE WHEN rq_all.selected = 1 THEN 1 END) = 1')
                    ->with('shipment')
            )
            ->defaultSort('shipped_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('shipment.shipment_reference')
                    ->label('Reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('shipped_at')
                    ->label('Date')
                    ->dateTime('M j, Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('selected_rate')
                    ->label('Selected Rate')
                    ->state(fn (Package $record) => $record->selected_carrier
                        ? "{$record->selected_carrier} — \${$record->selected_price}"
                        : '—'),
                Tables\Columns\TextColumn::make('cheapest_rate')
                    ->label('Cheapest Rate')
                    ->state(fn (Package $record) => $record->cheapest_carrier
                        ? "{$record->cheapest_carrier} — \${$record->cheapest_price}"
                        : '—'),
                Tables\Columns\TextColumn::make('savings')
                    ->label('Potential Savings')
                    ->state(function (Package $record) {
                        $savings = max(0, (float) $record->selected_price - (float) $record->cheapest_price);

                        return '$'.number_format($savings, 2);
                    })
                    ->color(fn (Package $record) => (float) $record->selected_price > (float) $record->cheapest_price ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('rate_quotes_count')
                    ->label('Quotes')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->default(now()->subDays(30)->format('Y-m-d')),
                        \Filament\Forms\Components\DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->columnSpan(2)
                    ->default()
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->where('shipped_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->where('shipped_at', '<=', $date));
                    }),
                Tables\Filters\SelectFilter::make('carrier')
                    ->options(fn () => Package::query()->where('status', PackageStatus::Shipped)->where('shipped_at', '>=', now()->subDays(90))->whereNotNull('carrier')->distinct()->pluck('carrier', 'carrier')->toArray())
                    ->query(fn ($query, array $data) => $data['value'] ? $query->where('packages.carrier', $data['value']) : $query),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(3);
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        return Package::find($key);
    }

    public function getTotalPotentialSavings(): float
    {
        $baseQuery = Package::query()
            ->where('packages.status', PackageStatus::Shipped->value)
            ->join('rate_quotes as rq_all', 'rq_all.package_id', '=', 'packages.id')
            ->select([
                DB::raw('MAX(CASE WHEN rq_all.selected = 1 THEN rq_all.quoted_price END) as selected_price'),
                DB::raw('MIN(rq_all.quoted_price) as cheapest_price'),
            ])
            ->groupBy('packages.id')
            ->havingRaw('COUNT(rq_all.id) >= 2 AND MAX(CASE WHEN rq_all.selected = 1 THEN 1 END) = 1');

        // Apply active table filters (date range, carrier)
        $baseQuery = $this->applyFiltersToTableQuery($baseQuery)->reorder();

        return (float) DB::query()
            ->selectRaw('COALESCE(SUM(CASE WHEN sub.selected_price > sub.cheapest_price THEN sub.selected_price - sub.cheapest_price ELSE 0 END), 0) as total_savings')
            ->fromSub($baseQuery, 'sub')
            ->value('total_savings');
    }
}
