<?php

namespace App\Filament\Pages\Reports;

use App\Enums\Role;
use App\Models\Package;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ShippingCostAnalysis extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Shipping Cost Analysis';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.reports.shipping-cost-analysis';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Package::query()
                    ->where('shipped', true)
                    ->whereNotNull('cost')
                    ->with('shipment')
            )
            ->defaultSort('shipped_at', 'desc')
            ->paginationMode(PaginationMode::Simple)
            ->columns([
                Tables\Columns\TextColumn::make('shipped_at')
                    ->label('Date')
                    ->dateTime('M j, Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipment.shipment_reference')
                    ->label('Reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('carrier')
                    ->sortable(),
                Tables\Columns\TextColumn::make('service')
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipment.validated_state_or_province')
                    ->label('State'),
                Tables\Columns\TextColumn::make('cost')
                    ->money('USD')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label('From')
                            ->default(now()->subDays(30)->format('Y-m-d')),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label('Until'),
                    ])
                    ->default()
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->where('shipped_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->where('shipped_at', '<=', $date));
                    }),
                Tables\Filters\SelectFilter::make('carrier')
                    ->options(fn () => Package::query()->where('shipped', true)->where('shipped_date', '>=', now()->subDays(90)->toDateString())->whereNotNull('carrier')->distinct()->pluck('carrier', 'carrier')->toArray()),
                Tables\Filters\SelectFilter::make('service')
                    ->options(fn () => Package::query()->where('shipped', true)->where('shipped_date', '>=', now()->subDays(90)->toDateString())->whereNotNull('service')->distinct()->pluck('service', 'service')->toArray()),
            ]);
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        return Package::find($key);
    }

    public function getTotalCost(): float
    {
        return (float) $this->getFilteredTableQuery()->sum('cost');
    }

    public function getAverageCost(): float
    {
        return (float) $this->getFilteredTableQuery()->avg('cost');
    }

    public function getPackageCount(): int
    {
        return $this->getFilteredTableQuery()->count();
    }
}
