# PolyBag

PolyBag is a shipping workstation for preparing Packages, buying labels, printing labels, and tracking fulfillment work.

## Language

**Shipment**:
An order-like shipping request containing recipient details and items that need fulfillment.

**Package**:
A physical parcel prepared from a Shipment and measured before label purchase.

**Package Draft**:
An unshipped Package that was prepared but has not yet completed label purchase.
_Avoid_: Orphan package, temporary package

**Carrier of record**:
The physical carrier that will actually move the parcel. Free text deliberately — it may
name a carrier we hold no `Carrier` row for and never will.
_Avoid_: Shipping provider, shipper

**Postage source**:
Where the label was bought: a `CarrierAccount` (bought directly) or a `DataSource`
(sales-channel postage). Not the Shipment's import source, which may be a different thing
entirely.
_Avoid_: Carrier, channel

**Service class**:
What a `ShippingMethod` is — a speed/price tier that several concrete carrier services can
satisfy. "Ground" is a service class; `USPS_GROUND_ADVANTAGE` is one service that satisfies it.
_Avoid_: Service, shipping service

**Offer**:
One ephemeral, package-specific quote: price, promise, purchase token, expiry. Discarded once
it expires or is spent.

**Observed service**:
A durable service identity seen in a postage source's response, not part of the catalog. It
becomes a `CarrierService` only when a human authors one; discovery never creates one.
_Avoid_: Discovered service, carrier service

**Label**:
One purchased instance of *outbound* postage for a Package: its tracking number, cost, and
the carrier of record it was bought as. A Package has at most one active Label; a voided
Label stays as history. A return label is not a Label in this sense.
_Avoid_: Shipping label (ambiguous with the Shopify product), label data (the document,
which lives on the Package)

**Blind purchase**:
Buying postage where the price and service are not known until after the fact — and, for
Shopify, never. It is not something we can compare or rank.
_Avoid_: Rate, quote

## Relationships

- A **Shipment** can produce one or more **Packages**.
- A **Package Draft** belongs to exactly one **Shipment**.
- A **Package Draft** becomes a shipped **Package** when label purchase succeeds.
- For now, a **Shipment** should have at most one active **Package Draft** in the packing workflow.
- When a **Package Draft** exists, the packing workflow resumes from the draft as source of truth.
- `PackageCreated` means the **Package Draft** was first persisted as a Package row, not that it is ready for label purchase.
- A shipped **Package** has exactly one **postage source**, recorded explicitly rather than inferred from which pointer is set.
- A **carrier of record** and a **postage source** are independent: postage bought from one can move on a parcel carried by any carrier.
- When the postage source is Shopify, the **carrier of record** is not known until after purchase — so nothing that has to be decided before purchase can depend on it.
- A **Package** with no **postage source** recorded has not been shipped; there is no state for a shipped Package whose postage source is unknown.
- A **Package** has at most one active **Label**; it is shipped exactly when it has one. A voided **Label** stays, and the Package's shipping columns are the projection of the active one.
- A void marks the **Label** voided and returns the **Package** to unshipped; re-shipping buys a new **Label** for the same Package.
- A **service class** is satisfied by one or more concrete carrier services; a **blind purchase** satisfies none, because no service is offered.
- An **observed service** is normalized onto an existing `CarrierService`, or promoted by authoring one. Nothing promotes itself.
- A **blind purchase** is not an **Offer** — with no price it can never win a comparison, so it never enters one.

## Example dialogue

> **Dev:** "If the operator prepares a Package but leaves before buying the label, should we delete it?"
> **Domain expert:** "No — that is a Package Draft. Keep it so the operator can resume, edit, ship, or explicitly delete it."
>
> **Dev:** "If the operator scans the same Shipment again, do we create another draft?"
> **Domain expert:** "Not yet — resume the existing Package Draft for that Shipment."
>
> **Dev:** "When resuming, should the new scan state overwrite the draft?"
> **Domain expert:** "No — load the Package Draft first and continue from that source of truth."
>
> **Dev:** "Should PackageCreated fire when the draft is ready to ship?"
> **Domain expert:** "No — fire it when the Package row is created in the database."

> **Dev:** "We bought a label through Shopify and it came back USPS. What goes in the carrier column?"
> **Domain expert:** "USPS. That is the carrier of record — who is carrying the parcel. Shopify is the postage source, which is a separate thing we record separately."
>
> **Dev:** "So can it go on the USPS end-of-day manifest with the rest?"
> **Domain expert:** "No. Manifesting follows the postage source, not the carrier — we did not buy that label, so we cannot manifest it."
>
> **Dev:** "The Shopify option in the rate list has no price. What should we sort it as?"
> **Domain expert:** "Don't sort it at all. It is a blind purchase, not an offer — there is nothing to compare it against, and the price is unknown until after we have bought it."
>
> **Dev:** "Amazon quoted us OnTrac and we have no OnTrac in the catalog. Do we add it?"
> **Domain expert:** "Not automatically. It is an observed service, and a packer can still pick it. A carrier row only appears when someone decides to author one."

## Flagged ambiguities

- "orphan package" was used for an unshipped Package left by an interrupted workflow — resolved: this is a **Package Draft**, not something to silently delete.
- "carrier" was used for both who carries the parcel and who sold us the postage, and `packages.carrier` was written as `Shopify` — resolved: those are the **carrier of record** and the **postage source**, recorded separately. Shopify is never a carrier of record.
- "service" was used for a service class, an offer, an observed service, and the confirmed service on a bought label — resolved: those are four terms, and `packages.service` holds only the last of them.
