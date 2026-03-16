<?php

namespace App\Filament\Pages\Reports;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Models\Package;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class UserShipmentsReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Shipments by User';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 31;

    protected string $view = 'filament.pages.reports.user-shipments-report';

    public string $viewMode = 'all';

    public ?int $userId = null;

    public string $period = 'day';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    public function updatedViewMode(): void
    {
        if ($this->viewMode === 'individual' && ! $this->userId) {
            $this->userId = User::orderBy('name')->first()?->id;
        }
        $this->resetTable();
    }

    public function updatedUserId(): void
    {
        $this->resetTable();
    }

    public function updatedPeriod(): void
    {
        if (! in_array($this->period, ['day', 'week', 'month'])) {
            $this->period = 'day';
        }

        if ($this->userId) {
            $this->resetTable();
        }
    }

    public function table(Table $table): Table
    {
        if ($this->viewMode === 'individual' && $this->userId) {
            return $this->userDetailTable($table);
        }

        return $this->allUsersTable($table);
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        return Package::find($key);
    }

    private function allUsersTable(Table $table): Table
    {
        $query = Package::query()
            ->where('packages.status', PackageStatus::Shipped->value)
            ->whereNotNull('shipped_by_user_id')
            ->join('users', 'packages.shipped_by_user_id', '=', 'users.id')
            ->select([
                DB::raw('users.name as user_name'),
                DB::raw('COUNT(*) as shipment_count'),
                DB::raw('MIN(packages.id) as id'),
            ])
            ->groupBy('users.name');

        return $table
            ->query($query)
            ->defaultSort('shipment_count', 'desc')
            ->defaultKeySort(false)
            ->columns([
                Tables\Columns\TextColumn::make('user_name')
                    ->label('User')
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipment_count')
                    ->label('Shipments')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->default(now()->subDays(30)->format('Y-m-d')),
                        DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->default()
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->where('packages.shipped_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->where('packages.shipped_at', '<=', $date.' 23:59:59'));
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(2);
    }

    private function userDetailTable(Table $table): Table
    {
        $groupExpr = match ($this->period) {
            'week' => $this->weekGroupExpression(),
            'month' => $this->monthGroupExpression(),
            default => $this->dayGroupExpression(),
        };

        $query = Package::query()
            ->where('status', PackageStatus::Shipped)
            ->where('shipped_by_user_id', $this->userId)
            ->select([
                DB::raw($groupExpr),
                DB::raw('COUNT(*) as shipment_count'),
                DB::raw('MIN(id) as id'),
            ])
            ->groupBy('period_label')
            ->orderByDesc('period_label');

        return $table
            ->query($query)
            ->defaultKeySort(false)
            ->columns([
                Tables\Columns\TextColumn::make('period_label')
                    ->label('Period')
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipment_count')
                    ->label('Shipments')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->default(now()->subDays(30)->format('Y-m-d')),
                        DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->default()
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->where('shipped_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->where('shipped_at', '<=', $date.' 23:59:59'));
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(2);
    }

    private function dayGroupExpression(): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => 'strftime("%Y-%m-%d", shipped_at) as period_label',
            default => 'DATE(shipped_at) as period_label',
        };
    }

    private function weekGroupExpression(): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => 'strftime("%Y-W%W", shipped_at) as period_label',
            default => 'CONCAT(YEAR(shipped_at), "-W", LPAD(WEEK(shipped_at, 3), 2, "0")) as period_label',
        };
    }

    private function monthGroupExpression(): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => 'strftime("%Y-%m", shipped_at) as period_label',
            default => 'DATE_FORMAT(shipped_at, "%Y-%m") as period_label',
        };
    }

    public function getUserOptions(): array
    {
        return User::orderBy('name')->pluck('name', 'id')->toArray();
    }
}
