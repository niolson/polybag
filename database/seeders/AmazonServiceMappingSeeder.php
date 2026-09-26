<?php

namespace Database\Seeders;

use App\Enums\PostageSourceKind;
use App\Filament\Pages\ServiceApprovals;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\SourceServiceMapping;

/**
 * What Amazon Buy Shipping's identifiers name — `carrier-catalog-reset/11`.
 *
 * Each row maps one `carrierId:serviceId` pair Amazon has returned to the
 * catalog service it sells, as signed off on 2026-09-26. Drawn from captured
 * `getRates` responses, and covering the carriers
 * {@see ServiceApprovals::US_BUY_SHIPPING_CARRIERS} names.
 *
 * Inward only, so several identifiers may name one service. Most of those
 * differ only by packaging — flat-rate boxes, One Rate, cubic — and packaging
 * stays on the rate (ADR-0005). Saturday variants map to their base service:
 * the due-by date decides whether the extra day is worth its price.
 *
 * Deliberately unmapped, and so left to a person:
 *
 * - `USPS_PTP_BPM` and `UPS_PTP_SUREPOST_BPM`: Bound Printed Matter is not
 *   authored (ADR-0006 decision 11).
 * - `UPS_PTP_GROUNDSAVER`: tied to neither Ground Saver weight band. Amazon has
 *   refused it at 8 oz and 12 oz only for the delivery promise, so it is not
 *   the 1 lb and over band its name suggests.
 * - `DHLMX_PTP_PACKAGE_EXPRESS`: only ever seen ineligible, from what looks like
 *   DHL's Mexico entity, so not safely DHL Express Worldwide.
 */
