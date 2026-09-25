<?php

use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\ContentClass;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Services\Shipping\ContentsFilter;

function rateForService(string $serviceCode, ?CarrierService $service): RateResponse
{
    return new RateResponse(
        carrier: 'USPS',
        serviceCode: $serviceCode,
        serviceName: $serviceCode,
        price: 5.00,
        carrierServiceId: $service?->id,
        carrierId: $service?->carrier_id,
    );
}

beforeEach(function (): void {
    $usps = Carrier::factory()->usps()->create();
    $this->mediaMail = CarrierService::factory()->uspsMediaMail()->for($usps)->create();
    $this->groundAdvantage = CarrierService::factory()->uspsGroundAdvantage()->for($usps)->create();

    $this->rates = collect([
        rateForService('USPS_GROUND_ADVANTAGE', $this->groundAdvantage),
        rateForService('MEDIA_MAIL', $this->mediaMail),
        // A rate that names no catalog service has no known requirement.
        rateForService('PARCEL_SELECT', null),
    ]);
});

it('drops a service that requires contents the package does not qualify for', function (): void {
    expect(ContentsFilter::keepQualifying($this->rates, [])->pluck('serviceCode')->all())
        ->toBe(['USPS_GROUND_ADVANTAGE', 'PARCEL_SELECT']);
});

it('keeps it for a package that qualifies', function (): void {
    expect(ContentsFilter::keepQualifying($this->rates, [ContentClass::Media])->pluck('serviceCode')->all())
        ->toBe(['USPS_GROUND_ADVANTAGE', 'MEDIA_MAIL', 'PARCEL_SELECT']);
});

it('asks nothing of the database when no rate names a service', function (): void {
    $rates = collect([rateForService('PARCEL_SELECT', null)]);

    DB::enableQueryLog();
    $kept = ContentsFilter::keepQualifying($rates, []);

    expect($kept)->toHaveCount(1)
        ->and(DB::getQueryLog())->toBe([]);
});
