# Client billing invoices unpriced postage as $0.00

Status: needs-triage — flag half shipped 2026-09-08; what to charge waits on `05`

Repo: `polybag`

## Problem

`ClientBillingReport::buildPackageSubquery()` builds its postage figure with
`COALESCE(SUM(p.cost), 0)`, and that `postage` is not display-only — it is the first term
of `line_total`. So a package whose seller reports no price bills the client **$0.00 of
postage** while the label fee, pick fees, materials and surcharges on the same line all
charge normally. The line looks complete. Nothing on it says postage is missing.

Same root cause as `08` — `packages.cost` is null when the seller reports no price — but a
different query path and a materially different consequence: `08` produced a misleading
dashboard number, this produces **an invoice that is wrong in the client's favour**.
Shopify Shipping prompted it; any package with a null cost bills the same way.

It is the only open item in this directory that is presently wrong in a way that moves
money, which is why it was sequenced ahead of everything else here — including everything
Shopify.

## The split

The four options are not one decision:

- **Flag lines with unpriced postage.** A *reporting* decision, the same treatment `08`
  gave the dashboard, safe to ship without the billing owner in the room. **Done.**
- **What to actually charge** — exclude the line, bill a per-client fallback rate, or wait
  for real costs from `05`. Stays with whoever owns billing.

## Decision on the flag half — 2026-09-08

**Show the gap** and let a human reconcile against the seller's own billing data. The
other three were not taken: **excluding the lines** withholds the label fee, pick fees,
materials and surcharges that are correct and earned, fixing an under-charge by dropping a
larger correct charge; **a fallback postage rate** invents a number, which is what `08`
refused to do to `packages.cost`, and would have to be unwound once the real cost arrives;
**doing nothing until `05`** leaves each billing run silently wrong in the meantime.

Deliberately small, because the expectation is that real label costs are recoverable — this
is disclosure to bridge that, not a billing mechanism.

**What was built**, all in `ClientBillingReport`, no schema:

- `COUNT(p.id) - COUNT(p.cost)` as `uncosted_package_count`, the same name `VolumeReport`
  uses for the same quantity. It describes the condition, not one cause of it: a manual
  ship or a failed cost write reads the same as a Shopify label.
- Both views disclose it under the postage figure — *"N billed at $0.00 — no reported
  postage"* — with a `danger` badge on the detail line.
- **Unpriced postage only**, a toggle filter on the billable event log: the reconciliation
  view, and nothing else.
- Both CSVs gain an **Unpriced Packages** column, and the detail export honours the toggle.

`line_total` is unchanged and still under-bills by the missing postage. That is the half
this does not fix, and the badge is what stops it going out unnoticed.

## Still open

What to actually charge. Better answered after `05` — if it supplies real costs for most
Shopify labels, the remaining population may be small enough that reconciling by hand is
the whole answer and no billing rule is needed.

## Related

- `08` — the same defect on the reporting path, done
- `05` — the cost recovery this waits on
