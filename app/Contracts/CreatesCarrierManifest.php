<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\ManifestResponse;
use App\Models\Package;
use App\Services\ManifestService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A carrier adapter that produces its own end-of-day form.
 *
 * The start of routing manifests through the adapters rather than a branch
 * per carrier in {@see ManifestService}. The adapter only talks to the carrier
 * and hands back the form; recording the manifest and stamping the Packages
 * on it stay with `ManifestService`, so every adapter that implements this
 * gets the same bookkeeping.
 *
 * USPS still runs through `ManifestService`'s own SCAN form path, which
 * splits by purchasing account and retries around already-manifested
 * tracking numbers. Moving it here is a later step.
 */
interface CreatesCarrierManifest
{
    /**
     * Ask the carrier for one form covering every one of these Packages.
     *
     * Called only when {@see CarrierPolicy::supportsCarrierManifest()} is true
     * and after `ManifestService` has checked each Package was bought on a
     * carrier account of ours.
     *
     * @param  Collection<int, Package>  $packages
     * @return ManifestResponse The form's number and its base64 PDF on success
     */
    public function createCarrierManifest(Collection $packages, AddressData $fromAddress, CarbonImmutable $mailingDate): ManifestResponse;
}
