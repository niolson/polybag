<?php

namespace App\Filament\Pages\Reports;

use App\Enums\Role;
use App\Filament\Concerns\HasMultiClientFilter;
use App\Models\PackageLabel;
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
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Every Label bought on DDP terms, with the postage and the duties, taxes and
 * fees the carrier prepaid kept apart.
 *
 * Only USPS reports the prepaid amount at purchase. A UPS or FedEx DDP Label
 * is listed with no duties: the carrier bills those to the account after
 * clearance, and nothing here records the bill yet. A blank is "not recorded",
 * never zero.
 *
 * The list is also what a client needs to take out of its own IOSS return when
 * USPS's provider declared these parcels under its own number: a Label whose
 * snapshot declares no registration was cleared that way.
 */
class PrepaidDutiesReport extends Page implements HasTable
{
    use HasMultiClientFilter;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Prepaid Duties';

    protected static ?string $title = 'Prepaid Duties';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 36;

    protected string $view = 'filament.pages.reports.prepaid-duties-report';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    /**
     * Labels declared DDP, or that carry a prepaid amount whatever the
     * snapshot says.
     *
     * @return Builder<PackageLabel>
     */
    private function labelsQuery(): Builder
    {
        return PackageLabel::query()
            ->join('packages', 'package_labels.package_id', '=', 'packages.id')
            ->join('shipments', 'packages.shipment_id', '=', 'shipments.id')
            ->leftJoin('clients', 'shipments.client_id', '=', 'clients.id')
            ->where(fn (Builder $query) => $query
                ->where('package_labels.customs_terms->duties_terms', 'ddp')
                ->orWhereNotNull('package_labels.duties_cost'))
            ->select([
                'package_labels.*',
                'shipments.shipment_reference as order_reference',
                'shipments.country as destination_country',
                'shipments.client_id as client_id',
                'clients.name as client_name',
            ]);
    }

    public function table(Table $table): Table
    {
        $filters = [
            Tables\Filters\Filter::make('date_range')
                ->form([
                    DatePicker::make('from')->default(now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d')),
                    DatePicker::make('until'),
                ])
                ->columns(2)
                ->default()
                ->query(fn (Builder $query, array $data): Builder => $this->withinShipDates($query, $data['from'] ?? null, $data['until'] ?? null)),
            Tables\Filters\SelectFilter::make('label_status')
                ->label('Labels')
                ->options(['active' => 'Active', 'voided' => 'Voided', 'all' => 'All'])
                ->default('active')
                ->selectablePlaceholder(false)
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'active') {
                    'voided' => $query->whereNotNull('package_labels.voided_at'),
                    'all' => $query,
                    default => $query->whereNull('package_labels.voided_at'),
                }),
        ];

        if ($filter = $this->buildClientFilter()) {
            $filters[] = $filter;
        }

        return $table
            ->query($this->labelsQuery())
            ->defaultSort('package_labels.ship_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('ship_date')->label('Ship Date')->date()->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('package_labels.ship_date', $direction)),
                Tables\Columns\TextColumn::make('order_reference')->label('Order')->searchable(query: fn (Builder $query, string $search): Builder => $query->where('shipments.shipment_reference', 'like', "%{$search}%")),
                Tables\Columns\TextColumn::make('client_name')->label('Client')->visible(fn (): bool => $this->multiClientEnabled()),
                Tables\Columns\TextColumn::make('destination_country')->label('To'),
                Tables\Columns\TextColumn::make('tracking_number')->label('Tracking')->searchable(query: fn (Builder $query, string $search): Builder => $query->where('package_labels.tracking_number', 'like', "%{$search}%")),
                Tables\Columns\TextColumn::make('carrier'),
                Tables\Columns\TextColumn::make('cost')->label('Postage')->money('USD'),
                Tables\Columns\TextColumn::make('duties_package_fee')->label('Package Fee')->money('USD')->placeholder('—'),
                Tables\Columns\TextColumn::make('duties_duty')->label('Duty')->money('USD')->placeholder('—'),
                Tables\Columns\TextColumn::make('duties_tax')->label('Tax')->money('USD')->placeholder('—'),
                Tables\Columns\TextColumn::make('duties_cost')->label('Duties Total')->money('USD')->placeholder('Not recorded'),
                Tables\Columns\TextColumn::make('landed_total')
                    ->label('Postage + Duties')
                    ->money('USD')
                    ->placeholder('—')
                    ->state(fn (PackageLabel $record): ?float => $record->duties_cost === null ? null : (float) $record->cost + (float) $record->duties_cost),
                Tables\Columns\TextColumn::make('declared_registration')
                    ->label('Registration Declared')
                    ->state(fn (PackageLabel $record): string => $this->declaredRegistration($record)),
                Tables\Columns\TextColumn::make('voided_at')->label('Voided')->since()->placeholder('')->color('danger'),
            ])
            ->filters($filters, layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(3);
    }

    /**
     * @param  Builder<PackageLabel>  $query
     * @return Builder<PackageLabel>
     */
    private function withinShipDates(Builder $query, ?string $from, ?string $until): Builder
    {
        return $query
            ->when($from, fn (Builder $query, string $date): Builder => $query->where('package_labels.ship_date', '>=', $date))
            ->when($until, fn (Builder $query, string $date): Builder => $query->where('package_labels.ship_date', '<=', $date));
    }

    private function multiClientEnabled(): bool
    {
        return (bool) app(SettingsService::class)->get('multi_client_enabled', false);
    }

    /**
     * What the Label declared to customs: the regime of the seller number it
     * carried, or "None" when it declared none. For USPS DDP "None" means the
     * provider's own IOSS number cleared the parcel.
     */
    private function declaredRegistration(PackageLabel $label): string
    {
        $regime = Arr::get($label->customs_terms ?? [], 'registration.regime');

        return $regime === null ? 'None' : strtoupper(str_replace('_', ' ', (string) $regime));
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
     * The rows the table currently shows, filters, search and sort included.
     *
     * Read in chunks that keep the table's sort, with the Label id as the
     * last key so that rows sharing a ship date never move between chunks.
     * `lazyById()` would page by id alone and drop the rows the sort puts
     * outside each id range.
     */
    public function exportCsv(int $chunkSize = 500): StreamedResponse
    {
        $query = $this->getFilteredSortedTableQuery();
        $filename = 'prepaid-duties-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query, $chunkSize): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Ship Date', 'Order', 'Client', 'Destination', 'Tracking', 'Carrier', 'Postage', 'Package Fee', 'Duty', 'Tax', 'Duties Total', 'Registration Declared', 'Voided']);

            foreach ($query?->orderBy('package_labels.id')->lazy($chunkSize) ?? [] as $label) {
                if (! $label instanceof PackageLabel) {
                    continue;
                }

                fputcsv($out, [
                    $label->ship_date?->toDateString(),
                    $label->getAttribute('order_reference'),
                    $label->getAttribute('client_name'),
                    $label->getAttribute('destination_country'),
                    $label->tracking_number,
                    $label->carrier,
                    $label->cost,
                    $label->duties_package_fee,
                    $label->duties_duty,
                    $label->duties_tax,
                    $label->duties_cost,
                    $this->declaredRegistration($label),
                    $label->voided_at?->toDateString(),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
