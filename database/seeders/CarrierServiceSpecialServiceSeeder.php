<?php

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\SpecialService;
use Illuminate\Database\Seeder;

class CarrierServiceSpecialServiceSeeder extends Seeder
{
    /**
     * Seed carrier-service-level special service scoping (which specific
     * carrier services can carry which special services, and to which
     * destination countries).
     *
     * These rows are code-owned carrier facts, transcribed from each carrier's
     * published restrictions (USPS rows from the official STC list and Pub 52;
     * UPS and FedEx from their service guides). Scoping is opt-in per
     * carrier: a special service with no rows for any of a carrier's services
     * is unrestricted for that carrier — which is why UPS has no signature
     * rows (DeliveryConfirmation is available across its services) and USPS
     * has no Saturday rows (it delivers Saturday as standard service).
     * Carrier-wide exclusions (e.g. USPS/UPS alcohol) live in the adapters'
     * capability maps, not here — absence of rows cannot express them.
     */
    public function run(): void
    {
        $fedexUsOnlyDomestic = [
            'GROUND_HOME_DELIVERY' => ['US'],
            'PRIORITY_OVERNIGHT' => ['US'],
            'STANDARD_OVERNIGHT' => ['US'],
            'FEDEX_2_DAY' => ['US'],
            'FEDEX_2_DAY_AM' => ['US'],
            'FEDEX_EXPRESS_SAVER' => ['US'],
        ];

        $scopesByCode = [
            // Mirrors the adapters' operational day maps (SATURDAY_DELIVERY_DAY_MAP)
            'saturday_delivery' => [
                'FedEx' => [
                    'FIRST_OVERNIGHT' => null,
                    'PRIORITY_OVERNIGHT' => null,
                    'STANDARD_OVERNIGHT' => null,
                    'FEDEX_2_DAY_AM' => null,
                    'FEDEX_2_DAY' => null,
                    'EXPRESS_SAVER' => null,
                    'FEDEX_EXPRESS_SAVER' => null,
                ],
                'UPS' => [
                    '14' => null, // Next Day Air Early
                    '01' => null, // Next Day Air
                    '13' => null, // Next Day Air Saver
                    '02' => null, // 2nd Day Air
                    '12' => null, // 3 Day Select
                ],
            ],

            // USPS 921 is STC-valid for Ground Advantage + Priority Mail (PME
            // uses code 981, not wired). FedEx DIRECT: US addresses, Canada
            // only via FedEx Ground; Ground Economy has no signature options.
            'signature_required' => [
                'USPS' => [
                    'USPS_GROUND_ADVANTAGE' => null,
                    'PRIORITY_MAIL' => null,
                ],
                'FedEx' => [
                    ...$fedexUsOnlyDomestic,
                    'FEDEX_GROUND' => ['US', 'CA'],
                ],
            ],

            // USPS 922 is STC-valid for GA, PM and PME. FedEx ADULT is US-only.
            'adult_signature_required' => [
                'USPS' => [
                    'USPS_GROUND_ADVANTAGE' => null,
                    'PRIORITY_MAIL' => null,
                    'PRIORITY_MAIL_EXPRESS' => null,
                ],
                'FedEx' => [
                    ...$fedexUsOnlyDomestic,
                    'FEDEX_GROUND' => ['US'],
                ],
            ],

            // USPS insurance (930/931) is STC-valid for GA + PM domestically
            // (PME uses 925, not wired) and 930/931 are in the international
            // enum. UPS/FedEx declared value is unrestricted (caps enforced at
            // rate time via declaredValueCap()).
            'declared_value' => [
                'USPS' => [
                    'USPS_GROUND_ADVANTAGE' => null,
                    'PRIORITY_MAIL' => null,
                    'PRIORITY_MAIL_INTERNATIONAL' => null,
                ],
            ],

            // FedEx-only (USPS prohibits, UPS parcel unsupported — capability
            // maps). Ground Economy (SmartPost) does not accept alcohol.
            'alcohol' => [
                'FedEx' => [
                    'GROUND_HOME_DELIVERY' => null,
                    'FEDEX_GROUND' => null,
                    'PRIORITY_OVERNIGHT' => null,
                    'STANDARD_OVERNIGHT' => null,
                    'FEDEX_2_DAY' => null,
                    'FEDEX_2_DAY_AM' => null,
                    'FEDEX_EXPRESS_SAVER' => null,
                    'FEDEX_INTERNATIONAL_PRIORITY' => null,
                    'INTERNATIONAL_ECONOMY' => null,
                ],
            ],

            // Section II in-equipment batteries may ship by air (Pub 52);
            // USPS 818 is domestic-only. FedEx Ground Economy excluded.
            'lithium_battery_in_equipment' => [
                'USPS' => [
                    'USPS_GROUND_ADVANTAGE' => null,
                    'PRIORITY_MAIL' => null,
                    'PRIORITY_MAIL_EXPRESS' => null,
                ],
                'FedEx' => [
                    'GROUND_HOME_DELIVERY' => null,
                    'FEDEX_GROUND' => null,
                    'PRIORITY_OVERNIGHT' => null,
                    'STANDARD_OVERNIGHT' => null,
                    'FEDEX_2_DAY' => null,
                    'FEDEX_2_DAY_AM' => null,
                    'FEDEX_EXPRESS_SAVER' => null,
                ],
            ],

            // Standalone lithium is surface-only for USPS (Pub 52). UPS and
            // FedEx are gated out via capability maps — the FedEx production
            // availability probe (2026-07-09) confirmed STANDALONE_BATTERY is
            // not offered on any FedEx service.
            'lithium_battery_standalone' => [
                'USPS' => [
                    'USPS_GROUND_ADVANTAGE' => null,
                ],
            ],

            // Ground-network services only, on every carrier.
            'lithium_battery_ground_only' => [
                'USPS' => [
                    'USPS_GROUND_ADVANTAGE' => null,
                ],
                'UPS' => [
                    '03' => null, // Ground
                ],
                'FedEx' => [
                    'GROUND_HOME_DELIVERY' => null,
                    'FEDEX_GROUND' => null,
                ],
            ],
        ];

        foreach ($scopesByCode as $code => $carriers) {
            $specialService = SpecialService::where('code', $code)->first();

            if (! $specialService) {
                continue;
            }

            $syncPayload = [];

            foreach ($carriers as $carrierName => $serviceScopes) {
                $carrier = Carrier::where('name', $carrierName)->first();

                if (! $carrier) {
                    continue;
                }

                $carrierServiceIds = $carrier->carrierServices()
                    ->whereIn('service_code', array_keys($serviceScopes))
                    ->pluck('id', 'service_code');

                foreach ($serviceScopes as $serviceCode => $restrictedCountries) {
                    $carrierServiceId = $carrierServiceIds->get($serviceCode);

                    if ($carrierServiceId) {
                        $syncPayload[$carrierServiceId] = ['restricted_countries' => $restrictedCountries];
                    }
                }
            }

            // Full sync: these rows are code-owned carrier facts, so the seeder
            // is authoritative — rows removed from the map are detached on reseed.
            $specialService->carrierServices()->sync($syncPayload);
        }
    }
}
