<?php

namespace Database\Seeders;

use App\Enums\ContentClass;
use App\Models\Carrier;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use Illuminate\Database\Seeder;

class CarrierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Every carrier seeded here is a system carrier: its name is fixed, so
        // this finds the same row on every start (ADR-0006 decision 1).
        $usps = Carrier::seedSystem(Carrier::USPS, ['pickup_cutoff_hour' => 20]);
        foreach ([
            ['name' => 'Ground Advantage', 'service_code' => 'USPS_GROUND_ADVANTAGE'],
            ['name' => 'Priority Mail', 'service_code' => 'PRIORITY_MAIL'],
            ['name' => 'Priority Mail Express', 'service_code' => 'PRIORITY_MAIL_EXPRESS'],
            ['name' => 'First-Class Package International Service', 'service_code' => 'FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE'],
            ['name' => 'Priority Mail International', 'service_code' => 'PRIORITY_MAIL_INTERNATIONAL'],
            ['name' => 'Priority Mail Express International', 'service_code' => 'PRIORITY_MAIL_EXPRESS_INTERNATIONAL'],
            // Offered only to a Package whose every item is a product the
            // seller marked as media (ADR-0006 decision 11). Library Mail and
            // Bound Printed Matter are not authored.
            ['name' => 'Media Mail', 'service_code' => 'MEDIA_MAIL', 'required_contents' => ContentClass::Media],
        ] as $service) {
            $requiredContents = $service['required_contents'] ?? null;

            // Every USPS service is USPS -- there's no scenario in this app
            // where a USPS service should be blocked from a PO Box or a
            // military address.
            $row = $usps->carrierServices()->firstOrCreate(
                ['service_code' => $service['service_code']],
                [
                    'name' => $service['name'],
                    'can_ship_to_po_boxes' => true,
                    'can_ship_to_military_addresses' => true,
                    'required_contents' => $requiredContents,
                ],
            );

            // A content requirement is what keeps the service off a parcel
            // that does not qualify, so it is restored on every sync rather
            // than only written when the row is created: a row an operator
            // made by hand before this was seeded would otherwise sell Media
            // Mail for anything.
            if ($requiredContents !== null && $row->required_contents !== $requiredContents) {
                $row->update(['required_contents' => $requiredContents]);
            }
        }

        $fedex = Carrier::seedSystem(Carrier::FEDEX);
        foreach ([
            ['name' => 'FedEx Home Delivery®', 'service_code' => 'GROUND_HOME_DELIVERY'],
            ['name' => 'FedEx Ground®', 'service_code' => 'FEDEX_GROUND'],
            ['name' => 'FedEx Ground® Economy', 'service_code' => 'SMART_POST'],
            ['name' => 'FedEx International Priority®', 'service_code' => 'FEDEX_INTERNATIONAL_PRIORITY'],
            ['name' => 'FedEx International Priority® Express', 'service_code' => 'FEDEX_INTERNATIONAL_PRIORITY_EXPRESS'],
            ['name' => 'FedEx International First®', 'service_code' => 'INTERNATIONAL_FIRST'],
            ['name' => 'FedEx International Economy®', 'service_code' => 'INTERNATIONAL_ECONOMY'],
            ['name' => 'FedEx International Connect Plus®', 'service_code' => 'FEDEX_INTERNATIONAL_CONNECT_PLUS'],
            ['name' => 'FedEx First Overnight®', 'service_code' => 'FIRST_OVERNIGHT'],
            ['name' => 'FedEx Priority Overnight®', 'service_code' => 'PRIORITY_OVERNIGHT'],
            ['name' => 'FedEx Standard Overnight®', 'service_code' => 'STANDARD_OVERNIGHT'],
            ['name' => 'FedEx 2Day®', 'service_code' => 'FEDEX_2_DAY'],
            ['name' => 'FedEx 2Day® A.M.', 'service_code' => 'FEDEX_2_DAY_AM'],
            ['name' => 'FedEx Express Saver®', 'service_code' => 'FEDEX_EXPRESS_SAVER'],
        ] as $service) {
            // FedEx Ground Economy (service code SMART_POST, the FedEx API's
            // longstanding name for it) is FedEx's only USPS-last-mile
            // service -- plain FedEx Ground/Home Delivery/Express etc. cannot
            // reach a PO Box or military address and correctly stay false.
            $isGroundEconomy = $service['service_code'] === 'SMART_POST';

            $fedex->carrierServices()->firstOrCreate(
                ['service_code' => $service['service_code']],
                [
                    'name' => $service['name'],
                    'can_ship_to_po_boxes' => $isGroundEconomy,
                    'can_ship_to_military_addresses' => $isGroundEconomy,
                ],
            );
        }

        $ups = Carrier::seedSystem(Carrier::UPS);
        foreach ([
            ['name' => 'UPS Ground', 'service_code' => '03'],
            ['name' => 'UPS 3 Day Select', 'service_code' => '12'],
            ['name' => 'UPS 2nd Day Air', 'service_code' => '02'],
            ['name' => 'UPS 2nd Day Air A.M.', 'service_code' => '59'],
            ['name' => 'UPS Next Day Air Saver', 'service_code' => '13'],
            ['name' => 'UPS Next Day Air', 'service_code' => '01'],
            ['name' => 'UPS Next Day Air Early', 'service_code' => '14'],
            ['name' => 'UPS Worldwide Express', 'service_code' => '07'],
            ['name' => 'UPS Worldwide Expedited', 'service_code' => '08'],
            ['name' => 'UPS Worldwide Saver', 'service_code' => '65'],
            ['name' => 'UPS Standard', 'service_code' => '11'],
            ['name' => 'UPS Ground Saver', 'service_code' => '92'],
            ['name' => 'UPS Ground Saver', 'service_code' => '93'],
        ] as $service) {
            // UPS Ground Saver (what UPS's API still calls SurePost) is UPS's
            // only USPS-last-mile service -- like FedEx Ground Economy above,
            // it can reach a PO Box or military address; plain UPS Ground/Air
            // services cannot. UPS splits it into two codes by weight -- 92
            // under 1lb, 93 at 1lb or greater -- and its Shop-rating response
            // returns whichever applies to the request, so both must be
            // seeded or one weight tier's rates get silently filtered out.
            $isGroundSaver = in_array($service['service_code'], ['92', '93'], true);

            $ups->carrierServices()->firstOrCreate(
                ['service_code' => $service['service_code']],
                [
                    'name' => $service['name'],
                    'can_ship_to_po_boxes' => $isGroundSaver,
                    'can_ship_to_military_addresses' => $isGroundSaver,
                ],
            );
        }

        // DHL Express, with no integration of ours: Shopify sells its Express
        // Worldwide, and the service needs a row to be sold as. Named DHL
        // Express rather than DHL, because DHL eCommerce is a different network
        // with different pickups and would need its own row. `P` is DHL's own
        // product code, so a direct integration later needs no remapping.
        // Shopify's *Preferred services* lists nothing else for DHL
        // (`shopify-shipping-carrier/02`), so nothing else is seeded.
        $dhlExpress = Carrier::seedSystem(Carrier::DHL_EXPRESS);
        $dhlExpress->carrierServices()->firstOrCreate(
            ['service_code' => 'P'],
            [
                'name' => 'DHL Express Worldwide',
                'can_ship_to_po_boxes' => false,
                'can_ship_to_military_addresses' => false,
            ],
        );

        // This hook asks Amazon for Buy Shipping against the seller's own Amazon
        // order (`channelType: AMAZON`), and for Amazon Shipping on an order from
        // another channel (`EXTERNAL`) where a connection is scoped to sell it.
        // Unlike every carrier above, this catalog is *discovered*: one
        // `getRates` came back naming 108 services across fifteen carriers, and
        // nothing may create a `CarrierService` from that (ADR-0003 decision
        // 2). So exactly one row is seeded, and it is not a service — it is the
        // hook a shipping method needs in order to ask Amazon at all. What
        // comes back is priced per offer under whichever carrier is carrying
        // it, and is named through Map Carrier Services if anyone wants it
        // named.
        //
        // No pickup cutoff: the carrier of record differs per offer and its own
        // row carries the cutoff, which is the point of normalizing the carrier
        // separately from the postage source.
        $amazon = Carrier::seedSystem(AmazonBuyShippingAdapter::SOURCE_NAME);
        $amazon->carrierServices()->firstOrCreate(
            ['service_code' => AmazonBuyShippingAdapter::CATALOG_SERVICE_CODE],
            [
                'name' => 'Amazon Buy Shipping rates',
                // Amazon resells USPS, which reaches both; whether any given
                // parcel is eligible is settled per order by `getRates`.
                'can_ship_to_po_boxes' => true,
                'can_ship_to_military_addresses' => true,
            ],
        );
    }
}
