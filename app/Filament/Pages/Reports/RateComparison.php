<?php

namespace App\Filament\Pages\Reports;

use App\Enums\Role;
use App\Models\Package;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class RateComparison extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Rate Comparison';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.reports.rate-comparison';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    /**
     * Pre-aggregated rate quote stats subquery. Scans rate_quotes once,
     * groups by package_id, and filters to packages with 2+ quotes
     * including a selected one. Uses covering index on MySQL.
     */
    private function rateQuoteStats(): \Illuminate\Database\Query\Builder
    {
        return DB::table('rate_quotes')
            ->select([
                'package_id',
                DB::raw('COUNT(*) as quote_count'),
                DB::raw('MAX(CASE WHEN selected = 1 THEN quoted_price END) as selected_price'),
                DB::raw('MAX(CASE WHEN selected = 1 THEN carrier END) as selected_carrier'),
                DB::raw('MIN(quoted_price) as cheapest_price'),
            ])
            ->groupBy('package_id')
            ->havingRaw('quote_count >= 2 AND MAX(CASE WHEN selected = 1 THEN 1 END) = 1');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Package::query()
                    ->where('packages.shipped', true)
                    ->joinSub($this->rateQuoteStats(), 'rq_stats', 'rq_stats.package_id', '=', 'packages.id')
                    ->select([
                        'packages.*',
                        'rq_stats.selected_price',
                        'rq_stats.selected_carrier',
                        'rq_stats.cheapest_price',
                        'rq_stats.quote_count as rate_quotes_count',
                        // Correlated subquery for cheapest carrier — only evaluated
                        // for the paginated rows, not the full dataset.
                        DB::raw('(SELECT rq.carrier FROM rate_quotes rq WHERE rq.package_id = packages.id ORDER BY rq.quoted_price ASC LIMIT 1) as cheapest_carrier'),
                    ])
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
                    ->default()
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->where('shipped_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->where('shipped_at', '<=', $date));
                    }),
                Tables\Filters\SelectFilter::make('carrier')
                    ->options(fn () => Package::query()->where('shipped', true)->whereNotNull('carrier')->distinct()->pluck('carrier', 'carrier')->toArray())
                    ->query(fn ($query, array $data) => $data['value'] ? $query->where('packages.carrier', $data['value']) : $query),
            ]);
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        return Package::find($key);
    }

    public function getTotalPotentialSavings(): float
    {
        $baseQuery = Package::query()
            ->where('packages.shipped', true)
            ->joinSub($this->rateQuoteStats(), 'rq_stats', 'rq_stats.package_id', '=', 'packages.id')
            ->select([
                'rq_stats.selected_price',
                'rq_stats.cheapest_price',
            ]);

        // Apply active table filters (date range, carrier)
        $baseQuery = $this->applyFiltersToTableQuery($baseQuery)->reorder();

        return (float) DB::query()
            ->selectRaw('COALESCE(SUM(CASE WHEN sub.selected_price > sub.cheapest_price THEN sub.selected_price - sub.cheapest_price ELSE 0 END), 0) as total_savings')
            ->fromSub($baseQuery, 'sub')
            ->value('total_savings');
    }
}
