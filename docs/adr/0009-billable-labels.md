# ADR-0009: A billable label is a production Label not voided before its billing period closes, counted from its own ledger

## Status

Proposed — 2026-10-12.

## Context

The hosted plan is priced per label: a monthly base that includes a number of labels,
then a per-label rate in graduated bands, with promotional rates for early-access
customers. Prices and promotions change, and they live in the billing system, not here.
What this repository has to settle is what counts as one label, because that number is
what the customer is charged for and the one thing the app must be able to produce.

There is more than one candidate count, and they disagree:

- A Shipment can ship as several Packages, each with its own Label.
- A Label can be voided and the Package shipped again, which leaves two Label rows for
  one parcel (ADR-0004).
- Labels come from four kinds of postage source: direct carrier accounts, Amazon Buy
  Shipping, Amazon Shipping on a connection's account, and Shopify Shipping blind
  purchases (ADR-0002).
- Labels are bought in carrier sandboxes during setup and testing, and fake carrier
  adapters buy "labels" that cost nothing.
- A Shipment can be marked shipped by its source with no Label at all
  (`order-reconciliation`).

This is PolyBag's own billing of its customers. It is unrelated to the Client Billing
report, in which a 3PL bills its own clients.

## Decision

### 1. The unit is a `PackageLabel` row

One purchased outbound-postage instance is one billable label, as `CONTEXT.md` defines a
Label. A Shipment sent as three Packages is three labels. Reprints, customs and ETD
documents that come with a Label, SCAN forms and manifests are not separate labels.

### 2. Only production Labels count

A Label bought in a carrier sandbox, or by a fake carrier adapter, does not count. Where
it was bought is recorded when the Label is written (decision 7), as `ShippingOffer`
already records it. It has to be recorded because nothing on a Label says so today: the
Offer does, but blind Shopify purchases have no Offer, and `sandbox_mode` is one shared
setting that can be switched afterwards.

### 3. A Label belongs to the billing period of its `purchased_at`

Periods are the billing system's, compared in UTC. Usage is pooled across the whole
instance: every Client, Location and workstation.

### 4. A void counts if PolyBag learned of it before the period was closed

A Label voided in time does not count, whoever started the void (`VoidReason`). The
replacement Label bought after a void counts as normal, so void-and-rebuy is one label,
not two.

"In time" is measured by when PolyBag *observed* the void (`voided_at`), not by a
timestamp the source reports. Every void path sets `voided_at`, and sources don't
reliably report when a void happened. A void made in the Shopify admin reaches PolyBag on
the synchronizer's next poll, so a void made just before the period ends can be observed
just after it.

To cover that lag, a period is not closed when it ends but after a **grace window** of
three days (decision 8). A void observed within the grace window removes its Label from
the period it was bought in. That also credits a void that genuinely happened in the
first days of the next period. This is accepted: it favors the customer, and it is
limited to three days. A void observed after the period is closed creates no credit, so
a closed invoice stays closed.

When a source does report a void time, it is stored for audit and nothing else.

### 5. Every postage source counts

Direct, Amazon Buy Shipping, Amazon Shipping and Shopify blind purchases all count. The
operator did the same work in PolyBag whoever was paid for the postage, and a single
rule is easier to explain on a pricing page than four.

### 6. A Shipment with no Label is never billed

A Shipment shipped by other software, cancelled, or still open has no production Label,
so it adds nothing.

### 7. Usage is recorded in its own ledger, not read from Label history

Label history cannot be the billing record. ADR-0004 decision 7 lets Label rows go when
their Package is deleted, and `shipments:archive` deletes them on purpose. A Label bought
in one period, voided in the next, then archived would vanish from a count that has to
stay reproducible.

So every Label gets a `label_usage` row, written in the same transaction as the Label in
`Package::markShipped()`:

| Column | Holds |
|---|---|
| `package_label_id` | The Label, as a plain column with no foreign key, so no delete cascades into it |
| `purchased_at` | Copied from the Label |
| `environment` | `production` or `sandbox` (decision 2) |
| `postage_source` | For reporting; every source counts (decision 5) |
| `voided_at` | When PolyBag observed the void, set by `Package::clearShipping()` |
| `source_voided_at` | The source's own void time, when it gives one; audit only |

No Package delete or archive removes these rows. They hold no address or customer data,
so the PII purge has nothing to remove from them, and they are kept as long as billing
records need to be.

### 8. A closed period's count is stored and never recomputed

A `billing:close-period` command runs once the grace window has passed. It computes the
period's count from `label_usage` and writes a `billing_periods` row holding the period
bounds, the count and when it was closed. A closed row is never changed. Hosted metering
reports closed rows. The count for the open period, for example on a usage page, is
computed live from the ledger.

### 9. The app reports the count; it does not invoice

Pricing, bands, promotional phases, invoices and payment collection belong to the
billing system. Hosted metering, which reads each instance's closed periods and reports
them to the billing system, lives with our other hosting tooling outside this repository.

An optional read-only usage page may show the open period's count. It shows labels, not
money, so it never needs to know the prices.

## Consequences

- Two tables: `label_usage` and `billing_periods`. There are no live tenants, so
  `label_usage` is backfilled from existing Labels, all marked as sandbox, and billing
  starts clean.
- Label rows are written in `Package::markShipped()`, and by
  `PackageLabel::createFromPackage()` for integrity repairs and the FedEx test cases.
  Each writer also writes the ledger row. A test should fail if a Label exists without
  one.
- `Package::clearShipping()` sets `label_usage.voided_at` alongside the Label's.
- A test covers the case behind decision 7: buy in one period, void in the next, delete the
  Package, and the closed count is unchanged.
- The demo instance's fabricated history (`demo:reset`) is not metered, so it needs no
  special case.

## Open questions

- **On-prem installs.** They would have to report usage over the internet, and some
  cannot or will not. Settle on-prem pricing (a flat license, or self-reported usage)
  before offering it.
- **Billing anchor.** Calendar month, or the subscription's start date? The close command
  takes explicit period bounds either way.
- **Grace window length.** Three days covers the Shopify poll with a wide margin. If
  Amazon void detection is added (`order-reconciliation` open question 4), check its lag
  against the window.
