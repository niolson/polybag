# Project review after the September feature and refactor work

Status: reference

Repo: `polybag`

## Why

September 2026 merged 124 pull requests: the postage-source split, the carrier catalog
reset, label history, packaging identity, and Amazon Shipping sold to other channels.
Each pull request was reviewed on its own diff. This review reads whole flows across
them, because the defects most likely to survive per-diff review sit where two
separately correct changes meet.

## How it is organised

The review runs in areas, each a path money or data takes through the app rather than
a directory:

| Area | Covers | State |
|---|---|---|
| A | Label purchase — Ship page, `ShippingRateService` offer issuance, `OfferStore`, `EloquentPackageShippingWorkflow`, `BatchLabelService` / `GenerateLabelJob` | Reviewed 2026-09-28; issues `01`–`05` |
| B | Label lifecycle — reprint, void, tracking and manifest dispatch, Shopify fulfillment sync | Not started |
| C | Postage sources and carrier adapters | Not started |
| D | Client and location scoping, authorization | Not started |
| E | Automation — rules, allowance, batch selection | Not started |

Each area is checked against the invariants its ADRs and `CONTEXT.md` state, not
against a general checklist. Area A's:

1. The browser names an Offer and restates nothing (ADR-0002 decision 4).
2. An Offer is bound to its Package, quote inputs, source instance, environment and
   billing identity, and expires.
3. An Offer is claimed atomically; it cannot be spent twice.
4. An ambiguous purchase leaves the Offer awaiting confirmation, and nothing more is
   bought for the Package until it is recovered or resolved (`CONTEXT.md`).
5. A purchase that succeeded at the source and failed here is found, not repeated.
6. `markShipped()` writes the Label row in its transaction; a Package has at most one
   active Label (ADR-0004).
7. With no shipping method, nothing is bought (ADR-0006 decision 12).
8. Automation buys only within the allowance.

## How findings are verified

Every finding is one of:

- **Confirmed** — a Pest test asserting the required behaviour fails against `main`.
  The test is in the issue and becomes the regression test of the fix. The probes are
  not committed as failing tests.
- **Plausible** — the code plainly allows it, but no test reaches it cheaply; the issue
  gives the exact scenario.

Candidates that did not hold up are listed below so they are not re-investigated.

## Area A: what held

Invariants 1, 2, 3, 6 and 7 hold on the attended path. In particular: `ship()` refuses a
rate with no offer; `rateFromOffer()` rebuilds carrier, service, price and metadata from
the row; `redeem()` claims with one conditional `UPDATE`; the quote fingerprint and the
carrier-account fingerprint retire an offer whose inputs or payer changed; the per-package
and per-shipment blind-purchase locks close the double-click and sibling-package races;
and `buyPostage()` re-reads status and shipping method from the database rather than the
page's stale instance.

Invariants 4 and 5 hold only while the Offer row survives and exists, which is where
the findings are.

## Area A: candidates dropped

- *A direct Offer with no expiry.* `shipDatesFor()` returns a date for every direct and
  Amazon Shipping task; only Buy Shipping gets none, and its offers carry Amazon's own
  window.
- *The Ship page's public `rateOptions` array is tamperable.* It is, but only the offer
  identifier is read from it, and `inspect()` rejects an offer belonging to another
  Package.
