<?php

namespace Database\Seeders;

use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Models\BoxSize;

/**
 * The carrier packaging catalog — `box-size-seed-data/03`.
 *
 * Every fixed-size {@see CarrierPackaging} case is seeded inactive at the
 * carrier's published dimensions, so a warehouse activates the packaging it
 * stocks instead of typing dimensions in. Rows are stored length ≥ width ≥
 * height. Packaging a carrier makes in more than one shape gets a row per
 * shape under one carrier identity, and the label tells the packer which one
 * is in hand.
 *
 * Once only, as part of the reference-data sync: a row an Admin deletes stays
 * deleted. A code that already exists is left alone. A case added to the enum
 * later needs a new batch name to reach databases this batch already seeded.
 *
 * Max weights are informational; nothing enforces them.
 */
class CarrierPackagingSeeder extends OnceOnlySeeder
{
    /**
     * Packaging with no fixed size: FedEx's and UPS's generic branded box codes
     * name any box of theirs, so there are no dimensions to seed.
     *
     * @var list<CarrierPackaging>
     */
    public const UNSIZED = [
        CarrierPackaging::FedexBox,
        CarrierPackaging::UpsExpressBox,
    ];

    /**
     * @var list<array{code: string, label: string, type: BoxSizeType, carrier_packaging: CarrierPackaging, length: string, width: string, height: string, max_weight: string}>
     */
    public const PACKAGING = [
        // USPS Priority Mail and Priority Mail Express flat-rate packaging: published
        // outside dimensions, free from USPS, priced by the service to 70 lb.
        ['code' => 'USPS-01', 'label' => 'USPS Flat Rate Envelope', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::UspsFlatRateEnvelope, 'length' => '12.5', 'width' => '9.5', 'height' => '0.5', 'max_weight' => '70'],
        ['code' => 'USPS-02', 'label' => 'USPS Legal Flat Rate Envelope', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::UspsLegalFlatRateEnvelope, 'length' => '15', 'width' => '9.5', 'height' => '0.5', 'max_weight' => '70'],
        ['code' => 'USPS-03', 'label' => 'USPS Padded Flat Rate Envelope', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::UspsPaddedFlatRateEnvelope, 'length' => '12.5', 'width' => '9.5', 'height' => '0.5', 'max_weight' => '70'],
        ['code' => 'USPS-04', 'label' => 'USPS Small Flat Rate Box', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::UspsSmallFlatRateBox, 'length' => '8.69', 'width' => '5.44', 'height' => '1.75', 'max_weight' => '70'],
        ['code' => 'USPS-05', 'label' => 'USPS Medium Flat Rate Box (top-loading)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::UspsMediumFlatRateBox, 'length' => '11.25', 'width' => '8.75', 'height' => '6', 'max_weight' => '70'],
        ['code' => 'USPS-06', 'label' => 'USPS Medium Flat Rate Box (side-loading)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::UspsMediumFlatRateBox, 'length' => '14.13', 'width' => '12', 'height' => '3.5', 'max_weight' => '70'],
        ['code' => 'USPS-07', 'label' => 'USPS Large Flat Rate Box', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::UspsLargeFlatRateBox, 'length' => '12.25', 'width' => '12', 'height' => '6', 'max_weight' => '70'],
        ['code' => 'USPS-08', 'label' => 'USPS Priority Mail Express Flat Rate Envelope', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::UspsExpressFlatRateEnvelope, 'length' => '12.5', 'width' => '9.5', 'height' => '0.5', 'max_weight' => '70'],
        ['code' => 'USPS-09', 'label' => 'USPS Priority Mail Express Legal Flat Rate Envelope', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::UspsExpressLegalFlatRateEnvelope, 'length' => '15', 'width' => '9.5', 'height' => '0.5', 'max_weight' => '70'],
        ['code' => 'USPS-10', 'label' => 'USPS Priority Mail Express Padded Flat Rate Envelope', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::UspsExpressPaddedFlatRateEnvelope, 'length' => '12.5', 'width' => '9.5', 'height' => '0.5', 'max_weight' => '70'],

        // FedEx packaging at the dimensions FedEx publishes for One Rate: 10 lb for
        // envelopes and paks, 50 lb for boxes and the tube.
        ['code' => 'FEDEX-01', 'label' => 'FedEx Envelope', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::FedexEnvelope, 'length' => '12.5', 'width' => '9.5', 'height' => '0.5', 'max_weight' => '10'],
        ['code' => 'FEDEX-02', 'label' => 'FedEx Padded Pak', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::FedexPak, 'length' => '14.75', 'width' => '11.75', 'height' => '1', 'max_weight' => '10'],
        ['code' => 'FEDEX-03', 'label' => 'FedEx Small Box (flat)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::FedexSmallBox, 'length' => '12.38', 'width' => '10.88', 'height' => '1.5', 'max_weight' => '50'],
        ['code' => 'FEDEX-04', 'label' => 'FedEx Small Box (deep)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::FedexSmallBox, 'length' => '11.25', 'width' => '8.75', 'height' => '2.63', 'max_weight' => '50'],
        ['code' => 'FEDEX-05', 'label' => 'FedEx Medium Box (flat)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::FedexMediumBox, 'length' => '13.25', 'width' => '11.5', 'height' => '2.38', 'max_weight' => '50'],
        ['code' => 'FEDEX-06', 'label' => 'FedEx Medium Box (deep)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::FedexMediumBox, 'length' => '11.25', 'width' => '8.75', 'height' => '4.38', 'max_weight' => '50'],
        ['code' => 'FEDEX-07', 'label' => 'FedEx Envelope (reusable)', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::FedexEnvelope, 'length' => '15.5', 'width' => '9.5', 'height' => '0.5', 'max_weight' => '10'],
        ['code' => 'FEDEX-08', 'label' => 'FedEx Envelope (padded)', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::FedexEnvelope, 'length' => '11.5', 'width' => '9.75', 'height' => '0.5', 'max_weight' => '10'],
        ['code' => 'FEDEX-09', 'label' => 'FedEx Pak (small)', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::FedexPak, 'length' => '12.75', 'width' => '10.25', 'height' => '1', 'max_weight' => '10'],
        ['code' => 'FEDEX-10', 'label' => 'FedEx Pak (large)', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::FedexPak, 'length' => '15.5', 'width' => '12', 'height' => '1', 'max_weight' => '10'],
        ['code' => 'FEDEX-11', 'label' => 'FedEx Pak (reusable)', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::FedexPak, 'length' => '14.5', 'width' => '10', 'height' => '1', 'max_weight' => '10'],
        ['code' => 'FEDEX-12', 'label' => 'FedEx Extra Small Box', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::FedexExtraSmallBox, 'length' => '7.25', 'width' => '4.69', 'height' => '2.5', 'max_weight' => '50'],
        ['code' => 'FEDEX-13', 'label' => 'FedEx Large Box (flat)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::FedexLargeBox, 'length' => '17.5', 'width' => '12.38', 'height' => '3', 'max_weight' => '50'],
        ['code' => 'FEDEX-14', 'label' => 'FedEx Large Box (deep)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::FedexLargeBox, 'length' => '11.25', 'width' => '8.75', 'height' => '7.75', 'max_weight' => '50'],
        ['code' => 'FEDEX-15', 'label' => 'FedEx Extra Large Box (cube)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::FedexExtraLargeBox, 'length' => '11.88', 'width' => '11', 'height' => '10.75', 'max_weight' => '50'],
        ['code' => 'FEDEX-16', 'label' => 'FedEx Extra Large Box (flat)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::FedexExtraLargeBox, 'length' => '15.75', 'width' => '14.13', 'height' => '6', 'max_weight' => '50'],
        ['code' => 'FEDEX-17', 'label' => 'FedEx Tube', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::FedexTube, 'length' => '38', 'width' => '6', 'height' => '6', 'max_weight' => '50'],
        // International only, at FedEx's published inside dimensions and their names' limits.
        ['code' => 'FEDEX-18', 'label' => 'FedEx 10kg Box', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::Fedex10kgBox, 'length' => '15.81', 'width' => '12.94', 'height' => '10.19', 'max_weight' => '22'],
        ['code' => 'FEDEX-19', 'label' => 'FedEx 25kg Box', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::Fedex25kgBox, 'length' => '21.56', 'width' => '16.56', 'height' => '13.19', 'max_weight' => '55'],

        // UPS packaging. UPS publishes 30 lb for its Express Box; the same limit is
        // used for its other packaging.
        ['code' => 'UPS-01', 'label' => 'UPS Letter', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::UpsLetter, 'length' => '12.5', 'width' => '9.5', 'height' => '0.5', 'max_weight' => '30'],
        ['code' => 'UPS-02', 'label' => 'UPS Pak', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::UpsPak, 'length' => '16', 'width' => '12.75', 'height' => '1', 'max_weight' => '30'],
        ['code' => 'UPS-03', 'label' => 'UPS Tube', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::UpsTube, 'length' => '38', 'width' => '6', 'height' => '6', 'max_weight' => '30'],
        ['code' => 'UPS-04', 'label' => 'UPS Express Box (Small)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::UpsExpressBoxSmall, 'length' => '13', 'width' => '11', 'height' => '2', 'max_weight' => '30'],
        ['code' => 'UPS-05', 'label' => 'UPS Express Box (Medium)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::UpsExpressBoxMedium, 'length' => '15', 'width' => '11', 'height' => '3', 'max_weight' => '30'],
        ['code' => 'UPS-06', 'label' => 'UPS Express Box (Large)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::UpsExpressBoxLarge, 'length' => '18', 'width' => '13', 'height' => '3', 'max_weight' => '30'],
    ];

    protected function batch(): string
    {
        return 'carrier-packaging-v1';
    }

    protected function seed(): void
    {
        foreach (self::PACKAGING as $box) {
            BoxSize::firstOrCreate(
                ['code' => $box['code']],
                [
                    ...$box,
                    'active' => false,
                    'empty_weight' => '0',
                    // USPS supplies its packaging free; FedEx and UPS supply theirs free to account holders.
                    'materials_cost' => '0',
                ],
            );
        }
    }
}
