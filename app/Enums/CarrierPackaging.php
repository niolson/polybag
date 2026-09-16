<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Packaging a carrier supplies and prices specifically — ADR-0005 decision 1.
 *
 * The second axis of a Box Size, independent of its physical form
 * ({@see BoxSizeType}): a USPS padded flat-rate envelope is a padded mailer
 * *and* `UspsPaddedFlatRateEnvelope`. Null, wherever this enum is nullable,
 * means the packer's own packaging of whatever form the type says.
 *
 * Closed by design so that every adapter mapping is exhaustive under PHPStan;
 * a customer stocking something not listed here is a code change, which is
 * worth more than an operator typing a name no adapter understands.
 *
 * Priority Mail Express flat-rate envelopes are separate cases from the
 * Priority Mail ones: USPS sells them as separate stock and a packer holding
 * one uses the service printed on it, so `exactly(UspsFlatRateEnvelope)` must
 * not accept an Express envelope.
 */
enum CarrierPackaging: string implements HasLabel
{
    case UspsFlatRateEnvelope = 'usps_flat_rate_envelope';
    case UspsLegalFlatRateEnvelope = 'usps_legal_flat_rate_envelope';
    case UspsPaddedFlatRateEnvelope = 'usps_padded_flat_rate_envelope';
    case UspsSmallFlatRateBox = 'usps_small_flat_rate_box';
    case UspsMediumFlatRateBox = 'usps_medium_flat_rate_box';
    case UspsLargeFlatRateBox = 'usps_large_flat_rate_box';
    case UspsExpressFlatRateEnvelope = 'usps_express_flat_rate_envelope';
    case UspsExpressLegalFlatRateEnvelope = 'usps_express_legal_flat_rate_envelope';
    case UspsExpressPaddedFlatRateEnvelope = 'usps_express_padded_flat_rate_envelope';

    case FedexEnvelope = 'fedex_envelope';
    case FedexPak = 'fedex_pak';
    case FedexTube = 'fedex_tube';
    case FedexBox = 'fedex_box';
    case FedexExtraSmallBox = 'fedex_extra_small_box';
    case FedexSmallBox = 'fedex_small_box';
    case FedexMediumBox = 'fedex_medium_box';
    case FedexLargeBox = 'fedex_large_box';
    case FedexExtraLargeBox = 'fedex_extra_large_box';
    case Fedex10kgBox = 'fedex_10kg_box';
    case Fedex25kgBox = 'fedex_25kg_box';

    case UpsLetter = 'ups_letter';
    case UpsPak = 'ups_pak';
    case UpsTube = 'ups_tube';
    case UpsExpressBox = 'ups_express_box';
    case UpsExpressBoxSmall = 'ups_express_box_small';
    case UpsExpressBoxMedium = 'ups_express_box_medium';
    case UpsExpressBoxLarge = 'ups_express_box_large';

    public function getLabel(): string
    {
        return match ($this) {
            self::UspsFlatRateEnvelope => 'USPS Flat Rate Envelope',
            self::UspsLegalFlatRateEnvelope => 'USPS Legal Flat Rate Envelope',
            self::UspsPaddedFlatRateEnvelope => 'USPS Padded Flat Rate Envelope',
            self::UspsSmallFlatRateBox => 'USPS Small Flat Rate Box',
            self::UspsMediumFlatRateBox => 'USPS Medium Flat Rate Box',
            self::UspsLargeFlatRateBox => 'USPS Large Flat Rate Box',
            self::UspsExpressFlatRateEnvelope => 'USPS Priority Mail Express Flat Rate Envelope',
            self::UspsExpressLegalFlatRateEnvelope => 'USPS Priority Mail Express Legal Flat Rate Envelope',
            self::UspsExpressPaddedFlatRateEnvelope => 'USPS Priority Mail Express Padded Flat Rate Envelope',
            self::FedexEnvelope => 'FedEx Envelope',
            self::FedexPak => 'FedEx Pak',
            self::FedexTube => 'FedEx Tube',
            self::FedexBox => 'FedEx Box',
            self::FedexExtraSmallBox => 'FedEx Extra Small Box',
            self::FedexSmallBox => 'FedEx Small Box',
            self::FedexMediumBox => 'FedEx Medium Box',
            self::FedexLargeBox => 'FedEx Large Box',
            self::FedexExtraLargeBox => 'FedEx Extra Large Box',
            self::Fedex10kgBox => 'FedEx 10kg Box',
            self::Fedex25kgBox => 'FedEx 25kg Box',
            self::UpsLetter => 'UPS Letter',
            self::UpsPak => 'UPS Pak',
            self::UpsTube => 'UPS Tube',
            self::UpsExpressBox => 'UPS Express Box',
            self::UpsExpressBoxSmall => 'UPS Express Box (Small)',
            self::UpsExpressBoxMedium => 'UPS Express Box (Medium)',
            self::UpsExpressBoxLarge => 'UPS Express Box (Large)',
        };
    }

    /**
     * Whose packaging this is, as the carrier is named on `carriers.name`.
     */
    public function carrier(): string
    {
        return match ($this) {
            self::UspsFlatRateEnvelope,
            self::UspsLegalFlatRateEnvelope,
            self::UspsPaddedFlatRateEnvelope,
            self::UspsSmallFlatRateBox,
            self::UspsMediumFlatRateBox,
            self::UspsLargeFlatRateBox,
            self::UspsExpressFlatRateEnvelope,
            self::UspsExpressLegalFlatRateEnvelope,
            self::UspsExpressPaddedFlatRateEnvelope => 'USPS',
            self::FedexEnvelope,
            self::FedexPak,
            self::FedexTube,
            self::FedexBox,
            self::FedexExtraSmallBox,
            self::FedexSmallBox,
            self::FedexMediumBox,
            self::FedexLargeBox,
            self::FedexExtraLargeBox,
            self::Fedex10kgBox,
            self::Fedex25kgBox => 'FedEx',
            self::UpsLetter,
            self::UpsPak,
            self::UpsTube,
            self::UpsExpressBox,
            self::UpsExpressBoxSmall,
            self::UpsExpressBoxMedium,
            self::UpsExpressBoxLarge => 'UPS',
        };
    }

    /**
     * Select options grouped by carrier, for the Box Size forms.
     *
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->carrier()][$case->value] = $case->getLabel();
        }

        return $options;
    }
}
