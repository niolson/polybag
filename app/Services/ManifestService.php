<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\ManifestResponse;
use App\Events\ManifestCreated as ManifestCreatedEvent;
use App\Http\Integrations\USPS\Requests\ScanForm;
use App\Http\Integrations\USPS\USPSConnector;
use App\Models\Manifest;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use Illuminate\Support\Collection;
use Saloon\Exceptions\Request\RequestException;

class ManifestService
{
    /**
     * Get unmanifested package counts per carrier with manifest support info.
     *
     * @return Collection<int, array{carrier: string, count: int, supports_manifest: bool}>
     */
    public static function getUnmanifestedSummary(): Collection
    {
        return Package::query()
            ->selectRaw('carrier, count(*) as count')
            ->where('manifested', false)
            ->where('shipped', true)
            ->whereNotNull('tracking_number')
            ->groupBy('carrier')
            ->get()
            ->map(fn ($row) => [
                'carrier' => $row->carrier,
                'count' => (int) $row->count,
                'supports_manifest' => CarrierRegistry::has($row->carrier)
                    && CarrierRegistry::get($row->carrier)->supportsManifest(),
            ]);
    }

    /**
     * Get unmanifested shipped packages grouped by carrier.
     *
     * @return Collection<string, Collection<int, Package>>
     */
    public static function getUnmanifestedPackages(): Collection
    {
        return Package::query()
            ->where('manifested', false)
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
            $connector = USPSConnector::getAuthenticatedConnector();

            $remainingPackages = $packages;
            $totalMarkedExternally = 0;

            // Retry loop: if USPS reports some packages as already manifested,
            // mark those and retry with the rest.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    $response = self::sendScanFormRequest($connector, $remainingPackages, $fromAddress);
                } catch (RequestException $e) {
                    // Saloon's retry mechanism throws after exhausting retries on 4xx.
                    // Extract the response to check for already-manifested errors.
                    $errorResponse = $e->getResponse();
                    $alreadyManifested = self::extractAlreadyManifestedBarcodes(
                        $errorResponse->json('error.errors', [])
                    );

                    if (empty($alreadyManifested)) {
                        throw $e;
                    }

                    $marked = Package::query()
                        ->whereIn('tracking_number', $alreadyManifested)
                        ->where('manifested', false)
                        ->update(['manifested' => true]);
                    $totalMarkedExternally += $marked;

                    logger()->warning('USPS SCAN Form: marked packages as already manifested', [
                        'tracking_numbers' => $alreadyManifested,
                        'count' => $marked,
                    ]);

                    $remainingPackages = $remainingPackages->reject(
                        fn ($p) => in_array($p->tracking_number, $alreadyManifested)
                    )->values();

                    if ($remainingPackages->isEmpty()) {
                        return ManifestResponse::failure(
                            "All {$totalMarkedExternally} packages were already manifested by USPS and have been cleared."
                        );
                    }

                    continue; // Retry with remaining packages
                }

                $response->parseBody();

                $manifestNumber = $response->metadata['manifestNumber'] ?? '';
                $image = $response->image;

                $manifest = Manifest::create([
                    'carrier' => 'USPS',
                    'manifest_number' => $manifestNumber,
                    'image' => $image,
                    'manifest_date' => now()->toDateString(),
                    'package_count' => $remainingPackages->count(),
                ]);

                Package::whereIn('id', $remainingPackages->pluck('id'))
                    ->update(['manifest_id' => $manifest->id, 'manifested' => true]);

                ManifestCreatedEvent::dispatch($manifest, $remainingPackages->count());

                return ManifestResponse::success(
                    manifestNumber: $manifestNumber,
                    carrier: 'USPS',
                    image: $image,
                );
            }

            return ManifestResponse::failure('USPS SCAN Form failed after multiple retries.');
        } catch (\Exception $e) {
            logger()->error('USPS Scan Form Error', ['error' => $e->getMessage()]);

            return ManifestResponse::failure($e->getMessage());
        }
    }

    /**
     * Send a SCAN Form request for the given packages.
     */
    private static function sendScanFormRequest(
        \Saloon\Http\Connector $connector,
        Collection $packages,
        AddressData $fromAddress,
    ): \Saloon\Http\Response {
        $trackingNumbers = $packages->pluck('tracking_number')->values()->all();

        $request = new ScanForm;
        $request->body()->set([
            'form' => '5630',
            'imageType' => 'PDF',
            'labelType' => '8.5x11LABEL',
            'mailingDate' => now()->format('Y-m-d'),
            'overwriteMailingDate' => true,
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

        return $connector->send($request);
    }

    /**
     * Extract tracking numbers from USPS "already manifested" error responses.
     *
     * @param  array<int, array<string, mixed>>  $errors
     * @return array<int, string>
     */
    private static function extractAlreadyManifestedBarcodes(array $errors): array
    {
        $barcodes = [];

        foreach ($errors as $error) {
            if (($error['code'] ?? '') !== '160398') {
                continue;
            }

            $detail = $error['detail'] ?? '';
            if (preg_match('/barcode (\S+) has manifested/', $detail, $matches)) {
                $barcodes[] = $matches[1];
            }
        }

        return $barcodes;
    }

    /**
     * Mark all unmanifested packages for a carrier as manifested without linking to a manifest record.
     */
    public static function markAsManifested(string $carrier): int
    {
        return Package::query()
            ->where('carrier', $carrier)
            ->where('manifested', false)
            ->where('shipped', true)
            ->whereNotNull('tracking_number')
            ->update(['manifested' => true]);
    }

    private static function createFedexManifest(): ManifestResponse
    {
        return ManifestResponse::failure('FedEx end-of-day manifest is not yet implemented.');
    }
}
