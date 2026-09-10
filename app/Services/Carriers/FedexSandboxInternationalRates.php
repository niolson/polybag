<?php

namespace App\Services\Carriers;

use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Models\CarrierService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Stand-in FedEx international rates for sandbox mode.
 *
 * The FedEx sandbox rate API rewrites the shipper and recipient addresses of
 * whatever it is sent — see the canned payload in {@see FedexAdapter} — so it
 * only ever answers with domestic services, and no international service can
 * be selected on the Ship page while sandbox mode is on. These quotes stand in
 * for that missing half so the international workflow (customs, commercial
 * invoice, label purchase) can be walked end to end. Only rating is faked:
 * the label itself is still bought from the real FedEx sandbox, which does
 * accept international shipments.
 *
 * Prices are made up but deterministic — the same package quoted twice gets
 * the same price, so nothing downstream sees a rate change under it.
 *
 * What these quotes cannot know is which lanes each service actually serves.
 * The real rate API answers that by simply not returning a service for an
 * origin/destination pair it does not cover, which is what keeps an
 * unavailable service off the Ship page in production. Quoting from a table
 * has no such filter, so sandbox offers every service the shipping method
 * lists and a narrow one can be selected and then refused at purchase —
 * `FEDEX_INTERNATIONAL_PRIORITY_EXPRESS` from Washington to Ontario comes back
 * as REQUESTEDSHIPMENT.SERVICETYPE.NOTSUPPORTED, "not supported for the origin
 * and destination pair". That refusal is FedEx being right, not a defect here,
 * and it cannot happen outside sandbox mode.
 */
class FedexSandboxInternationalRates
{
    /**
     * The FedEx international services this app catalogs, with the shape of a
     * plausible quote for each. Ordered slowest to fastest.
     *
     * @var array<string, array{name: string, base: float, perPound: float, transitDays: int}>
     */
    private const SERVICES = [
        'FEDEX_INTERNATIONAL_CONNECT_PLUS' => ['name' => 'FedEx International Connect Plus®', 'base' => 24.80, 'perPound' => 2.15, 'transitDays' => 6],
        'INTERNATIONAL_ECONOMY' => ['name' => 'FedEx International Economy®', 'base' => 38.45, 'perPound' => 3.40, 'transitDays' => 5],
        'FEDEX_INTERNATIONAL_PRIORITY' => ['name' => 'FedEx International Priority®', 'base' => 52.90, 'perPound' => 4.75, 'transitDays' => 3],
        'FEDEX_INTERNATIONAL_PRIORITY_EXPRESS' => ['name' => 'FedEx International Priority® Express', 'base' => 68.20, 'perPound' => 5.60, 'transitDays' => 2],
        'INTERNATIONAL_FIRST' => ['name' => 'FedEx International First®', 'base' => 91.75, 'perPound' => 6.85, 'transitDays' => 1],
    ];

    /**
     * Quotes for the international services among the requested codes.
     *
     * An empty `$serviceCodes` means the caller placed no restriction and every
     * international service is quoted. A non-empty one that names no
     * international service quotes nothing, the same answer FedEx itself would
     * give for a domestic-only shipping method sent abroad.
     *
     * @param  array<int, string>  $serviceCodes
     * @return Collection<int, RateResponse>
     */
    public function ratesFor(RateRequest $request, array $serviceCodes): Collection
    {
        $quoted = $serviceCodes === []
            ? array_keys(self::SERVICES)
            : array_values(array_intersect(array_keys(self::SERVICES), $serviceCodes));

        if ($quoted === []) {
            return collect();
        }

        $weight = max(1.0, (float) ($request->packages[0]->weight ?? 1.0));
        $names = $this->catalogNames($quoted);
        $shipDate = $request->shipDate ?? now();

        logger()->info('FedEx sandbox mode: standing in for international rates the sandbox cannot quote', [
            'service_codes' => $quoted,
            'destination_country' => $request->destinationCountry,
        ]);

        return collect($quoted)->map(function (string $code) use ($names, $weight, $shipDate): RateResponse {
            $service = self::SERVICES[$code];
            $days = $service['transitDays'];

            return new RateResponse(
                carrier: 'FedEx',
                serviceCode: $code,
                serviceName: $names[$code] ?? $service['name'],
                price: round($service['base'] + ($service['perPound'] * ceil($weight)), 2),
                deliveryCommitment: $this->commitment($days),
                deliveryDate: $shipDate->copy()->addWeekdays($days)->toDateString(),
                transitTime: $this->commitment($days),
                metadata: [
                    // createShipment reads the service off the metadata, exactly
                    // as it would for a rate the sandbox had really quoted.
                    'serviceType' => $code,
                    'isSandboxStub' => true,
                ],
            );
        });
    }

    /**
     * Cataloged service names, so a stubbed rate reads on the Ship page the way
     * the real one does. Falls back to the built-in name for a code the
     * catalog has no row for.
     *
     * @param  array<int, string>  $serviceCodes
     * @return array<string, string>
     */
    private function catalogNames(array $serviceCodes): array
    {
        return CarrierService::query()
            ->whereIn('service_code', $serviceCodes)
            ->whereHas('carrier', fn (Builder $query) => $query->where('name', 'FedEx'))
            ->pluck('name', 'service_code')
            ->all();
    }

    private function commitment(int $days): string
    {
        return $days === 1 ? '1 Business Day' : "{$days} Business Days";
    }
}
