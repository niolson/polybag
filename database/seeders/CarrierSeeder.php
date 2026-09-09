<?php

namespace Database\Seeders;

use App\Models\Carrier;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\ShopifyAdapter;
use Illuminate\Database\Seeder;

class CarrierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // The cutoff hangs off the carrier row rather than a name-keyed table in
        // code, so renaming a carrier cannot silently drop its pickup policy.
        $usps = Carrier::firstOrCreate(['name' => 'USPS'], ['pickup_cutoff_hour' => 20]);
        foreach ([
            ['name' => 'Ground Advantage', 'service_code' => 'USPS_GROUND_ADVANTAGE'],
            ['name' => 'Priority Mail', 'service_code' => 'PRIORITY_MAIL'],
            ['name' => 'Priority Mail Express', 'service_code' => 'PRIORITY_MAIL_EXPRESS'],
            ['name' => 'Priority Mail International', 'service_code' => 'PRIORITY_MAIL_INTERNATIONAL'],
        ] as $service) {
            // Every USPS service is USPS -- there's no scenario in this app
            // where a USPS service should be blocked from a PO Box or a
            // military address.
            $usps->carrierServices()->firstOrCreate(
                ['service_code' => $service['service_code']],
                [
                    'name' => $service['name'],
                    'can_ship_to_po_boxes' => true,
                    'can_ship_to_military_addresses' => true,
                ],
            );
        }

        $fedex = Carrier::firstOrCreate(['name' => 'FedEx']);
        foreach ([
            ['name' => 'FedEx Home Delivery®', 'service_code' => 'GROUND_HOME_DELIVERY'],
            ['name' => 'FedEx Ground®', 'service_code' => 'FEDEX_GROUND'],
            ['name' => 'FedEx Ground® Economy', 'service_code' => 'SMART_POST'],
            ['name' => 'FedEx International Priority®', 'service_code' => 'FEDEX_INTERNATIONAL_PRIORITY'],
            ['name' => 'FedEx International Priority® Express', 'service_code' => 'FEDEX_INTERNATIONAL_PRIORITY_EXPRESS'],
            ['name' => 'FedEx International First®', 'service_code' => 'INTERNATIONAL_FIRST'],
            ['name' => 'FedEx International Economy®', 'service_code' => 'FEDEX_INTERNATIONAL_ECONOMY'],
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

        $ups = Carrier::firstOrCreate(['name' => 'UPS']);
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

        // Shopify Shipping buys postage on the merchant's Shopify account
        // instead of a carrier account of ours, which is how a shop without an
        // NSA reaches USPS Connect eCommerce rates. Its service codes are the
        // `carrier:service` pairs Shopify's preferredRateSelection takes, or
        // `auto` to let Shopify choose the rate the way its admin would.
        //
        // Shopify publishes no list of service codes and has no API to
        // enumerate them, so the pairs below were established by probe rather
        // than from documentation -- see the issue file for the method and the
        // caveats. There is no single vocabulary here: each carrier keeps its
        // own, and Shopify passes it through.
        //
        //   USPS  a PascalCase of Shopify's own -- `GroundAdvantage`, not the
        //         `USPS_GROUND_ADVANTAGE` the USPS block above uses
        //   UPS   UPS's own numeric codes, the same alphabet as the UPS block
        //   DHL   DHL's own single-letter product codes, where `P` is Express
        //         Worldwide
        //
        // All are matched case-sensitively: `priority` finds no rate where
        // `Priority` does.
        //
        // The cutoff is 8 PM, matching USPS. Shopify does not reveal which carrier it picked
        // until after purchase, and `shippingDatetime` goes out *in* the purchase
        // mutation, so no carrier-derived cutoff can apply — see ADR-0002. The
        // cutoff lives on the row like every other carrier's rather than as a
        // special case in ShipDateService.
        $shopify = Carrier::firstOrCreate(
            ['name' => ShopifyAdapter::CARRIER_NAME],
            ['pickup_cutoff_hour' => 20],
        );
        $shopify->carrierServices()->firstOrCreate(
            ['service_code' => ShopifyAdapter::AUTO_SERVICE_CODE],
            [
                'name' => "Shopify's choice",
                // Shopify picks the carrier itself, and only USPS reaches a PO
                // Box or an APO/FPO, so its choice for those destinations is
                // constrained the same way ours would be.
                'can_ship_to_po_boxes' => true,
                'can_ship_to_military_addresses' => true,
            ],
        );

        foreach ([
            ['name' => "Shopify's USPS Ground Advantage", 'service_code' => 'usps:GroundAdvantage'],
            ['name' => "Shopify's USPS Priority Mail", 'service_code' => 'usps:Priority'],
            ['name' => "Shopify's USPS Priority Mail Express", 'service_code' => 'usps:PriorityExpress'],
            ['name' => "Shopify's USPS Media Mail", 'service_code' => 'usps:MediaMail'],
            ['name' => "Shopify's UPS Ground", 'service_code' => 'ups_shipping:03'],
            ['name' => "Shopify's UPS 3 Day Select", 'service_code' => 'ups_shipping:12'],
            ['name' => "Shopify's UPS 2nd Day Air", 'service_code' => 'ups_shipping:02'],
            ['name' => "Shopify's UPS 2nd Day Air A.M.", 'service_code' => 'ups_shipping:59'],
            ['name' => "Shopify's UPS Next Day Air Saver", 'service_code' => 'ups_shipping:13'],
            ['name' => "Shopify's UPS Next Day Air", 'service_code' => 'ups_shipping:01'],
            // Named apart, unlike the UPS block's pair. There the two rows only
            // ever filter a rate response that already returned exactly one of
            // them, so a packer never sees both; here they are advertised from
            // the catalog and would otherwise be two identical lines on screen.
            // The admin's own wording is the model.
            ['name' => "Shopify's UPS Ground Saver (under 1 lb)", 'service_code' => 'ups_shipping:92'],
            ['name' => "Shopify's UPS Ground Saver (1 lb and over)", 'service_code' => 'ups_shipping:93'],
            ['name' => "Shopify's UPS Worldwide Express", 'service_code' => 'ups_shipping:07'],
            ['name' => "Shopify's UPS Worldwide Expedited", 'service_code' => 'ups_shipping:08'],
            ['name' => "Shopify's UPS Worldwide Saver", 'service_code' => 'ups_shipping:65'],
            ['name' => "Shopify's UPS Standard", 'service_code' => 'ups_shipping:11'],
            ['name' => "Shopify's DHL Express Worldwide", 'service_code' => 'dhl_express:P'],
        ] as $service) {
            // Same USPS-last-mile rule as the UPS block above, and the same
            // 92/93 split by weight -- which Shopify reproduces exactly: a
            // 0.3lb parcel finds a rate for 92 and none for 93, a 5lb parcel
            // the reverse. That is worth stating because it is also the reason
            // "no rate" cannot be read as "no such service": availability is
            // per shipment, and a code is only ever disproved for the parcel it
            // was probed with.
            $isGroundSaver = in_array($service['service_code'], ['ups_shipping:92', 'ups_shipping:93'], true);
            $isUsps = str_starts_with($service['service_code'], 'usps:');

            $shopify->carrierServices()->firstOrCreate(
                ['service_code' => $service['service_code']],
                [
                    'name' => $service['name'],
                    'can_ship_to_po_boxes' => $isUsps || $isGroundSaver,
                    'can_ship_to_military_addresses' => $isUsps || $isGroundSaver,
                ],
            );
        }

        // Amazon Buy Shipping buys postage against the seller's own Amazon
        // order. Unlike every carrier above, its catalog is *discovered*: one
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
        $amazon = Carrier::firstOrCreate(['name' => AmazonBuyShippingAdapter::SOURCE_NAME]);
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
