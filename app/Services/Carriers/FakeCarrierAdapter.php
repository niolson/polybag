<?php

namespace App\Services\Carriers;

use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Models\Package;
use Illuminate\Support\Collection;
use Saloon\Http\Response;

class FakeCarrierAdapter implements CarrierAdapterInterface
{
    /** @var array<string, array<int, array{code: string, name: string, price: float, transit: string, days: int}>> */
    private const RATES = [
        'USPS' => [
            ['code' => 'USPS_GROUND_ADVANTAGE', 'name' => 'Ground Advantage', 'price' => 8.50, 'transit' => '2-5 Business Days', 'days' => 5],
            ['code' => 'PRIORITY', 'name' => 'Priority Mail', 'price' => 12.75, 'transit' => '1-3 Business Days', 'days' => 3],
            ['code' => 'PRIORITY_MAIL_EXPRESS', 'name' => 'Priority Mail Express', 'price' => 28.40, 'transit' => '1-2 Days', 'days' => 1],
        ],
        'FedEx' => [
            ['code' => 'FEDEX_GROUND', 'name' => 'FedEx Ground', 'price' => 10.25, 'transit' => '1-5 Business Days', 'days' => 5],
            ['code' => 'FEDEX_EXPRESS_SAVER', 'name' => 'FedEx Express Saver', 'price' => 18.90, 'transit' => '3 Business Days', 'days' => 3],
            ['code' => 'FEDEX_2DAY', 'name' => 'FedEx 2Day', 'price' => 24.50, 'transit' => '2 Business Days', 'days' => 2],
        ],
        'UPS' => [
            ['code' => 'UPS_GROUND', 'name' => 'UPS Ground', 'price' => 11.00, 'transit' => '1-5 Business Days', 'days' => 5],
            ['code' => 'UPS_3DAY_SELECT', 'name' => 'UPS 3 Day Select', 'price' => 19.75, 'transit' => '3 Business Days', 'days' => 3],
            ['code' => 'UPS_2ND_DAY_AIR', 'name' => 'UPS 2nd Day Air', 'price' => 26.30, 'transit' => '2 Business Days', 'days' => 2],
        ],
    ];

    /** @var array<string, string> */
    private const TRACKING_PREFIXES = [
        'USPS' => '9400',
        'FedEx' => '7489',
        'UPS' => '1Z99',
    ];

    public function __construct(
        private readonly string $carrierName,
    ) {}

    public function getCarrierName(): string
    {
        return $this->carrierName;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
    {
        return null;
    }

    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        $rates = self::RATES[$this->carrierName] ?? [];

        return collect($rates)
            ->when(! empty($serviceCodes), fn (Collection $c) => $c->whereIn('code', $serviceCodes))
            ->map(fn (array $rate) => new RateResponse(
                carrier: $this->carrierName,
                serviceCode: $rate['code'],
                serviceName: $rate['name'],
                price: $rate['price'],
                deliveryCommitment: $rate['transit'],
                deliveryDate: now()->addWeekdays($rate['days'])->toDateString(),
                transitTime: $rate['transit'],
            ));
    }

    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
    {
        return collect();
    }

    public function createShipment(ShipRequest $request): ShipResponse
    {
        $prefix = self::TRACKING_PREFIXES[$this->carrierName] ?? 'FAKE';
        $tracking = $prefix . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT) . random_int(100, 999);

        // Minimal valid 1x1 white pixel PDF
        $labelData = base64_encode('%PDF-1.0 1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 288 432]/Parent 2 0 R>>endobj xref 0 4 trailer<</Size 4/Root 1 0 R>> startxref 0 %%EOF');

        return ShipResponse::success(
            trackingNumber: $tracking,
            cost: $request->selectedRate->price,
            carrier: $this->carrierName,
            service: $request->selectedRate->serviceName,
            labelData: $labelData,
            labelFormat: $request->labelFormat,
        );
    }

    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
    {
        return CancelResponse::success("Fake {$this->carrierName} shipment {$trackingNumber} cancelled");
    }

    public function supportsMultiPackage(): bool
    {
        return false;
    }

    public function supportsManifest(): bool
    {
        return false;
    }

    public function resolvePreSelectedRate(RateResponse $rate, Package $package): RateResponse
    {
        return $rate;
    }
}