class AmazonServiceMappingSeeder extends OnceOnlySeeder
{
    /**
     * `carrierId:serviceId` => [carrier name, catalog service code].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const MAPPINGS = [
        'AMZN_US:std-us-swa-mfn' => [Carrier::AMAZON_SHIPPING, 'std-us-swa-mfn'],
        'ONTRAC:ONTRAC_MFN_GROUND' => [Carrier::ONTRAC, 'ONTRAC_MFN_GROUND'],

        'USPS:USPS_PTP_GAL' => [Carrier::USPS, 'USPS_GROUND_ADVANTAGE'],
        'USPS:USPS_PTP_GAH' => [Carrier::USPS, 'USPS_GROUND_ADVANTAGE'],
        'USPS:USPS_PTP_GAC' => [Carrier::USPS, 'USPS_GROUND_ADVANTAGE'],
        'USPS:USPS_PTP_FC_CUSTOMS' => [Carrier::USPS, 'USPS_GROUND_ADVANTAGE'],
        // "USPS First Class": First-Class Package became Ground Advantage in 2023.
        'USPS:USPS_PTP_FC' => [Carrier::USPS, 'USPS_GROUND_ADVANTAGE'],
        'USPS:USPS_PTP_PRI' => [Carrier::USPS, 'PRIORITY_MAIL'],
        'USPS:USPS_PTP_PRI_CUBIC' => [Carrier::USPS, 'PRIORITY_MAIL'],
        'USPS:USPS_PTP_PRI_CUSTOMS' => [Carrier::USPS, 'PRIORITY_MAIL'],
        'USPS:USPS_PTP_PRI_FRE' => [Carrier::USPS, 'PRIORITY_MAIL'],
        'USPS:USPS_PTP_PRI_LFRE' => [Carrier::USPS, 'PRIORITY_MAIL'],
        'USPS:USPS_PTP_PRI_PFRE' => [Carrier::USPS, 'PRIORITY_MAIL'],
        'USPS:USPS_PTP_PRI_SFRB' => [Carrier::USPS, 'PRIORITY_MAIL'],
        'USPS:USPS_PTP_PRI_MFRB' => [Carrier::USPS, 'PRIORITY_MAIL'],
        'USPS:USPS_PTP_PRI_LFRB' => [Carrier::USPS, 'PRIORITY_MAIL'],
        'USPS:USPS_PTP_PRI_LFRB_CUSTOMS' => [Carrier::USPS, 'PRIORITY_MAIL'],
        'USPS:USPS_PTP_EXP' => [Carrier::USPS, 'PRIORITY_MAIL_EXPRESS'],
        'USPS:USPS_PTP_EXP_CUSTOMS' => [Carrier::USPS, 'PRIORITY_MAIL_EXPRESS'],
        'USPS:USPS_PTP_EXP_FRE' => [Carrier::USPS, 'PRIORITY_MAIL_EXPRESS'],
        'USPS:USPS_PTP_EXP_LFRE' => [Carrier::USPS, 'PRIORITY_MAIL_EXPRESS'],
        'USPS:USPS_PTP_EXP_PFRE' => [Carrier::USPS, 'PRIORITY_MAIL_EXPRESS'],
        'USPS:USPS_PTP_PRI_INTL' => [Carrier::USPS, 'PRIORITY_MAIL_INTERNATIONAL'],
        'USPS:USPS_PTP_PRI_FRE_INTL' => [Carrier::USPS, 'PRIORITY_MAIL_INTERNATIONAL'],
        'USPS:USPS_PTP_PRI_SFRB_INTL' => [Carrier::USPS, 'PRIORITY_MAIL_INTERNATIONAL'],
        'USPS:USPS_PTP_PRI_MFRB_INTL' => [Carrier::USPS, 'PRIORITY_MAIL_INTERNATIONAL'],
        'USPS:USPS_PTP_PRI_LFRB_INTL' => [Carrier::USPS, 'PRIORITY_MAIL_INTERNATIONAL'],
        'USPS:USPS_PTP_EXP_INTL' => [Carrier::USPS, 'PRIORITY_MAIL_EXPRESS_INTERNATIONAL'],
        'USPS:USPS_PTP_EXP_FRE_INTL' => [Carrier::USPS, 'PRIORITY_MAIL_EXPRESS_INTERNATIONAL'],
        'USPS:USPS_PTP_FC_INTL' => [Carrier::USPS, 'FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE'],
        'USPS:USPS_PTP_MM' => [Carrier::USPS, 'MEDIA_MAIL'],
        'USPS:USPS_PTP_PSBN' => [Carrier::USPS, 'PARCEL_SELECT'],

        'UPS:UPS_PTP_GND' => [Carrier::UPS, '03'],
        'UPS:UPS_PTP_3DAY_SELECT' => [Carrier::UPS, '12'],
        'UPS:UPS_PTP_2ND_DAY_AIR' => [Carrier::UPS, '02'],
        'UPS:UPS_PTP_2ND_DAY_AIR_SAT' => [Carrier::UPS, '02'],
        'UPS:UPS_PTP_NEXT_DAY_AIR' => [Carrier::UPS, '01'],
        'UPS:UPS_PTP_NEXT_DAY_AIR_SAT' => [Carrier::UPS, '01'],
        'UPS:UPS_PTP_NEXT_DAY_AIR_SAVER' => [Carrier::UPS, '13'],
        'UPS:UPS_PTP_SUREPOST_L' => [Carrier::UPS, '92'],
        'UPS:UPS_PTP_SUREPOST_H' => [Carrier::UPS, '93'],
        // Carries Ground Saver Media's media requirement, so a Package that
        // does not qualify never sees it.
        'UPS:UPS_PTP_SUREPOST_MEDIA' => [Carrier::UPS, '95'],
        'UPS:UPS_EXPRESS_INTL' => [Carrier::UPS, '07'],
        'UPS:UPS_EXPEDITED_INTL' => [Carrier::UPS, '08'],
        'UPS:UPS_SAVER_INTL' => [Carrier::UPS, '65'],
        'UPS:UPS_STANDARD_INTL' => [Carrier::UPS, '11'],

        'FEDEX:FEDEX_PTP_GROUND' => [Carrier::FEDEX, 'FEDEX_GROUND'],
        'FEDEX:FEDEX_PTP_HOME_DELIVERY' => [Carrier::FEDEX, 'GROUND_HOME_DELIVERY'],
        'FEDEX:FEDEX_PTP_SMARTPOST' => [Carrier::FEDEX, 'SMART_POST'],
        'FEDEX:FEDEX_PTP_EXPRESS_SAVER' => [Carrier::FEDEX, 'FEDEX_EXPRESS_SAVER'],
        'FEDEX:FEDEX_PTP_EXPRESS_SAVER_ONE_RATE' => [Carrier::FEDEX, 'FEDEX_EXPRESS_SAVER'],
        'FEDEX:FEDEX_PTP_SECOND_DAY' => [Carrier::FEDEX, 'FEDEX_2_DAY'],
        'FEDEX:FEDEX_PTP_SECOND_DAY_ONE_RATE' => [Carrier::FEDEX, 'FEDEX_2_DAY'],
        'FEDEX:FEDEX_PTP_SECOND_DAY_SAT' => [Carrier::FEDEX, 'FEDEX_2_DAY'],
        'FEDEX:FEDEX_PTP_SEC_DAY_ONE_RATE_SAT' => [Carrier::FEDEX, 'FEDEX_2_DAY'],
        'FEDEX:FEDEX_PTP_SECOND_DAY_AM' => [Carrier::FEDEX, 'FEDEX_2_DAY_AM'],
        'FEDEX:FEDEX_PTP_SECOND_DAY_AM_ONE_RATE' => [Carrier::FEDEX, 'FEDEX_2_DAY_AM'],
        'FEDEX:FEDEX_PTP_STANDARD_OVERNIGHT' => [Carrier::FEDEX, 'STANDARD_OVERNIGHT'],
        'FEDEX:FEDEX_PTP_STANDARD_OVERNIGHT_ONE_RATE' => [Carrier::FEDEX, 'STANDARD_OVERNIGHT'],
        'FEDEX:FEDEX_PTP_PRIORITY_OVERNIGHT' => [Carrier::FEDEX, 'PRIORITY_OVERNIGHT'],
        'FEDEX:FEDEX_PTP_PRIORITY_OVERNIGHT_ONE_RATE' => [Carrier::FEDEX, 'PRIORITY_OVERNIGHT'],
        'FEDEX:FEDEX_PTP_PRI_OVERNIGHT_SAT' => [Carrier::FEDEX, 'PRIORITY_OVERNIGHT'],
        'FEDEX:FEDEX_PTP_PRI_OVERN_ONE_R_SAT' => [Carrier::FEDEX, 'PRIORITY_OVERNIGHT'],
    ];

    public const BATCH = 'amazon-mappings-v1';

    protected function batch(): string
    {
        return self::BATCH;
    }

    /**
     * A pair whose service is missing is skipped: `CarrierSeeder` runs first
     * and seeds every one, so a missing row is one an operator deleted.
     *
     * So is a pair already mapped. The mapping page is open to Admins before
     * this batch first runs, and their choice stands over the signed-off one.
     */
    protected function seed(): void
    {
        foreach (self::MAPPINGS as $identity => [$carrierName, $serviceCode]) {
            [$externalCarrierId, $externalServiceId] = explode(':', $identity, 2);

            if (SourceServiceMapping::query()->forIdentity(PostageSourceKind::Amazon, $externalCarrierId, $externalServiceId)->exists()) {
                continue;
            }

            $serviceId = CarrierService::query()
                ->where('service_code', $serviceCode)
                ->whereHas('carrier', fn ($query) => $query->where('name', $carrierName))
                ->value('id');

            if ($serviceId === null) {
                continue;
            }

            SourceServiceMapping::map(PostageSourceKind::Amazon, $externalCarrierId, $externalServiceId, $serviceId);
        }
    }
}
