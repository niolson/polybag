<?php

namespace App\Services;

use App\Contracts\CreatesCarrierManifest;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\ManifestResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Events\ManifestCreated as ManifestCreatedEvent;
use App\Http\Integrations\USPS\Requests\ScanForm;
use App\Http\Integrations\USPS\USPSConnector;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Location;
use App\Models\Manifest;
use App\Models\Package;
use App\Models\ScanFormRequest;
use App\Services\Carriers\CarrierRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Connector;
use Saloon\Http\Response;

class ManifestService
{
    /**
     * Get unmanifested shipped packages grouped by carrier, optionally scoped to a location.
     *
     * @return Collection<string, Collection<int, Package>>
     */
    public function getUnmanifestedPackages(?int $locationId = null): Collection
    {
        return Package::query()
            ->whereNull('manifest_id')
            ->where('status', PackageStatus::Shipped)
            ->boughtOnCarrierAccount()
            ->whereNotNull('tracking_number')
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->with('shipment')
            ->get()
            ->groupBy('carrier');
    }

    /**
     * Create a manifest for the given carrier and packages.
     *
     * Eligibility is a question for the postage source, not the carrier. The
     * queries that feed this already filter on provenance; the guard is here
     * because the rule protects a request to USPS, and a caller assembling its
     * own collection must not be able to route around it.
     *
     * A carrier whose adapter creates its own form ({@see CreatesCarrierManifest})
     * is asked for it; the rest still dispatch by name below.
     */
    public function createManifest(string $carrier, Collection $packages, ?CarbonImmutable $shipDate = null, ?int $locationId = null): ManifestResponse
    {
        if ($packages->contains(fn (Package $package): bool => $package->postage_source !== PostageSource::CarrierAccount)) {
            return ManifestResponse::failure(
                'One or more packages were not bought on a carrier account of ours, so they cannot go on a manifest we create.'
            );
        }

        $adapter = app(CarrierRegistry::class)->directAdapterFor($carrier);

        if ($adapter instanceof CreatesCarrierManifest && $adapter->supportsCarrierManifest()) {
            return $this->createAdapterManifest($adapter, $carrier, $packages, $shipDate, $locationId);
        }

        return match ($carrier) {
            Carrier::USPS => $this->createUspsManifest($packages, $shipDate, $locationId),
            Carrier::FEDEX => $this->createFedexManifest(),
            default => ManifestResponse::failure(Carrier::labelForName($carrier).' does not support end-of-day manifests.'),
        };
    }

    /**
     * Ask the adapter for the form, then record it and put the Packages on it,
     * as the USPS path does for each SCAN form.
     *
     * @param  Collection<int, Package>  $packages
     */
    private function createAdapterManifest(CreatesCarrierManifest $adapter, string $carrier, Collection $packages, ?CarbonImmutable $shipDate, ?int $locationId): ManifestResponse
    {
        try {
            $location = $locationId ? Location::find($locationId) : null;
            $fromAddress = $location ? AddressData::fromLocation($location) : AddressData::fromConfig();

            $response = $adapter->createCarrierManifest($packages->values(), $fromAddress, $shipDate ?? CarbonImmutable::now());
        } catch (\Exception $e) {
            logger()->error('Carrier manifest error', ['carrier' => $carrier, 'error' => $e->getMessage()]);

            return ManifestResponse::failure('The manifest could not be created. Try again, or ask an administrator to check the log.');
        }

        if (! $response->success) {
            return $response;
        }

        $manifest = DB::transaction(function () use ($response, $carrier, $packages, $locationId): Manifest {
            $manifest = Manifest::create([
                'carrier' => $carrier,
                'location_id' => $locationId,
                'manifest_number' => $response->manifestNumber,
                'image' => $response->image,
                'manifest_date' => now()->toDateString(),
                'package_count' => $packages->count(),
            ]);

            $this->markManifested($packages->pluck('tracking_number')->all(), $manifest);

            return $manifest;
        });

        ManifestCreatedEvent::dispatch($manifest, $packages->count());

        return ManifestResponse::success(
            manifestNumber: (string) $response->manifestNumber,
            carrier: $carrier,
            image: $response->image,
            manifests: [$manifest],
        );
    }

    /**
     * USPS limits SCAN Forms to 10,000 tracking numbers per request.
     */
    private const USPS_MANIFEST_CHUNK_SIZE = 10_000;

    private function resolveUspsAccount(Package $package, ?int $locationId): ?CarrierAccount
    {
        if ($package->carrierAccount) {
            return $package->carrierAccount;
        }

        $carrierId = Carrier::where('name', Carrier::USPS)->value('id');

        return $carrierId
            ? CarrierAccount::resolveForShipment(
                $carrierId,
                $package->location_id ?? $locationId,
                $package->shipment?->client_id,
            )->first()
            : null;
    }

