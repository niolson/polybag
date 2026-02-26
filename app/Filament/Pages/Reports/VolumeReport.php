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

class VolumeReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Volume Report';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.reports.volume-report';

    public string $groupBy = 'channel';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    public function updatedGroupBy(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $query = match ($this->groupBy) {
            'shipping_method' => Package::query()
                ->join('shipments', 'packages.shipment_id', '=', 'shipments.id')
                ->leftJoin('shipping_methods', 'shipments.shipping_method_id', '=', 'shipping_methods.id')
                ->where('packages.shipped', true)
                ->select([
                    DB::raw('COALESCE(shipping_methods.name, "Unmapped") as group_name'),
                    DB::raw('COUNT(*) as package_count'),
                    DB::raw('SUM(packages.cost) as total_cost'),
                    DB::raw('AVG(packages.cost) as avg_cost'),
                    DB::raw('MIN(packages.id) as id'),
                ])
                ->groupBy('group_name'),
            'period' => Package::query()
                ->where('shipped', true)
                ->select([
                    DB::raw($this->periodGroupExpression()),
                    DB::raw('COUNT(*) as package_count'),
                    DB::raw('SUM(cost) as total_cost'),
                    DB::raw('AVG(cost) as avg_cost'),
                    DB::raw('MIN(id) as id'),
                ])
                ->groupBy('group_name'),
            default => Package::query()
                ->join('shipments', 'packages.shipment_id', '=', 'shipments.id')
                ->leftJoin('channels', 'shipments.channel_id', '=', 'channels.id')
                ->where('packages.shipped', true)
                ->select([
                    DB::raw('COALESCE(channels.name, "Unknown") as group_name'),
                    DB::raw('COUNT(*) as package_count'),
                    DB::raw('SUM(packages.cost) as total_cost'),
                    DB::raw('AVG(packages.cost) as avg_cost'),
                    DB::raw('MIN(packages.id) as id'),
                ])
                ->groupBy('group_name'),
        };

        return $table
            ->query($query)
            ->defaultSort('package_count', 'desc')
            ->defaultKeySort(false)
            ->columns([
                Tables\Columns\TextColumn::make('group_name')
                    ->label(match ($this->groupBy) {
                        'shipping_method' => 'Shipping Method',
                        'period' => 'Period',
                        default => 'Channel',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('package_count')
                    ->label('Packages')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Total Cost')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('avg_cost')
                    ->label('Avg Cost')
                    ->money('USD')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from'),
                        \Filament\Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function ($query, array $data) {
                        $col = $this->groupBy === 'channel' || $this->groupBy === 'shipping_method'
                            ? 'packages.shipped_at'
                            : 'shipped_at';

                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->where($col, '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->where($col, '<=', $date));
                    }),
            ]);
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        return Package::find($key);
    }

    private function periodGroupExpression(): string
    {
        $driver = DB::getDriverName();

        return match ($driver) {
            'sqlite' => 'strftime("%Y-%m", shipped_at) as group_name',
            default => 'DATE_FORMAT(shipped_at, "%Y-%m") as group_name',
        };
    }
}
