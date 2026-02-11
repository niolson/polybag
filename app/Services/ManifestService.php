<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\ManifestResponse;
use App\Http\Integrations\USPS\Requests\ScanForm;
use App\Http\Integrations\USPS\USPSConnector;
use App\Models\Manifest;
use App\Models\Package;
use Illuminate\Support\Collection;

class ManifestService
{
    /**
     * Get unmanifested shipped packages grouped by carrier.
     *
     * @return Collection<string, Collection<int, Package>>
     */
    public static function getUnmanifestedPackages(): Collection
    {
        return Package::query()
            ->whereNull('manifest_id')
            ->where('shipped', true)
            ->whereNotNull('tracking_number')
            ->with('shipment')
            ->get()
            ->groupBy('carrier');
    }

    /**
     * Create a manifest for the given carrier and packages.
     */
    public static function createManifest(string $carrier, Collection $packages): ManifestResponse
    {
        return match ($carrier) {
            'USPS' => self::createUspsManifest($packages),
            'FedEx' => self::createFedexManifest(),
            default => ManifestResponse::failure("Unsupported carrier: {$carrier}"),
        };
    }

    private static function createUspsManifest(Collection $packages): ManifestResponse
    {
        try {
            $fromAddress = AddressData::fromConfig();
            $trackingNumbers = $packages->pluck('tracking_number')->values()->all();

            $request = new ScanForm;
            $request->body()->set([
                'form' => '5630',
                'imageType' => 'PDF',
                'labelType' => '8.5x11LABEL',
                'mailingDate' => now()->format('Y-m-d'),
                'entryFacilityZIPCode' => $fromAddress->postalCode,
                'destinationEntryFacilityType' => 'NONE',
                'shipment' => [
                    'trackingNumbers' => $trackingNumbers,
                ],
                'fromAddress' => array_filter([
                    'firstName' => $fromAddress->firstName,
                    'lastName' => $fromAddress->lastName,
                    'streetAddress' => $fromAddress->streetAddress,
                    'secondaryAddress' => $fromAddress->streetAddress2,
                    'city' => $fromAddress->city,
                    'state' => $fromAddress->stateOrProvince,
                    'ZIPCode' => $fromAddress->postalCode,
                ]),
            ]);

            $connector = USPSConnector::getAuthenticatedConnector();
            $response = $connector->send($request);
            $response->parseBody();

            $manifestNumber = $response->metadata['manifestNumber'] ?? '';
            $image = $response->image;

            $manifest = Manifest::create([
                'carrier' => 'USPS',
                'manifest_number' => $manifestNumber,
                'image' => $image,
                'manifest_date' => now()->toDateString(),
                'package_count' => $packages->count(),
            ]);

            Package::whereIn('id', $packages->pluck('id'))
                ->update(['manifest_id' => $manifest->id]);

            return ManifestResponse::success(
                manifestNumber: $manifestNumber,
                carrier: 'USPS',
                image: $image,
            );
        } catch (\Exception $e) {
            logger()->error('USPS Scan Form Error', ['error' => $e->getMessage()]);

            return ManifestResponse::failure($e->getMessage());
        }
    }

    private static function createFedexManifest(): ManifestResponse
    {
        return ManifestResponse::failure('FedEx end-of-day manifest is not yet implemented.');
    }
}
