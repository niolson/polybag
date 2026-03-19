<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\ManifestResponse;
use App\Enums\PackageStatus;
use App\Events\ManifestCreated as ManifestCreatedEvent;
use App\Http\Integrations\USPS\Requests\ScanForm;
use App\Http\Integrations\USPS\USPSConnector;
use App\Models\Manifest;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Connector;
use Saloon\Http\Response;

class ManifestService
{
    /**
     * Get unmanifested package counts per carrier with manifest support info.
     *
     * @return Collection<int, array{carrier: string, count: int, supports_manifest: bool}>
     */
    public function getUnmanifestedSummary(): Collection
    {
        $registry = app(CarrierRegistry::class);

        return Package::query()
            ->selectRaw('carrier, count(*) as count')
            ->whereNull('manifest_id')
            ->where('status', PackageStatus::Shipped)
            ->whereNotNull('tracking_number')
            ->groupBy('carrier')
            ->get()
            ->map(fn ($row) => [
                'carrier' => $row->carrier,
                'count' => (int) $row->count,
                'supports_manifest' => $registry->has($row->carrier)
                    && $registry->get($row->carrier)->supportsManifest(),
            ]);
    }

    /**
     * Get unmanifested shipped packages grouped by carrier.
     *
     * @return Collection<string, Collection<int, Package>>
     */
    public function getUnmanifestedPackages(): Collection
    {
        return Package::query()
            ->whereNull('manifest_id')
            ->where('status', PackageStatus::Shipped)
            ->whereNotNull('tracking_number')
            ->with('shipment')
            ->get()
            ->groupBy('carrier');
    }

    /**
     * Create a manifest for the given carrier and packages.
     */
    public function createManifest(string $carrier, Collection $packages, ?CarbonImmutable $shipDate = null): ManifestResponse
    {
        return match ($carrier) {
            'USPS' => $this->createUspsManifest($packages, $shipDate),
            'FedEx' => $this->createFedexManifest(),
            default => ManifestResponse::failure("Unsupported carrier: {$carrier}"),
        };
    }

    /**
     * USPS limits SCAN Forms to 10,000 tracking numbers per request.
     */
    private const USPS_MANIFEST_CHUNK_SIZE = 10_000;

    private function createUspsManifest(Collection $packages, ?CarbonImmutable $shipDate = null): ManifestResponse
    {
        try {
            $fromAddress = AddressData::fromConfig();
            $connector = USPSConnector::getAuthenticatedConnector();

            $chunks = $packages->chunk(self::USPS_MANIFEST_CHUNK_SIZE);
            $manifestNumbers = [];
            $lastImage = null;
            $totalManifested = 0;

            foreach ($chunks as $chunkIndex => $chunk) {
                $result = $this->createUspsScanForm($connector, $chunk->values(), $fromAddress, $shipDate);

                if (! $result['success']) {
                    // If some chunks succeeded, report partial success
                    if ($totalManifested > 0) {
                        return ManifestResponse::failure(
                            "Partial manifest: {$totalManifested} packages manifested across "
                            .count($manifestNumbers).' form(s), but batch '.($chunkIndex + 1)
                            ." failed: {$result['error']}"
                        );
                    }

                    return ManifestResponse::failure($result['error']);
                }

                $manifestNumbers[] = $result['manifestNumber'];
                $lastImage = $result['image'];
                $totalManifested += $result['packageCount'];
            }

            $combinedNumber = implode(', ', $manifestNumbers);

            return ManifestResponse::success(
                manifestNumber: $combinedNumber,
                carrier: 'USPS',
                image: $lastImage,
            );
        } catch (\Exception $e) {
            logger()->error('USPS Scan Form Error', ['error' => $e->getMessage()]);

            return ManifestResponse::failure($e->getMessage());
        }
    }

    /**
     * Send a single USPS SCAN Form request for one chunk of packages,
     * with retry logic for already-manifested errors.
     *
     * @return array{success: bool, manifestNumber?: string, image?: string, packageCount?: int, error?: string}
     */
    private function createUspsScanForm(
        Connector $connector,
        Collection $packages,
        AddressData $fromAddress,
        ?CarbonImmutable $shipDate = null,
    ): array {
        $remainingPackages = $packages;
        $totalMarkedExternally = 0;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = $this->sendScanFormRequest($connector, $remainingPackages, $fromAddress, $shipDate);
            } catch (RequestException $e) {
                $errorResponse = $e->getResponse();
                $alreadyManifested = $this->extractAlreadyManifestedBarcodes(
                    $errorResponse->json('error.errors', [])
                );

                if (empty($alreadyManifested)) {
                    throw $e;
                }

                // Create a placeholder manifest for externally manifested packages
                $externalManifest = Manifest::create([
                    'carrier' => 'USPS',
                    'manifest_number' => 'EXTERNAL-'.now()->format('YmdHis'),
                    'manifest_date' => now()->toDateString(),
                    'package_count' => count($alreadyManifested),
                ]);

                $marked = Package::query()
                    ->whereIn('tracking_number', $alreadyManifested)
                    ->whereNull('manifest_id')
                    ->update(['manifest_id' => $externalManifest->id]);
                $totalMarkedExternally += $marked;

                logger()->warning('USPS SCAN Form: marked packages as already manifested', [
                    'tracking_numbers' => $alreadyManifested,
                    'count' => $marked,
                ]);

                $remainingPackages = $remainingPackages->reject(
                    fn ($p) => in_array($p->tracking_number, $alreadyManifested)
                )->values();

                if ($remainingPackages->isEmpty()) {
                    return [
                        'success' => false,
                        'error' => "All {$totalMarkedExternally} packages were already manifested by USPS and have been cleared.",
                    ];
                }

                continue;
            }

            $response->parseBody();

            $manifestNumber = $response->metadata['manifestNumber'] ?? '';
            $image = $response->image;

            $manifest = DB::transaction(function () use ($manifestNumber, $image, $remainingPackages) {
                $manifest = Manifest::create([
                    'carrier' => 'USPS',
                    'manifest_number' => $manifestNumber,
                    'image' => $image,
                    'manifest_date' => now()->toDateString(),
                    'package_count' => $remainingPackages->count(),
                ]);

                Package::whereIn('id', $remainingPackages->pluck('id'))
                    ->update(['manifest_id' => $manifest->id]);

                return $manifest;
            });

            ManifestCreatedEvent::dispatch($manifest, $remainingPackages->count());

            return [
                'success' => true,
                'manifestNumber' => $manifestNumber,
                'image' => $image,
                'packageCount' => $remainingPackages->count(),
            ];
        }

        return ['success' => false, 'error' => 'USPS SCAN Form failed after multiple retries.'];
    }

    /**
     * Send a SCAN Form request for the given packages.
     */
    private function sendScanFormRequest(
        Connector $connector,
        Collection $packages,
        AddressData $fromAddress,
        ?CarbonImmutable $shipDate = null,
    ): Response {
        $trackingNumbers = $packages->pluck('tracking_number')->values()->all();

        $request = new ScanForm;
        $request->body()->set([
            'form' => '5630',
            'imageType' => 'PDF',
            'labelType' => '8.5x11LABEL',
            'mailingDate' => $shipDate?->format('Y-m-d') ?? now()->format('Y-m-d'),
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
    private function extractAlreadyManifestedBarcodes(array $errors): array
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

    private function createFedexManifest(): ManifestResponse
    {
        return ManifestResponse::failure('FedEx end-of-day manifest is not yet implemented.');
    }
}
