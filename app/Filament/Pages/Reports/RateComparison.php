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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Package::query()
                    ->where('shipped', true)
                    ->whereHas('rateQuotes', function (Builder $q) {
                        $q->where('selected', true);
                    })
                    ->where(function (Builder $q) {
                        $q->whereRaw('(select count(*) from rate_quotes where rate_quotes.package_id = packages.id) >= 2');
                    })
                    ->withCount('rateQuotes')
                    ->with(['shipment', 'rateQuotes'])
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
                    ->state(function (Package $record) {
                        $selected = $record->rateQuotes->firstWhere('selected', true);

                        return $selected ? "{$selected->carrier} — \${$selected->quoted_price}" : '—';
                    }),
                Tables\Columns\TextColumn::make('cheapest_rate')
                    ->label('Cheapest Rate')
                    ->state(function (Package $record) {
                        $cheapest = $record->rateQuotes->sortBy('quoted_price')->first();

                        return $cheapest ? "{$cheapest->carrier} — \${$cheapest->quoted_price}" : '—';
                    }),
                Tables\Columns\TextColumn::make('savings')
                    ->label('Potential Savings')
                    ->state(function (Package $record) {
                        $selected = $record->rateQuotes->firstWhere('selected', true);
                        $cheapest = $record->rateQuotes->sortBy('quoted_price')->first();

                        if (! $selected || ! $cheapest) {
                            return '$0.00';
                        }

                        $savings = (float) $selected->quoted_price - (float) $cheapest->quoted_price;

                        return '$'.number_format(max(0, $savings), 2);
                    })
                    ->color(function (Package $record) {
                        $selected = $record->rateQuotes->firstWhere('selected', true);
                        $cheapest = $record->rateQuotes->sortBy('quoted_price')->first();

                        if (! $selected || ! $cheapest) {
                            return null;
                        }

                        return (float) $selected->quoted_price > (float) $cheapest->quoted_price ? 'warning' : 'success';
                    }),
                Tables\Columns\TextColumn::make('rate_quotes_count')
                    ->label('Quotes')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from'),
                        \Filament\Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->where('shipped_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->where('shipped_at', '<=', $date));
                    }),
                Tables\Filters\SelectFilter::make('carrier')
                    ->options(fn () => Package::query()->where('shipped', true)->whereNotNull('carrier')->distinct()->pluck('carrier', 'carrier')->toArray())
                    ->query(fn ($query, array $data) => $data['value'] ? $query->where('carrier', $data['value']) : $query),
            ]);
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        return Package::find($key);
    }

    public function getTotalPotentialSavings(): float
    {
        $packages = $this->getFilteredTableQuery()
            ->with('rateQuotes')
            ->get();

        return $packages->sum(function (Package $package) {
            $selected = $package->rateQuotes->firstWhere('selected', true);
            $cheapest = $package->rateQuotes->sortBy('quoted_price')->first();

            if (! $selected || ! $cheapest) {
                return 0;
            }

            return max(0, (float) $selected->quoted_price - (float) $cheapest->quoted_price);
        });
    }
}
