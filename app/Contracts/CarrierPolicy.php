<?php

namespace App\Contracts;

use App\Enums\ServiceCapability;

/**
 * What a physical carrier answers about itself.
 *
 * One of the two halves `CarrierAdapterInterface` used to bundle (ADR-0002
 * decision 7). Everything here is a fact about USPS, FedEx or UPS that holds
 * whoever bought the label: what the carrier will and will not carry, how much
 * value it will insure, whether it runs a manifest program at all.
 *
 * It is deliberately *not* where voiding, tracking or per-package manifest
 * eligibility live — those follow the postage source, and are declared on
 * {@see PostageSourceOperations}. A resale channel like Shopify Shipping
 * implements none of this, because it is not a carrier and has no policy of its
 * own to report; it only relays whatever the carrier it picked happens to do.
 */
interface CarrierPolicy
{
    /**
     * The carrier's name (e.g. 'USPS', 'FedEx', 'UPS') — the carrier of record,
     * never a marketplace or storefront.
     */
    public function getCarrierName(): string;

    /**
     * This carrier's capability for a special service code, carrier-wide.
     *
     * - Supported: the adapter translates it into carrier API fields
     * - Prohibited: carrier policy or legal restriction
     * - NotImplemented: not coded yet; the service is silently skipped
     *
     * Whether a *particular offer* can honor the code is a separate question —
     * see {@see CarrierAdapterInterface::offerCapability()}.
     */
    public function serviceCapability(string $serviceCode): ServiceCapability;

    /**
     * Maximum declared value this carrier accepts per package, or null when
     * unlimited/not applicable.
     */
    public function declaredValueCap(): ?float;

    /**
     * Whether this carrier supports multi-package shipments.
     */
    public function supportsMultiPackage(): bool;

    /**
     * Whether this carrier runs an end-of-day manifest (SCAN form) program at
     * all — the question `EndOfDay` asks once per carrier row to decide whether
     * to offer a manifest button.
     *
     * Not to be confused with {@see PostageSourceOperations::supportsPackageManifest()},
     * which asks whether one specific package may go on a manifest *we* create.
     * USPS answers yes here and still says no there for a Shopify-bought parcel.
     */
    public function supportsCarrierManifest(): bool;

    /**
     * Whether this carrier exposes a tracking API at all.
     *
     * Being able to call it for a given parcel is a separate matter: entitlement
     * follows whoever bought the label, which is why tracking dispatches through
     * {@see PostageSourceOperations::trackShipment()}.
     */
    public function supportsTracking(): bool;
}
