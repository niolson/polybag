<?php

use App\Models\CarrierService;
use App\Services\Carriers\FakeCarrierAdapter;
use Database\Seeders\CarrierSeeder;

/**
 * A fake rate is only reachable if its service code matches a cataloged
 * `CarrierService`: `ShippingRateService` passes the codes a shipping method
 * allows, and {@see FakeCarrierAdapter::getRates()} filters on them. A code
 * that matches nothing is quoted only when no shipping method is assigned, and
 * silently disappears the rest of the time — which is how `FEDEX_2DAY`,
 * `PRIORITY` and three spelled-out UPS codes sat here unnoticed while the
 * catalog said `FEDEX_2_DAY`, `PRIORITY_MAIL` and `03` / `12` / `02`.
 */
it('quotes only services that exist in the catalog', function (string $carrier): void {
    $this->seed(CarrierSeeder::class);

    $cataloged = CarrierService::query()
        ->whereHas('carrier', fn ($query) => $query->where('name', $carrier))
        ->pluck('service_code')
        ->all();

    $quoted = (new FakeCarrierAdapter($carrier))
        ->getRates(rateRequestForClient(1), [])
        ->pluck('serviceCode')
        ->all();

    expect($quoted)->not->toBeEmpty()
        ->and(array_diff($quoted, $cataloged))->toBe([]);
})->with(['USPS', 'FedEx', 'UPS']);

it('still quotes when a shipping method restricts the codes', function (): void {
    $this->seed(CarrierSeeder::class);

    $codes = CarrierService::query()
        ->whereHas('carrier', fn ($query) => $query->where('name', 'UPS'))
        ->pluck('service_code')
        ->all();

    $quoted = (new FakeCarrierAdapter('UPS'))->getRates(rateRequestForClient(1), $codes);

    expect($quoted)->toHaveCount(3);
});
