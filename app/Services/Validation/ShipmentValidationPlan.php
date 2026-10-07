<?php

namespace App\Services\Validation;

use App\Contracts\AddressValidationInterface;
use App\Contracts\AddressValidationPlan;
use App\Enums\PostageSourceKind;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Shipment;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which validators check a Shipment's address, and in what order — the only
 * place the routing rules live (`address-validation-routing/05`).
 *
 * A carrier's free validator may be used only for a Shipment tendered to that
 * carrier, so FedEx is tried only where the Shipment's shipping method could
 * buy its Label and the Client has an account with it. Where it is, the
 * country decides its place (`address-validation-routing/08`): first in the
 * US, Puerto Rico and the countries it is trusted in, last where Google can't
 * answer, and never where it accepts wrong house numbers. USPS and then
 * Google are the fallback. A validator that doesn't support the country is
 * left out; AddressValidationCountries holds the lists.
 */
class ShipmentValidationPlan implements AddressValidationPlan
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return list<AddressValidationInterface>
     */
    public function validatorsFor(Shipment $shipment): array
    {
        if (config('app.fake_carriers') || SettingsService::isDemoMode()) {
            return [new FakeAddressValidator];
        }

        if ($this->settings->get('sandbox_mode', false) && ! $this->settings->get('address_validation_use_real_in_sandbox', false)) {
            return [new FakeAddressValidator];
        }

        $country = $shipment->country ?? 'US';
        $fedex = AddressValidationCountries::fedexSupports($country) && $this->fedexMayValidate($shipment);

        $validators = [];

        if ($fedex && AddressValidationCountries::fedexFirst($country)) {
            $validators[] = new FedexAddressValidator;
        }

        $validators[] = new UspsAddressValidator;

        if ($this->settings->get('address_validation_google_enabled', false)) {
            $validators[] = new GoogleAddressValidator;
        }

        if ($fedex && AddressValidationCountries::fedexLastResort($country)) {
            $validators[] = new FedexAddressValidator;
        }

        return array_values(array_filter(
            $validators,
            fn (AddressValidationInterface $validator): bool => $validator->supports($country),
        ));
    }

    /**
     * Our reading of the FedEx agreement: its validator is for Shipments
     * tendered to FedEx. That is one whose method could buy a direct FedEx
     * Label as the postage resolver would (direct allowed, an active service
     * of an active FedEx carrier listed), or
     * allows Amazon Buy Shipping, which may sell a FedEx Label (decided
     * 2026-10-01) — and whose Client has an active FedEx account to ask with.
     * A Shipment with no method can buy nothing, so it never qualifies.
     */
    public function fedexMayValidate(Shipment $shipment): bool
    {
        $method = $shipment->shippingMethod;

        if ($method === null) {
            return false;
        }

        $tenderedToFedex = $method->allowsSource(PostageSourceKind::Amazon)
            || ($method->allowsSource(PostageSourceKind::Direct)
                && $method->carrierServices()
                    ->active()
                    ->withActiveCarrier()
                    ->whereHas('carrier', fn (Builder $q) => $q->where('name', Carrier::FEDEX))
                    ->exists());

        if (! $tenderedToFedex) {
            return false;
        }

        $carrierId = Carrier::where('name', Carrier::FEDEX)->value('id');

        return $carrierId !== null
            && CarrierAccount::resolveForAddressValidation($carrierId, $shipment) !== null;
    }
}
