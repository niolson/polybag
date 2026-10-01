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

**Connection**:
The UI name for a `DataSource` record: one connected account (Shopify, Amazon, or an
external database) with its credentials, OAuth state, and Client assignment. A Connection
may import orders, sell postage, receive tracking, or several of these. Being *active*
means it may be used at all; *importing orders* is a separate switch, so an active
Connection can exist only to sell postage or receive tracking.
_Avoid_: Data source, import source (in UI copy — the code keeps `DataSource`)

**Amazon Buy Shipping / Amazon Shipping**:
Amazon Buy Shipping is the Shipping v2 API used to quote and buy postage. Amazon Shipping is
one physical carrier that API can return. For an on-Amazon order (`channelType: AMAZON`), the
API may return Amazon Shipping, USPS, UPS, FedEx, or another eligible carrier. For an
off-Amazon order (`channelType: EXTERNAL`), the purchasable carrier is Amazon Shipping.
PolyBag quotes both. Off-Amazon Amazon Shipping is a direct sale: a shipping method lists
Amazon Shipping Ground like any direct service, and the Amazon connection scoped to the
Package sells it, never the Shipment's import source.
_Avoid_: Using "Amazon" without saying whether it means the API/postage source or the carrier

**Service class**:
What a `ShippingMethod` is — a speed/price tier that several concrete carrier services can
satisfy. "Ground" is a service class; `USPS_GROUND_ADVANTAGE` is one service that satisfies it.
A method may list no services when a postage source may sell it any service, such as Amazon
Buy Shipping allowed *any service*.
_Avoid_: Service, shipping service

**Offer**:
One ephemeral, package-specific quote: price, promise, purchase token where the source issues
one, expiry. Every rate a packer can choose is one, quoted directly from a carrier account or
resold through a channel; the browser names an Offer and restates nothing. Discarded once it
expires or is spent.

**Observed service**:
A durable service identity seen in a postage source's response, not part of the catalog. It
becomes a `CarrierService` only when a human authors one; discovery never creates one.
_Avoid_: Discovered service, carrier service

**Allowance**:
What a shipping method lets automation buy: a postage source the method has a row for, and a
service the method lists, or any service where that source's row allows it. Amazon Buy
Shipping's row says *services on this method* (the default: an Amazon offer mapped to a listed
service) or *any service* (every Amazon offer, unmapped ones included). The connection's
**postage setting** and shipping rules narrow it, and a deactivated service or carrier is
never in it, whatever the row says; nothing widens it. It is the same in sandbox
and production. With no shipping method, it is nothing: a shipment needs a method before any
Label is bought, by a person or by automation. Human selection does not otherwise require it.
_Avoid_: Approval, service enabled, service active

**Postage setting**:
A connection's consent to sell channel postage: *does not sell*, *packer only*, or *packer and
automation*. *Packer only* keeps its postage off every unattended path, whatever the
shipping method allows.

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

**Packaging**:
What a Package is enclosed in, described on two axes: its physical form (box, polybag,
padded mailer) and, when it is not the packer's own, the carrier-supplied identity it
carries (a Priority Mail Small Flat Rate Box, a FedEx Pak). A property of the Box Size.
_Avoid_: Package type (FedEx's word, and ambiguous with Package), packaging type (UPS's
word)

**Carrier-supplied packaging**:
Packaging the carrier provides and prices specifically. A Box Size with a
`carrier_packaging` value. A rate that *requires* one is valid in nothing else.

**Label printer**:
A 4x6 printer on the workstation, of which there are two settings: the *image* label
printer takes PDF/PNG/GIF through its driver, the *raw* label printer takes ZPL bytes
straight through. They may be the same physical device. A label prints on the one its
own format needs, whatever the workstation prefers to buy.
_Avoid_: The label printer (singular — there are two)

**Document printer**:
The 8.5x11 printer on the workstation for pack slips, customs forms and pick lists. Still
`reportPrinter` / `hasReportPrinter` / `printReport()` in code, from before it was renamed
in the UI; the code name is not worth a churn commit.
_Avoid_: Report printer (in anything a user reads)

**PolyBag code**:
A barcode PolyBag prints for scanning: the install's prefix (`SCAN_CODE_PREFIX`, default
`PB`), a type token, then a body. `PBS216` is Shipment 216, `PBB17` is the warehouse's own
Box Size 17, and `PBCSHIP` is the Ship command. It names its record exactly and is never looked up as anything else. Order
references, SKUs, UPCs and tracking numbers are *external identifiers*. See ADR-0007.
_Avoid_: Record code, Shipment ID (for the scanned form), order number

