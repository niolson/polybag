# Split unattended rate selection out of the shipping workflow

Status: needs-triage

Repo: `polybag`

Severity: architecture — no defect on its own; it makes defects like `01`–`03` easier to
write and harder to see.
Verified: n/a.

## Problem

`EloquentPackageShippingWorkflow` is 1,663 lines and holds four jobs:

| Lines (approx.) | Job |
|---|---|
| 67–151 | Building the attended view (`prepareRates()`) |
| 160–1170 | Buying: locks, pre-purchase checks, the offer claim, the carrier call, error mapping, recovery of earlier purchases, account and packaging re-checks |
| 1228–1428 | Choosing what automation may buy (`selectedRateForAutoShip()`) |
| 1430–1650 | Explaining in operator language why automation bought nothing |

The purchase half enforces the double-purchase guarantees, and it shares one class,
one set of catch blocks and one result type with ~650 lines of selection and messaging.
Two consequences show up in this review:

- `autoShip()` wraps `purchase()` in a second set of catch blocks that duplicate
  `buyPostage()`'s, and the cleanup decision lives in the automation half while the
  facts it needs (is an Offer unresolved?) live in the purchase half. `01` is that gap.
- The trust boundary is decided by entry point: `ship()` requires an offer and
  `autoShip()` doesn't. That's documented, but it's why `02` could exist; the
  unattended path skipped the Offer because it didn't need the tamper protection, and
  lost the recovery protection with it.

## What to build

Move unattended selection and its messaging into their own service (for example
`UnattendedRateSelector`), returning `UnattendedRateSelection` as now. The workflow's
`autoShip()` becomes: select, issue an Offer for the selection (`02`), then call the same
purchase path `ship()` uses. The purchase class is left with one job, and every rule it
enforces applies to both entry points.

Best done together with `02`, which changes the same seam.

## Comments

- 2026-10-02 — Partly done. `02` and `18` already gave every rule-selected rate the
  Offer its quote issued, and `autoShip()` uses the same `purchase()` path as `ship()`.
  The offer requirement now lives in `purchase()` rather than `ship()`, so a non-blind
  rate with no Offer is refused whichever entry point sent it. What is left is the move
  itself: unattended selection and its messaging (`selectedRateForAutoShip()` through
  `refusedForMethodRequirements()`, ~430 lines) into their own service, after which
  `autoShip()`'s catch blocks need only cover selection's failures.
