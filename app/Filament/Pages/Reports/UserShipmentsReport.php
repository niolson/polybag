<?php

namespace App\Filament\Pages\Reports;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Concerns\DescribesReportRange;
use App\Filament\Concerns\HasMultiClientFilter;
use App\Filament\Support\LocalDateRange;
use App\Models\Package;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Shipped packages by the user who bought their Label, per user or per user
 * and period. Grouped by user id, so two users who share a name stay apart.
 */
class UserShipmentsReport extends Page implements HasTable
{
    use DescribesReportRange;
    use HasMultiClientFilter;
    use InteractsWithTable;

    /**
     * The Group by choices, in the order the control lists them.
     *
     * @var array<string, string>
     */
    public const GROUPINGS = [
        'user' => 'User',
        'day' => 'User and day',
        'week' => 'User and week',
        'month' => 'User and month',
    ];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'Packages by User';

    protected static ?string $title = 'Packages by User';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 33;

    protected string $view = 'filament.pages.reports.user-shipments-report';

    public string $groupBy = 'user';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    public function updatedGroupBy(): void
    {
        if (! array_key_exists($this->groupBy, self::GROUPINGS)) {
            $this->groupBy = 'user';
        }

        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $filters = [
            Tables\Filters\Filter::make('date_range')
                ->form([
                    DatePicker::make('from')
                        ->default(LocalDateRange::now()->subDays(30)->format('Y-m-d')),
                    DatePicker::make('until'),
                ])
                ->columns(2)
                ->default()
                ->query(fn (Builder $query, array $data): Builder => LocalDateRange::apply($query, 'packages.shipped_at', $data['from'] ?? null, $data['until'] ?? null)),
        ];

        if ($filter = $this->buildClientFilter()) {
            $filters[] = $filter;
        }

        $byUser = $this->groupBy === 'user';

        return $table
            ->query(fn (): Builder => $this->groupedQuery())
            ->defaultSort($byUser ? 'package_count' : 'period', 'desc')
            ->defaultKeySort(false)
            ->columns([
                Tables\Columns\TextColumn::make('period')
                    ->label('Period')
                    ->formatStateUsing(fn (?string $state): string => $this->periodLabel((string) $state))
                    ->sortable()
                    ->visible(! $byUser),
                Tables\Columns\TextColumn::make('user_name')
                    ->label('User')
                    ->sortable(),
                Tables\Columns\TextColumn::make('package_count')
                    ->label('Packages')
                    ->numeric()
                    ->summarize(Sum::make()->label('Total'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('order_count')
                    ->label('Orders')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('item_count')
                    ->label('Items')
                    ->numeric()
                    ->summarize(Sum::make()->label('Total'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('active_days')
                    ->label('Days Active')
                    ->numeric()
                    ->sortable()
                    ->visible($byUser),
                Tables\Columns\TextColumn::make('per_active_day')
                    ->label('Packages / Active Day')
                    ->numeric(decimalPlaces: 1)
                    ->sortable()
                    ->visible($byUser),
                Tables\Columns\TextColumn::make('voided_count')
                    ->label('Labels Voided')
                    ->numeric()
                    ->summarize(Sum::make()->label('Total'))
                    ->sortable()
                    ->visible($byUser),
            ])
            ->filters($filters, layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(2);
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        return Package::find($key);
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
     * The rows the table shows, filtered and sorted as shown.
     */
    public function exportCsv(): StreamedResponse
    {
        $rows = $this->getFilteredSortedTableQuery()?->get() ?? collect();
        $byUser = $this->groupBy === 'user';
        $filename = 'packages-by-user-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $byUser): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $byUser
                ? ['User', 'Packages', 'Orders', 'Items', 'Days Active', 'Packages / Active Day', 'Labels Voided']
                : ['Period', 'User', 'Packages', 'Orders', 'Items']);

            foreach ($rows as $row) {
                $counts = [
                    (int) $row->getAttribute('package_count'),
                    (int) $row->getAttribute('order_count'),
                    (int) $row->getAttribute('item_count'),
                ];

                fputcsv($out, $byUser
                    ? [$row->getAttribute('user_name'), ...$counts, (int) $row->getAttribute('active_days'), number_format((float) $row->getAttribute('per_active_day'), 1, '.', ''), (int) $row->getAttribute('voided_count')]
                    : [$this->periodLabel((string) $row->getAttribute('period')), $row->getAttribute('user_name'), ...$counts]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Shipped packages grouped by the user who shipped them, and by period
     * when one is chosen.
     *
     * @return Builder<Package>
     */
    private function groupedQuery(): Builder
    {
        $items = DB::table('package_items')
            ->select('package_id', DB::raw('SUM(quantity) as quantity'))
            ->groupBy('package_id');

        $query = Package::query()
            ->where('packages.status', PackageStatus::Shipped->value)
            ->whereNotNull('packages.shipped_by_user_id')
            ->join('users', 'packages.shipped_by_user_id', '=', 'users.id')
            ->join('shipments', 'packages.shipment_id', '=', 'shipments.id')
            ->leftJoinSub($items, 'item_totals', 'item_totals.package_id', '=', 'packages.id')
            ->select([
                DB::raw('users.id as user_id'),
                DB::raw('users.name as user_name'),
                DB::raw('COUNT(*) as package_count'),
                DB::raw('COUNT(DISTINCT packages.shipment_id) as order_count'),
                DB::raw('COALESCE(SUM(item_totals.quantity), 0) as item_count'),
                DB::raw('MIN(packages.id) as id'),
            ]);

        if ($this->groupBy !== 'user') {
            return $query
                ->addSelect(DB::raw($this->periodExpression().' as period'))
                ->groupBy('users.id', 'users.name', 'period');
        }

        [$voided, $bindings] = $this->voidedCountSubquery();

        return $query
            ->addSelect([
                DB::raw('COUNT(DISTINCT packages.ship_date) as active_days'),
                DB::raw('1.0 * COUNT(*) / COUNT(DISTINCT packages.ship_date) as per_active_day'),
            ])
            ->selectRaw("({$voided}) as voided_count", $bindings)
            ->groupBy('users.id', 'users.name');
    }

    /**
     * Labels the user voided in the period, whichever user had bought them,
     * scoped to the chosen client like the rest of the row.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function voidedCountSubquery(): array
    {
        $range = $this->tableFilters['date_range'] ?? [];
        $bounds = LocalDateRange::utcBounds($range['from'] ?? null, $range['until'] ?? null);
        $clientId = $this->tableFilters['client']['client_id'] ?? null;

        $sql = 'SELECT COUNT(*) FROM package_labels voided_labels';
        $conditions = ['voided_labels.voided_by_user_id = users.id', 'voided_labels.voided_at IS NOT NULL'];
        $bindings = [];

        if (filled($clientId) && $this->buildClientFilter() !== null) {
            $sql .= ' JOIN packages voided_packages ON voided_packages.id = voided_labels.package_id'
                .' JOIN shipments voided_shipments ON voided_shipments.id = voided_packages.shipment_id';
            $conditions[] = 'voided_shipments.client_id = ?';
            $bindings[] = $clientId;
        }

        if ($bounds['from'] !== null) {
            $conditions[] = 'voided_labels.voided_at >= ?';
            $bindings[] = $bounds['from'];
        }

        if ($bounds['until'] !== null) {
            $conditions[] = 'voided_labels.voided_at < ?';
            $bindings[] = $bounds['until'];
        }

        return [$sql.' WHERE '.implode(' AND ', $conditions), $bindings];
    }

    /**
     * The period of a package's ship date: the day, the Monday that starts
     * its week, or the month.
     */
    private function periodExpression(): string
    {
        $sqlite = DB::getDriverName() === 'sqlite';

        return match ($this->groupBy) {
            'week' => $sqlite
                ? "date(packages.ship_date, 'weekday 0', '-6 days')"
                : 'DATE_SUB(DATE(packages.ship_date), INTERVAL WEEKDAY(packages.ship_date) DAY)',
            'month' => $sqlite
                ? "strftime('%Y-%m', packages.ship_date)"
                : "DATE_FORMAT(packages.ship_date, '%Y-%m')",
            default => $sqlite
                ? "strftime('%Y-%m-%d', packages.ship_date)"
                : 'DATE(packages.ship_date)',
        };
    }

    /**
     * A week as its dates ("Oct 12–18"), a month by name, a day as its date.
     */
    private function periodLabel(string $period): string
    {
        if ($period === '') {
            return '';
        }

        return match ($this->groupBy) {
            'week' => (function () use ($period): string {
                $start = Carbon::parse($period);
                $end = $start->copy()->endOfWeek();

                return $start->month === $end->month
                    ? $start->format('M j').'–'.$end->format('j')
                    : $start->format('M j').' – '.$end->format('M j');
            })(),
            'month' => Carbon::createFromFormat('Y-m', $period)->format('F Y'),
            default => Carbon::parse($period)->format('M j, Y'),
        };
    }
}
