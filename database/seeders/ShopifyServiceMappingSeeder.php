<?php

namespace Database\Seeders;

use App\Enums\PostageSourceKind;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\SourceServiceMapping;

/**
 * What Shopify is offered for — `carrier-catalog-reset/09`.
 *
 * Each row maps one of Shopify's `preferredRateSelection` codes to the catalog
 * service it buys. Outward: a purchase sends exactly one code for a service. The
 * `carrier:service` pair is split at the colon, and purchase joins it back.
 *
 * Shopify publishes no list of these codes and has no API to enumerate them, so
 * the pairs were established by probe (`shopify-shipping-carrier/02`). There is
 * no single vocabulary: each carrier keeps its own, and Shopify passes it
 * through. All are matched case-sensitively: `priority` finds no rate where
 * `Priority` does.
 *
 * - USPS: the product name in PascalCase — `GroundAdvantage`, not
 *   `USPS_GROUND_ADVANTAGE`. Domestic Priority drops the "Mail" (`Priority`);
 *   international keeps it and the trailing "Service".
 * - UPS: UPS's own numeric codes, the same alphabet as our catalog.
 * - DHL: DHL's own product codes, where `P` is Express Worldwide.
 */
class ShopifyServiceMappingSeeder extends OnceOnlySeeder
{
    /**
     * `carrier:service` code => [carrier name, catalog service code].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const MAPPINGS = [
        'usps:GroundAdvantage' => [Carrier::USPS, 'USPS_GROUND_ADVANTAGE'],
        'usps:Priority' => [Carrier::USPS, 'PRIORITY_MAIL'],
        'usps:PriorityExpress' => [Carrier::USPS, 'PRIORITY_MAIL_EXPRESS'],
        'usps:MediaMail' => [Carrier::USPS, 'MEDIA_MAIL'],
        'usps:FirstClassPackageInternationalService' => [Carrier::USPS, 'FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE'],
        'usps:PriorityMailInternational' => [Carrier::USPS, 'PRIORITY_MAIL_INTERNATIONAL'],
        'usps:PriorityMailExpressInternational' => [Carrier::USPS, 'PRIORITY_MAIL_EXPRESS_INTERNATIONAL'],
        'ups_shipping:03' => [Carrier::UPS, '03'],
        'ups_shipping:12' => [Carrier::UPS, '12'],
        'ups_shipping:02' => [Carrier::UPS, '02'],
        'ups_shipping:59' => [Carrier::UPS, '59'],
        'ups_shipping:13' => [Carrier::UPS, '13'],
        'ups_shipping:01' => [Carrier::UPS, '01'],
        'ups_shipping:14' => [Carrier::UPS, '14'],
        // Shopify reproduces UPS's split by weight exactly: a 0.3lb parcel
        // finds a rate for 92 and none for 93, a 5lb parcel the reverse.
        'ups_shipping:92' => [Carrier::UPS, '92'],
        'ups_shipping:93' => [Carrier::UPS, '93'],
        'ups_shipping:07' => [Carrier::UPS, '07'],
        'ups_shipping:08' => [Carrier::UPS, '08'],
        'ups_shipping:65' => [Carrier::UPS, '65'],
        'ups_shipping:11' => [Carrier::UPS, '11'],
        'dhl_express:P' => [Carrier::DHL_EXPRESS, 'P'],
    ];

    public const BATCH = 'shopify-mappings-v1';

    protected function batch(): string
    {
        return self::BATCH;
    }

    /**
     * A pair whose service is missing is skipped: `CarrierSeeder` runs first
     * and seeds every one, so a missing row is one an operator deleted.
     */
    protected function seed(): void
    {
        foreach (self::MAPPINGS as $code => [$carrierName, $serviceCode]) {
            $serviceId = CarrierService::query()
                ->where('service_code', $serviceCode)
                ->whereHas('carrier', fn ($query) => $query->where('name', $carrierName))
                ->value('id');

            if ($serviceId === null) {
                continue;
            }

            [$externalCarrierId, $externalServiceId] = explode(':', $code, 2);

            SourceServiceMapping::map(PostageSourceKind::Shopify, $externalCarrierId, $externalServiceId, $serviceId);
        }
    }
}
