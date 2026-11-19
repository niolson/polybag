<?php

namespace App\Filament\Pages\Reports;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Concerns\DescribesReportRange;
use App\Filament\Support\LocalDateRange;
use App\Models\Carrier;
use App\Models\Location;
use App\Models\Package;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class RateComparison extends Page implements HasTable
{
    use DescribesReportRange;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Rate Comparison';

    protected static ?string $title = 'Rate Comparison';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.reports.rate-comparison';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                $this->comparedPackages()
                    ->select([
                        'packages.id',
                        'packages.shipment_id',
                        'packages.shipped_at',
                        'packages.carrier',
                        'rq_bought.quoted_price as selected_price',
                        'rq_bought.carrier as selected_carrier',
                        DB::raw('MIN(rq_session.quoted_price) as cheapest_price'),
                        DB::raw('COUNT(rq_session.id) as rate_quotes_count'),
                        DB::raw('(SELECT rq.carrier FROM rate_quotes rq WHERE rq.package_id = rq_bought.package_id AND rq.created_at = rq_bought.created_at ORDER BY rq.quoted_price ASC, rq.id ASC LIMIT 1) as cheapest_carrier'),
                    ])
                    ->groupBy('packages.id', 'packages.shipment_id', 'packages.shipped_at', 'packages.carrier', 'rq_bought.id', 'rq_bought.package_id', 'rq_bought.created_at', 'rq_bought.quoted_price', 'rq_bought.carrier')
                    ->with('shipment')
            )
            ->defaultSort('shipped_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('shipment.shipment_reference')
                    ->label('Reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('shipped_at')
                    ->label('Date')
                    ->dateTime('M j, Y', timezone: Location::timezone())
                    ->sortable(),
                Tables\Columns\TextColumn::make('selected_rate')
                    ->label('Selected Rate')
                    ->state(fn (Package $record): string => $record->selected_carrier
                        ? Carrier::labelForName($record->selected_carrier).' — $'.number_format((float) $record->selected_price, 2)
                        : '—'),
                Tables\Columns\TextColumn::make('cheapest_rate')
                    ->label('Cheapest Rate')
                    ->state(fn (Package $record): string => $record->cheapest_carrier
                        ? Carrier::labelForName($record->cheapest_carrier).' — $'.number_format((float) $record->cheapest_price, 2)
                        : '—'),
                Tables\Columns\TextColumn::make('savings')
                    ->label('Potential Savings')
                    ->state(function (Package $record): string {
                        $savings = max(0, (float) $record->selected_price - (float) $record->cheapest_price);

                        return '$'.number_format($savings, 2);
                    })
                    ->color(fn (Package $record): string => (float) $record->selected_price > (float) $record->cheapest_price ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('rate_quotes_count')
                    ->label('Quotes')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->default(LocalDateRange::now()->subDays(30)->format('Y-m-d')),
                        DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->columnSpan(2)
                    ->default()
                    ->query(function ($query, array $data) {
                        return LocalDateRange::apply($query, 'packages.shipped_at', $data['from'] ?? null, $data['until'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('carrier')
                    ->options(fn () => Package::query()->where('status', PackageStatus::Shipped)->where('shipped_at', '>=', now()->subDays(90))->whereNotNull('carrier')->distinct()->pluck('carrier')->mapWithKeys(fn (string $carrier): array => [$carrier => Carrier::labelForName($carrier)])->toArray())
                    ->query(fn ($query, array $data) => $data['value'] ? $query->where('packages.carrier', $data['value']) : $query),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(3);
    }

    /**
     * Shipped Packages joined to the quote their active Label was bought from
     * and to every quote of that same quote session, ungrouped.
     *
     * Read through the active Label, not the `selected` flag: after a void and
     * re-buy the voided Label's quote is selected too, and its session priced
     * rates the packer could no longer buy (`release-polish/03`). A quote
     * session is one rate shopping call, whose rows share `created_at`. A
     * Label with no quote — a blind purchase, or a quote since purged — is
     * left out, and so is a session that offered only the rate bought.
     *
     * Joined directly rather than through a derived table, so the date filter
     * on `packages.shipped_at` narrows Packages before the aggregation.
     *
     * @return Builder<Package>
     */
    private function comparedPackages(): Builder
    {
        return Package::query()
            ->where('packages.status', PackageStatus::Shipped->value)
            ->join('package_labels as active_label', function (JoinClause $join): void {
                $join->on('active_label.package_id', '=', 'packages.id')
                    ->whereNull('active_label.voided_at');
            })
            ->join('rate_quotes as rq_bought', 'rq_bought.id', '=', 'active_label.rate_quote_id')
            ->join('rate_quotes as rq_session', function (JoinClause $join): void {
                $join->on('rq_session.package_id', '=', 'rq_bought.package_id')
                    ->on('rq_session.created_at', '=', 'rq_bought.created_at');
            })
            ->havingRaw('COUNT(rq_session.id) >= 2');
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        return Package::find($key);
    }

    public function getTotalPotentialSavings(): float
    {
        return $this->getSavingsSummary()['savings'];
    }

    /**
     * What the page's rows would have saved, against what the same Packages
     * actually paid for the rate selected.
     *
     * @return array{savings: float, spend: float, packages: int}
     */
    public function getSavingsSummary(): array
    {
        $baseQuery = $this->comparedPackages()
            ->select([
                'rq_bought.quoted_price as selected_price',
                DB::raw('MIN(rq_session.quoted_price) as cheapest_price'),
            ])
            ->groupBy('packages.id', 'rq_bought.id', 'rq_bought.quoted_price');

        // Apply active table filters (date range, carrier)
        $baseQuery = $this->applyFiltersToTableQuery($baseQuery)->reorder();

        $totals = DB::query()
            ->selectRaw('COALESCE(SUM(CASE WHEN sub.selected_price > sub.cheapest_price THEN sub.selected_price - sub.cheapest_price ELSE 0 END), 0) as total_savings')
            ->selectRaw('COALESCE(SUM(sub.selected_price), 0) as total_spend')
            ->selectRaw('COUNT(*) as package_count')
            ->fromSub($baseQuery, 'sub')
            ->first();

        return [
            'savings' => (float) $totals->total_savings,
            'spend' => (float) $totals->total_spend,
            'packages' => (int) $totals->package_count,
        ];
    }
}
