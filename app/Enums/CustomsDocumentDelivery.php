<?php

namespace App\Enums;

use App\DataTransferObjects\Shipping\AddressData;

/**
 * What a purchase hands back for the customs declaration a lane carries, as
 * answered by the source that will buy the label.
 *
 * The per-carrier capability behind the report printer gate
 * (`shopify-shipping-carrier/07` constraint 3, the rows filled in by `23`).
 * The obvious predicate — {@see AddressData::requiresCustomsDeclaration()}
 * — answers "does this shipment carry a declaration?", and the gate needs
 * "does this purchase return a second document that has to go on paper?".
 * The first is a superset of the second: USPS folds the CP72 into the label
 * and prints it on the thermal path, so gating on the address alone would
 * refuse USPS international on every workstation without a report printer,
 * for a document that needs none.
 *
 * Not a boolean, because "nothing comes back" has two different reasons and
 * only one of them is stable.
 */
enum CustomsDocumentDelivery
{
    /**
     * A document beside the label — a commercial invoice on Letter paper — that
     * `printReport()` sends to the report printer. UPS (`ShipmentResults.Form`),
     * Shopify (`CUSTOMS_FORM`) and Amazon (`CUSTOM_FORM`, when the offering
     * declares it) all return one of these.
     */
    case Separate;

    /**
     * The declaration is part of the label itself and prints wherever the
     * label prints. USPS's CP72 is three 4×6 plies inside one label document.
     */
    case FusedIntoLabel;

    /**
     * The carrier could return a document but the request never asks for one.
     * FedEx today: no `shippingDocumentSpecification`, no ETD, so the invoice
     * FedEx lists as required is never generated and the parcel leaves with
     * the air waybill only. Stale the day either is wired up — which is why
     * this is its own case and not {@see self::FusedIntoLabel}.
     */
    case NotRequested;

    /**
     * The lane carries no declaration, or the source produces none for it —
     * a domestic label, or Amazon's plain-domestic quote for a territory.
     */
    case None;

    /**
     * Whether buying this needs a report printer on the workstation.
     */
    public function needsReportPrinter(): bool
    {
        return $this === self::Separate;
    }
}
