<?php

namespace App\Services;

use App\Models\Carrier;
use App\Models\CarrierAlias;
use App\Models\CarrierService;
use App\Services\ServiceInference\ServiceRuleset;
use Illuminate\Support\Str;

/**
 * The catalog service a Label's service is, where nothing at purchase named it
 * — issue `postage-source-split/15`.
 *
 * A quoted purchase needs none of this: the rate already carries its
 * `CarrierService`, and the Label records it. This is for the two cases that
 * reach a Label with only a name — a service the inference ladder derived, and,
 * once, every Label bought before the column existed.
 *
 * Every answer is snapshotted where it is written and never asked again on
 * read, so renaming a catalog row or editing a table later does not change what
 * an earlier Label meant.
 */
class CatalogServiceResolver
{
    public function __construct(private readonly ServiceRuleset $ruleset) {}

    /**
     * The catalog service an inferred service name is, through the ruleset's
     * own table — the same vocabulary that produced the name.
     */
    public function forInferredService(?int $carrierId, ?string $service): ?int
    {
        $carrier = $carrierId === null ? null : Carrier::find($carrierId);

        if ($carrier === null) {
            return null;
        }

        $serviceCode = $this->ruleset->catalogServiceCodeFor($carrier->name, $service);

        return $serviceCode === null ? null : $this->serviceWithCode($carrier, $serviceCode);
    }

    /**
     * The catalog service a source-reported service name is, for a Label that
     * predates the column — read by the backfill only.
     *
     * The whole name first, compared the way carrier names are (case, spacing
     * and ® aside), which settles UPS, FedEx, Amazon Shipping and anything else
     * whose source names a service the way the catalog does. USPS alone
     * reports the mail class with its rate detail fused on — "USPS Ground
     * Advantage Machinable Cubic Non-Soft Pack Tier 2" — so a USPS name also
     * matches the longest catalog name it starts with. Nowhere else: "UPS
     * Ground" starts "UPS Ground Saver", which is a different service.
     */
    public function forReportedService(?int $carrierId, ?string $service): ?int
    {
        $carrier = $carrierId === null ? null : Carrier::find($carrierId);

        if ($carrier === null || blank($service)) {
            return null;
        }

        // Oldest first, so where two rows share a name the seeded one wins, as
        // it does for a shared code in `IdentifiesCatalogServices`.
        $services = $carrier->carrierServices()->orderBy('id')->get(['id', 'name']);
        $reported = CarrierAlias::lookupKey($service);

        $exact = $services->first(fn (CarrierService $candidate): bool => CarrierAlias::lookupKey($candidate->name) === $reported);

        if ($exact !== null || $carrier->name !== Carrier::USPS) {
            return $exact?->id;
        }

        // Without the carrier's own name, which the source prefixes to some
        // classes and the catalog to none.
        $mailClass = Str::chopStart($reported, 'usps ');

        return $services
            ->mapWithKeys(fn (CarrierService $candidate): array => [
                $candidate->id => Str::chopStart(CarrierAlias::lookupKey($candidate->name), 'usps '),
            ])
            ->filter(fn (string $name): bool => $mailClass === $name || str_starts_with($mailClass, "{$name} "))
            // "Priority Mail Express …" also starts with "Priority Mail".
            ->sortByDesc(fn (string $name): int => mb_strlen($name))
            ->keys()
            ->first();
    }

    private function serviceWithCode(Carrier $carrier, string $serviceCode): ?int
    {
        return $carrier->carrierServices()
            ->where('service_code', $serviceCode)
            ->orderBy('id')
            ->value('id');
    }
}
