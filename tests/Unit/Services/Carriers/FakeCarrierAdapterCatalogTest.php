<?php

use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateRequest;
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
it('quotes only services that exist in the catalog', function (string $carrier, string $destinationCountry): void {
    $this->seed(CarrierSeeder::class);

    $cataloged = CarrierService::query()
        ->whereHas('carrier', fn ($query) => $query->where('name', $carrier))
        ->pluck('service_code')
        ->all();

    $quoted = (new FakeCarrierAdapter($carrier))
        ->getRates(fakeRateRequestTo($destinationCountry), [])
        ->pluck('serviceCode')
        ->all();

    expect($quoted)->not->toBeEmpty()
        ->and(array_diff($quoted, $cataloged))->toBe([]);
})->with(['USPS', 'FedEx', 'UPS'])->with(['domestic' => 'US', 'international' => 'NO']);

it('quotes international services only for a parcel leaving its origin country', function (string $carrier): void {
    $adapter = new FakeCarrierAdapter($carrier);

    $domestic = $adapter->getRates(fakeRateRequestTo('US'), [])->pluck('serviceCode');
    $international = $adapter->getRates(fakeRateRequestTo('NO'), [])->pluck('serviceCode');

    expect($international)->not->toBeEmpty()
        ->and($domestic->intersect($international))->toBeEmpty();
})->with(['USPS', 'FedEx', 'UPS']);

it('quotes an international shipping method\'s services', function (): void {
    $quoted = (new FakeCarrierAdapter('FedEx'))
        ->getRates(fakeRateRequestTo('NO'), ['INTERNATIONAL_ECONOMY', 'FEDEX_INTERNATIONAL_PRIORITY'])
        ->pluck('serviceCode')
        ->all();

    expect($quoted)->toEqualCanonicalizing(['INTERNATIONAL_ECONOMY', 'FEDEX_INTERNATIONAL_PRIORITY']);
});

function fakeRateRequestTo(string $destinationCountry): RateRequest
{
    return new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: $destinationCountry === 'US' ? '90210' : '0150',
        destinationCountry: $destinationCountry,
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
        clientId: 1,
    );
}

it('still quotes when a shipping method restricts the codes', function (): void {
    $this->seed(CarrierSeeder::class);

    $codes = CarrierService::query()
        ->whereHas('carrier', fn ($query) => $query->where('name', 'UPS'))
        ->pluck('service_code')
        ->all();

    $quoted = (new FakeCarrierAdapter('UPS'))->getRates(rateRequestForClient(1), $codes);

    expect($quoted)->toHaveCount(3);
});
