<?php

namespace App\Filament\Pages\Reports;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Concerns\DescribesReportRange;
use App\Filament\Support\LocalDateRange;
use App\Models\Client;
use App\Models\Package;
use App\Models\User;
use App\Services\SettingsService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Shipped packages by the user who bought their Label, per user or per user
 * and period, on the packages' ship date. Grouped by user id, so two users
 * who share a name stay apart.
 */
class UserShipmentsReport extends Page implements HasTable
{
    use DescribesReportRange;
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

    protected static ?int $navigationSort = 31;

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
                // The range bounds the subqueries in groupedQuery(), so the
                // filter carries no query of its own.
                ->query(fn (Builder $query): Builder => $query),
        ];

        if ($this->multiClientEnabled()) {
            $filters[] = Tables\Filters\Filter::make('client')
                ->form([
                    Select::make('client_id')
                        ->label('Client')
                        ->options(fn (): array => Client::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->native(false)
                        ->placeholder('All clients'),
                ])
                ->query(fn (Builder $query): Builder => $query);
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
        return $this->groupBy === 'user' ? User::find($key) : Package::find($key);
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
     * Per user: the packages they shipped and the Labels they voided in the
     * range, from users with either. Per user and period: the packages they
     * shipped.
     *
     * Packages count on their ship date, the Location's calendar day the
     * Label is dated for, as on Volume and the Packages list; a Label bought
     * after the carrier cutoff is dated for the next pickup day. Voids count
     * on the local day they were made.
     *
     * @return Builder<User>|Builder<Package>
     */
    private function groupedQuery(): Builder
    {
        if ($this->groupBy !== 'user') {
            return Package::query()
                ->tap(fn (Builder $query): Builder => $this->shippedInRange($query))
                ->join('users', 'packages.shipped_by_user_id', '=', 'users.id')
                ->select([
                    DB::raw('users.id as user_id'),
                    DB::raw('users.name as user_name'),
                    DB::raw('COUNT(*) as package_count'),
                    DB::raw('COUNT(DISTINCT packages.shipment_id) as order_count'),
                    DB::raw('COALESCE(SUM(item_totals.quantity), 0) as item_count'),
                    DB::raw('MIN(packages.id) as id'),
                    DB::raw($this->periodExpression().' as period'),
                ])
                ->groupBy('users.id', 'users.name', 'period');
        }

        $shipped = $this->shippedInRange(Package::query())
            ->toBase()
            ->select([
                DB::raw('packages.shipped_by_user_id as user_id'),
                DB::raw('COUNT(*) as package_count'),
                DB::raw('COUNT(DISTINCT packages.shipment_id) as order_count'),
                DB::raw('COALESCE(SUM(item_totals.quantity), 0) as item_count'),
                DB::raw('COUNT(DISTINCT packages.ship_date) as active_days'),
            ])
            ->groupBy('packages.shipped_by_user_id');

        return User::query()
            ->leftJoinSub($shipped, 'shipped', 'shipped.user_id', '=', 'users.id')
            ->leftJoinSub($this->voidedInRange(), 'voided', 'voided.user_id', '=', 'users.id')
            ->where(fn (Builder $query): Builder => $query->whereNotNull('shipped.user_id')->orWhereNotNull('voided.user_id'))
            ->select([
                'users.id',
                DB::raw('users.name as user_name'),
                DB::raw('COALESCE(shipped.package_count, 0) as package_count'),
                DB::raw('COALESCE(shipped.order_count, 0) as order_count'),
                DB::raw('COALESCE(shipped.item_count, 0) as item_count'),
                DB::raw('COALESCE(shipped.active_days, 0) as active_days'),
                DB::raw('CASE WHEN shipped.active_days > 0 THEN 1.0 * shipped.package_count / shipped.active_days ELSE NULL END as per_active_day'),
                DB::raw('COALESCE(voided.voided_count, 0) as voided_count'),
            ]);
    }

    /**
     * Shipped packages with a shipping user, on a ship date in range, for the
     * chosen client, with each package's item quantity joined on.
     *
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    private function shippedInRange(Builder $query): Builder
    {
        ['from' => $from, 'until' => $until] = $this->dateRange();
        $clientId = $this->clientId();

        $items = DB::table('package_items')
            ->select('package_id', DB::raw('SUM(quantity) as quantity'))
            ->groupBy('package_id');

        return $query
            ->where('packages.status', PackageStatus::Shipped->value)
            ->whereNotNull('packages.shipped_by_user_id')
            ->leftJoinSub($items, 'item_totals', 'item_totals.package_id', '=', 'packages.id')
            ->when($from, fn (Builder $query, string $date): Builder => $query->whereDate('packages.ship_date', '>=', $date))
            ->when($until, fn (Builder $query, string $date): Builder => $query->whereDate('packages.ship_date', '<=', $date))
            ->when($clientId, fn (Builder $query, string $clientId): Builder => $query
                ->join('shipments', 'packages.shipment_id', '=', 'shipments.id')
                ->where('shipments.client_id', $clientId));
    }

    /**
     * Labels each user voided in range, whoever bought them, for the chosen
     * client.
     */
    private function voidedInRange(): QueryBuilder
    {
        ['from' => $from, 'until' => $until] = $this->dateRange();
        $bounds = LocalDateRange::utcBounds($from, $until);
        $clientId = $this->clientId();

        return DB::table('package_labels as voided_labels')
            ->whereNotNull('voided_labels.voided_at')
            ->whereNotNull('voided_labels.voided_by_user_id')
            ->when($bounds['from'], fn (QueryBuilder $query, string $bound): QueryBuilder => $query->where('voided_labels.voided_at', '>=', $bound))
            ->when($bounds['until'], fn (QueryBuilder $query, string $bound): QueryBuilder => $query->where('voided_labels.voided_at', '<', $bound))
            ->when($clientId, fn (QueryBuilder $query, string $clientId): QueryBuilder => $query
                ->join('packages as voided_packages', 'voided_packages.id', '=', 'voided_labels.package_id')
                ->join('shipments as voided_shipments', 'voided_shipments.id', '=', 'voided_packages.shipment_id')
                ->where('voided_shipments.client_id', $clientId))
            ->select([
                DB::raw('voided_labels.voided_by_user_id as user_id'),
                DB::raw('COUNT(*) as voided_count'),
            ])
            ->groupBy('voided_labels.voided_by_user_id');
    }

    /**
     * @return array{from: ?string, until: ?string}
     */
    private function dateRange(): array
    {
        $state = $this->tableFilters['date_range'] ?? [];

        return [
            'from' => filled($state['from'] ?? null) ? (string) $state['from'] : null,
            'until' => filled($state['until'] ?? null) ? (string) $state['until'] : null,
        ];
    }

    private function clientId(): ?string
    {
        if (! $this->multiClientEnabled()) {
            return null;
        }

        $value = $this->tableFilters['client']['client_id'] ?? null;

        return filled($value) ? (string) $value : null;
    }

    private function multiClientEnabled(): bool
    {
        return (bool) app(SettingsService::class)->get('multi_client_enabled', false);
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