**Duties terms**:
Who pays duties and import charges on an international parcel: the carrier account (DDP)
or the recipient (DDU). Resolved per Shipment from the order, else the client's policy;
an EU destination with neither is unresolved and cannot be bought. See ADR-0008.
_Avoid_: Incoterms (the full set is wider than the two PolyBag uses), duties paid (says
nothing about who)

**Seller tax registration**:
A number proving import VAT was collected at checkout — IOSS (EU), UK VAT, VOEC (Norway),
ARN (Australia). The regime fixes where it is valid. The order's registration replaces
the client's; they are never combined.
_Avoid_: VAT number (also a recipient's tax ID), tax ID (ambiguous with the recipient's)

**Source-decided terms**:
The duties terms on a Label bought through a postage source that cannot take ours —
Amazon Buy Shipping, Shopify Shipping. Recorded as such, never guessed as DDP or DDU.

## Relationships

- A **Shipment** can produce one or more **Packages**.
- A **Package Draft** belongs to exactly one **Shipment**.
- A **Package Draft** becomes a shipped **Package** when label purchase succeeds.
- For now, a **Shipment** should have at most one active **Package Draft** in the packing workflow.
- When a **Package Draft** exists, the packing workflow resumes from the draft as source of truth.
- Opening a **Shipment** in the packing workflow does not create a **Package Draft**; the first saved packing progress (a box, a measurement, or a packed item) does, and progress is saved as it happens.
- A **Package Draft** may only be bought for once it has positive weight and dimensions and, when packing validation is on, every item packed — whichever page the purchase starts from.
- `PackageCreated` means the **Package Draft** was first persisted as a Package row, not that it is ready for label purchase.
- A shipped **Package** has exactly one **postage source**, recorded explicitly rather than inferred from which pointer is set.
- A **carrier of record** and a **postage source** are independent: postage bought from one can move on a parcel carried by any carrier.
- When the postage source is Shopify, the **carrier of record** is not known until after purchase — so nothing that has to be decided before purchase can depend on it.
- A **Package** with no **postage source** recorded has not been shipped; there is no state for a shipped Package whose postage source is unknown.
- A **Package** has at most one active **Label**; it is shipped exactly when it has one. A voided **Label** stays, and the Package's shipping columns are the projection of the active one.
- A void marks the **Label** voided and returns the **Package** to unshipped; re-shipping buys a new **Label** for the same Package.
- A **service class** is satisfied by one or more concrete carrier services; a **blind purchase** satisfies none, because no service is offered.
- An **observed service** is normalized onto an existing `CarrierService`, or promoted by authoring one. Nothing promotes itself.
- Any **Offer** may be selected by a human; shipping rules, auto-ship and batch shipping buy
  only within the shipping method's **allowance**, narrowed by the connection's **postage
  setting**.
- A **blind purchase** is not an **Offer** — with no price it can never win a comparison, so it never enters one.
- An **Offer** is claimed atomically before purchase. If the source's answer is ambiguous,
  it remains awaiting confirmation and must be recovered or resolved before another purchase
  is attempted.
- A Shipment's **duties terms** are its own when set, otherwise its client's for the destination; a person changes them by editing the Shipment, never at the Ship page.
- A rate whose carrier cannot ship the resolved **duties terms** is dropped with a reason, never forced to the other term.

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
> **Domain expert:** "Don't sort it at all. It is a blind purchase, not an offer — there is nothing to compare it against, and Shopify does not report the price even after we have bought it."
>
> **Dev:** "Amazon quoted us OnTrac and we have no OnTrac in the catalog. Do we add it?"
> **Domain expert:** "Not automatically. It is an observed service, and a packer can still pick it. A carrier row only appears when someone decides to author one."
>
> **Dev:** "Once we map that service, can batch shipping buy it?"
> **Domain expert:** "If the shipment's shipping method lists the service it is mapped to, yes — the method is what allows unattended spend. A method whose Amazon Buy Shipping row allows *any service* would have bought it unmapped too, unless the connection sells postage to a packer only."

## Flagged ambiguities

- "orphan package" was used for an unshipped Package left by an interrupted workflow — resolved: this is a **Package Draft**, not something to silently delete.
- "carrier" was used for both who carries the parcel and who sold us the postage, and `packages.carrier` was written as `Shopify` — resolved: those are the **carrier of record** and the **postage source**, recorded separately. Shopify is never a carrier of record.
- "service" was used for a service class, an offer, an observed service, and the confirmed service on a bought label — resolved: those are four terms, and `packages.service` holds only the last of them.