    private function createUspsManifest(Collection $packages, ?CarbonImmutable $shipDate = null, ?int $locationId = null): ManifestResponse
    {
        try {
            $location = $locationId ? Location::find($locationId) : null;
            $fromAddress = $location ? AddressData::fromLocation($location) : AddressData::fromConfig();
            $resolvedAccounts = [];

            // Batch-eager-load the relations the account resolution needs (2 queries
            // total) rather than one query per package. loadMissing mutates the shared
            // model instances, so $packages sees the loaded relations.
            EloquentCollection::make($packages->all())
                ->loadMissing(['carrierAccount', 'shipment']);

            $packagesByAccount = $packages->groupBy(function (Package $package) use (&$resolvedAccounts, $locationId): string {
                $account = $this->resolveUspsAccount($package, $locationId);

                if (! $account) {
                    return 'unresolved';
                }

                $resolvedAccounts[$account->id] = $account;

                return (string) $account->id;
            });

            if ($packagesByAccount->has('unresolved')) {
                return ManifestResponse::failure('No USPS carrier account could be resolved for one or more packages.');
            }

            $manifestNumbers = [];
            $manifests = [];
            $totalManifested = 0;
            $batchNumber = 0;

            foreach ($packagesByAccount as $accountId => $accountPackages) {
                $connector = USPSConnector::getAuthenticatedConnector($resolvedAccounts[(int) $accountId]);

                foreach ($accountPackages->chunk(self::USPS_MANIFEST_CHUNK_SIZE) as $chunk) {
                    $batchNumber++;
                    $result = $this->createUspsScanForm($connector, $chunk->values(), $fromAddress, $shipDate, $locationId);

                    if (! $result['success']) {
                        if ($totalManifested > 0) {
                            logger()->error('USPS SCAN form batch failed after earlier batches', ['batch' => $batchNumber, 'error' => $result['error']]);

                            return ManifestResponse::failure(
                                "Only part of the day was manifested: {$totalManifested} packages on "
                                .count($manifestNumbers).' '.Str::plural('form', count($manifestNumbers))
                                .", but batch {$batchNumber} failed. Run End of Day again for the rest."
                            );
                        }

                        return ManifestResponse::failure($result['error']);
                    }

                    $manifestNumbers[] = $result['manifest']->manifest_number;
                    $manifests[] = $result['manifest'];
                    $totalManifested += $result['packageCount'];
                }
            }

            return ManifestResponse::success(
                manifestNumber: implode(', ', $manifestNumbers),
                carrier: Carrier::USPS,
                image: end($manifests)->image,
                manifests: $manifests,
            );
        } catch (\Exception $e) {
            logger()->error('USPS Scan Form Error', ['error' => $e->getMessage()]);

            return ManifestResponse::failure($e instanceof \RuntimeException
                ? $e->getMessage()
                : 'The SCAN form could not be created. Try again, or ask an administrator to check the log.');
        }
    }

    /**
     * Send a single USPS SCAN Form request for one chunk of packages,
     * with retry logic for already-manifested errors.
     *
     * @return array{success: bool, manifest?: Manifest, packageCount?: int, error?: string}
     */
    private function createUspsScanForm(
        Connector $connector,
        Collection $packages,
        AddressData $fromAddress,
        ?CarbonImmutable $shipDate = null,
        ?int $locationId = null,
    ): array {
        $remainingPackages = $packages;
        $totalMarkedExternally = 0;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = $this->sendScanFormRequest($connector, $remainingPackages, $fromAddress, $shipDate);
            } catch (RequestException $e) {
                $errorResponse = $e->getResponse();

                // An error page or empty reply is not JSON, and reading it as JSON
                // would replace USPS's answer with "Syntax error".
                try {
                    $errors = $errorResponse->json('error.errors', []);
                } catch (\JsonException) {
                    $errors = [];
                }

                $alreadyManifested = $this->extractAlreadyManifestedBarcodes($errors);

                if (empty($alreadyManifested)) {
                    throw new \RuntimeException($this->describeScanFormFailure($errorResponse), previous: $e);
                }

                // Create a placeholder manifest for externally manifested packages
                $externalManifest = Manifest::create([
                    'carrier' => Carrier::USPS,
                    'location_id' => $locationId,
                    'manifest_number' => 'EXTERNAL-'.now()->format('YmdHis'),
                    'manifest_date' => now()->toDateString(),
                    'package_count' => count($alreadyManifested),
                ]);

                $marked = $this->markManifested($alreadyManifested, $externalManifest);
                $totalMarkedExternally += $marked;

                logger()->warning('USPS SCAN Form: marked packages as already manifested', [
                    'tracking_numbers' => $alreadyManifested,
                    'count' => $marked,
                ]);

                $remainingPackages = $remainingPackages->reject(
                    fn ($p): bool => in_array($p->tracking_number, $alreadyManifested)
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

            $manifest = DB::transaction(function () use ($manifestNumber, $image, $remainingPackages, $locationId, $shipDate) {
                $manifest = Manifest::create([
                    'carrier' => Carrier::USPS,
                    'location_id' => $locationId,
                    'manifest_number' => $manifestNumber,
                    'image' => $image,
                    'manifest_date' => now()->toDateString(),
                    'package_count' => $remainingPackages->count(),
                ]);

                $this->markManifested($remainingPackages->pluck('tracking_number')->all(), $manifest);

                ScanFormRequest::forgetTrackingNumbers(
                    $shipDate?->format('Y-m-d') ?? now()->format('Y-m-d'),
                    $remainingPackages->pluck('tracking_number')->all(),
                );

                return $manifest;
            });

            ManifestCreatedEvent::dispatch($manifest, $remainingPackages->count());

            return [
                'success' => true,
                'manifest' => $manifest,
                'packageCount' => $remainingPackages->count(),
            ];
        }

        return ['success' => false, 'error' => 'USPS SCAN Form failed after multiple retries.'];
    }

