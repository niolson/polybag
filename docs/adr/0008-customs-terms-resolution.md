# ADR-0008: Customs terms are resolved per Shipment from the order, then the client, and filtered by sourced carrier data

## Status

Proposed — 2026-10-01. Decided in a design session the same day; sliced into
`docs/issues/international-customs-terms/`.

## Context

Every international label PolyBag buys declares the same terms whoever the client is and
whatever the order says. FedEx bills duties to the shipper's account on every
destination; UPS and USPS ship DDU everywhere; no seller tax registration or recipient
tax ID is ever sent; USPS claims an export-filing exemption on every parcel and declares
`US` as the origin of any item with none.

Since 1 July 2026 the EU charges a €3 duty per tariff line on every import, with a
handling fee to follow. DDU parcels into the EU now carry door fees large enough to drive
refusals, and postal networks are starting to refuse them outright. USPS already
requires prepaid duties into six EU countries and cannot prepay into twelve others. A
3PL ships for clients who answer these questions differently: one collects duties at
checkout and holds an IOSS registration, one has no EU registration, one sells through a
marketplace that collected the VAT under its own number. More channels and ERP
integrations are planned, so the order itself must be able to carry the answers.

Three questions are involved, and they are independent:

- **Duties terms** — who pays duties and import charges: the carrier account (DDP) or the
  recipient (DDU).
- **Seller tax registration** — the number proving import VAT was collected at checkout
  (IOSS, UK VAT, VOEC, ARN), or none.
- **Required IDs** — what the destination needs to clear the parcel: a recipient tax ID,
  an export ITN.

## Decision

### 1. A client's duties policy is a destination map that starts unset

`clients.duties_policy` maps a destination — `EU` or an ISO country code — to `ddp` or
`ddu`. The most specific entry wins. A destination outside the EU with no entry is DDU.
An EU destination with no entry is **unresolved**, and the label is refused with a
message naming the client and the fix.

There is no default term for the EU. DDU walks parcels into postal refusals and door
fees; DDP bills duties to whichever carrier account the scope resolves, which in a 3PL
may be the 3PL's own. Either default would ship on terms nobody chose.

### 2. The order overrides the client

`shipments.duties_terms` (`ddp`/`ddu`, nullable) overrides the client's policy in either
direction, and a set value counts as the choice decision 1 requires. An order that
collected duties at checkout must ship DDP or the recipient pays twice; a business order
from an ERP may legitimately ship DDU against a DDP client default.

There is no separate packer-level setting. The Shipment's value is the override, edited
by anyone who may update a Shipment — a manager — exactly as the shipping method is. The
resolved terms are part of the Offer fingerprint, so changing them forces a re-quote.

### 3. The order's seller tax registration replaces the client's

`shipments.seller_tax_regime` and `seller_tax_number` hold a registration the channel or
ERP supplied. When set, and its regime covers the destination, it is sent and the
client's is not. They are never combined: a marketplace that is the deemed supplier
collected the VAT under its own number, and the client's number would declare the wrong
seller.

A client holds at most one registration per regime in `client_tax_registrations`. The
regime, not the operator, fixes which destinations it covers, the number's format and the
low-value threshold above which it does not apply.

### 4. Carrier duties support is sourced data, and it filters rates

`resources/data/customs/duties-support.json` gives each carrier and destination one of
`ddp_required`, `either` or `ddu_only`, each entry with a source URL and the date it was
checked, optionally an effective date, and a file-level version. It follows the pattern
of `resources/data/service-inference/`.

A rate whose support does not fit the resolved term is dropped, and the Ship page names
the reason. It is never forced to DDP — that would bill duties against the client's
choice — and never silently sent DDU into a destination that refuses it.

A USPS DDP rate additionally requires the quoting `CarrierAccount` to have accepted
USPS's DDP terms, recorded on the account by an Admin, because the account holder is the
party billed.

### 5. Sources that cannot take terms are source-decided

Amazon Buy Shipping and Shopify Shipping offers carry terms PolyBag cannot set. They skip
the filter and the unresolved-EU refusal, are labeled as such on the Ship page, and are
recorded as `source_decided`. Automation does not buy them for EU, GB, NO or AU
destinations until a test purchase establishes what the source declares, at which point
the source gets entries in `duties-support.json` like a carrier.

### 6. What was declared is recorded on the Label

`package_labels.customs_terms` snapshots the duties term and where it came from (`order`,
`client`, `source_decided`), the registration sent and where it came from, the ITN or
exemption, and the `duties-support.json` version. USPS's prepaid duties amount is stored
in `duties_cost`.

### 7. One readiness check, authoritative at purchase

Rules that need no rate — terms resolved, origin, HS code, recipient tax ID, ITN, the
existing zero-value and product-identifier guards, and the warnings — live in one
`CustomsReadiness` service. The shipping workflow refuses on its blocks for every path,
batch and automation included; the Ship page shows its findings before rating.

## Options considered

- **Default `ddu`.** Rejected: drops every USPS rate to the six DDP-required countries and
  sends express parcels into €15–25 door fees.
- **Default `ddp_eu`.** Rejected: bills duties to a carrier account nobody authorized.
- **A third term, "DDP where the rate supports it, else DDU".** Rejected: rate shopping
  would rank a DDU parcel, whose recipient pays at the door, against a DDP one as if they
  were the same purchase. A client who wants USPS DDU to Poland says `PL → ddu` once.
- **A packer override at the Ship page.** Rejected: it is a billing decision, and a third
  layer would let the Label disagree with the Shipment.
- **Forcing DDP when a carrier requires it.** Rejected: bills duties against the client's
  policy; dropping the rate with a reason is honest.
- **Carrier support in a database table editable in Filament.** Deferred: reacts faster,
  but each install drifts from its own copy and entries lose their sources. Revisit if
  the release cadence hurts.
- **Merging the order's registration with the client's.** Rejected: see decision 3.
- **Six-digit HS grouping for the $2,500 export-filing rule.** Deferred in favor of the
  customs total, which never under-blocks and needs no HS data.

## Consequences

- No EU label can be bought for a client until someone sets its EU terms, or the order
  carries them.
- A DDU client cannot buy USPS into DE, BE, DK, FI, FR or PT; a DDP client cannot buy
  USPS into the twelve countries USPS cannot prepay, unless it adds a `ddu` entry for
  them.
- When a country changes its rules, the fix is a sourced line in a JSON file and a
  release.
- Every importer — Shopify, Amazon, Database, and future channels and ERPs — writes the
  same order-level columns; none of this lives in `shipments.metadata`.
- Postage-source purchases into the EU stay attended until their terms are verified.
