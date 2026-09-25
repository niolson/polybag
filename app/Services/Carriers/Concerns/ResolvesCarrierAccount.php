<?php

namespace App\Services\Carriers\Concerns;

use App\DataTransferObjects\Shipping\RateRequest;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\ShippingOffer;

/**
 * Resolves the carrier account for a shipment and reports configuration status.
 *
 * The carrier row is looked up by getCarrierName() (declared on
 * CarrierAdapterInterface), so each adapter's declared name stays the single
 * source of truth for both resolution and the DB lookup.
 */
trait ResolvesCarrierAccount
{
    abstract public function getCarrierName(): string;

    private function resolveAccount(?int $locationId, ?int $clientId = null): ?CarrierAccount
    {
        $carrierId = Carrier::where('name', $this->getCarrierName())->value('id');

        return $carrierId
            ? CarrierAccount::resolveForShipment($carrierId, $locationId, $clientId)->first()
            : null;
    }

    /**
     * The account to rate this request on.
     *
     * Rate shopping hands over the account `PostageSourceResolver` resolved,
     * so the source it asked is the account that quotes (ADR-0006 decision 4).
     * A request built anywhere else carries none, and is resolved here the way
     * it always was.
     */
    private function ratingAccount(RateRequest $request): ?CarrierAccount
    {
        return $request->carrierAccount ?? $this->resolveAccount($request->locationId, $request->clientId);
    }

    /**
     * Whether the account an offer was bought on can no longer answer for it.
     *
     * Recovery must ask the account that made the purchase, not whichever
     * account scopes now prefer: a different CRID or shipper number answers
     * "no such label" truthfully about itself, and that would be read as
     * "nothing was bought" while the original account owns a label. So an
     * offer that recorded an account is asked on that account, and left
     * unresolved when the row is gone or its billing identity has changed
     * since the quote — the same digest the purchase path compares.
     */
    private function purchasingAccountChanged(ShippingOffer $offer): bool
    {
        if ($offer->carrier_account_id === null) {
            // Deleting the account nulls the id (the FK is nullOnDelete) but
            // leaves the fingerprint, so a fingerprint with no id is a gone
            // account; neither is an offer that never recorded one.
            return $offer->carrier_account_fingerprint !== null;
        }

        $account = CarrierAccount::query()->find($offer->carrier_account_id);

        return $account === null
            || ($offer->carrier_account_fingerprint !== null && $account->fingerprint() !== $offer->carrier_account_fingerprint);
    }

    /**
     * The account an offer was bought on, for asking what became of it.
     *
     * Only after {@see purchasingAccountChanged()} said no. An offer that
     * recorded no account (issued before rates carried one) falls back to the
     * resolution the purchase used.
     */
    private function purchasingAccount(ShippingOffer $offer, ?int $locationId, ?int $clientId = null): ?CarrierAccount
    {
        return $offer->carrier_account_id === null
            ? $this->resolveAccount($locationId, $clientId)
            : CarrierAccount::query()->find($offer->carrier_account_id);
    }

    public function isConfigured(): bool
    {
        $carrierId = Carrier::where('name', $this->getCarrierName())->value('id');

        return $carrierId !== null
            && CarrierAccount::active()
                ->where('carrier_id', $carrierId)
                ->with('carrier')
                ->get()
                ->contains(fn (CarrierAccount $account): bool => $account->hasUsableCredentials());
    }
}