    /**
     * Put the Labels with these tracking numbers on a manifest: each shipped
     * Package still carrying one, and its active Label, together.
     *
     * Keyed by the tracking numbers USPS was sent rather than by Package, so a
     * Package voided while USPS was answering, or voided and re-shipped since,
     * is not stamped with a form its current Label is not on
     * (`project-review/07`).
     *
     * @param  array<int, string>  $trackingNumbers
     * @return int The number of Packages marked
     */
    private function markManifested(array $trackingNumbers, Manifest $manifest): int
    {
        return DB::transaction(function () use ($trackingNumbers, $manifest): int {
            $packageIds = Package::query()
                ->whereIn('tracking_number', $trackingNumbers)
                ->where('status', PackageStatus::Shipped)
                ->whereNull('manifest_id')
                ->lockForUpdate()
                ->pluck('id');

            Package::query()->whereKey($packageIds)->update(['manifest_id' => $manifest->id]);

            DB::table('package_labels')
                ->whereIn('package_id', $packageIds)
                ->whereNull('voided_at')
                ->update(['manifest_id' => $manifest->id, 'updated_at' => now()]);

            return $packageIds->count();
        });
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

        $mailingDate = $shipDate?->format('Y-m-d') ?? now()->format('Y-m-d');

        $request = new ScanForm;
        $request->headers()->set([
            'X-Idempotency-Key' => ScanFormRequest::forTrackingNumbers($mailingDate, $trackingNumbers)->idempotency_key,
        ]);
        $request->body()->set([
            'imageInfo' => [
                'imageType' => 'PDF',
                'labelType' => '8.5X11LABEL',
            ],
            'mailingDate' => $mailingDate,
            'overwriteMailingDate' => true,
            'entryFacilityZIPCode' => $fromAddress->postalCode,
            'destinationEntryFacilityType' => 'NONE',
            'shipment' => [
                'trackingNumbers' => $trackingNumbers,
            ],
            'fromAddress' => array_filter([
                ...$this->scanFormNameFields($fromAddress),
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
     * The shipper's name as USPS requires it, the way labels send it: first
     * and last name, or a single name as the firm, with the company as the
     * firm when there is one.
     *
     * @return array{firstName?: string, lastName?: string, firm?: string}
     */
    private function scanFormNameFields(AddressData $address): array
    {
        $fields = [];

        if ($address->firstName && $address->lastName) {
            $fields['firstName'] = mb_substr($address->firstName, 0, 50);
            $fields['lastName'] = mb_substr($address->lastName, 0, 50);
        } elseif ($address->firstName || $address->lastName) {
            $fields['firm'] = mb_substr((string) ($address->firstName ?: $address->lastName), 0, 50);
        }

        if ($address->company) {
            $fields['firm'] = mb_substr($address->company, 0, 50);
        }

        return $fields;
    }

    /**
     * What USPS answered, in a line an operator can read: its own message when the
     * reply is JSON, otherwise the status and the start of whatever came back.
     */
    private function describeScanFormFailure(Response $response): string
    {
        try {
            $message = $response->json('error.message');
            $details = collect($response->json('error.errors', []))
                ->pluck('detail')
                ->filter(fn (mixed $detail): bool => is_string($detail) && $detail !== '')
                ->implode('; ');
        } catch (\JsonException) {
            $message = null;
            $details = '';
        }

        // USPS's message is only the status text ("Bad Request"); the reason
        // is in each error's detail.
        if ($details !== '') {
            return "USPS rejected the SCAN form request (error {$response->status()}): {$details}";
        }

        if (is_string($message) && $message !== '') {
            return "USPS rejected the SCAN form request (error {$response->status()}): {$message}";
        }

        $body = trim((string) preg_replace('/\s+/', ' ', strip_tags($response->body())));
        $detail = $body === '' ? 'empty response' : mb_substr($body, 0, 200);

        return "USPS rejected the SCAN form request (error {$response->status()}): {$detail}";
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
