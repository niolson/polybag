# Client billing invoices unpriced postage as $0.00

Status: needs-triage — flag half shipped 2026-09-08; what to charge waits on `05`

Repo: `polybag`

## Problem

`ClientBillingReport` builds its postage figure the same way the dashboard rollup did
before `08`:

```php
DB::raw('COALESCE(SUM(p.cost), 0) as postage'),
```

`app/Filament/Pages/Reports/ClientBillingReport.php`, `buildPackageSubquery()`. That
`postage` is not display-only — it is the first term of `line_total`:

```php
'pkg.postage + pkg.package_count * '.$labelFee.' + ...'
```

So a package whose seller reports no price bills the client **$0.00 of postage**, while
the label fee, pick fees, materials and surcharges on the same line all charge normally.
The line looks complete. Nothing on it says postage is missing.

This is the same root cause as `08` — `packages.cost` is null when the seller reports no
price — but a different query path (it reads `packages` directly, not
`daily_shipping_stats`) and a materially different consequence. `08` produced a
misleading dashboard number. This produces an invoice that is wrong in the client's
favour, by however much the postage actually cost.

Shopify Shipping is the case that prompted this, but it is not the only one: any package
with a null cost bills the same way.

## Why it wasn't fixed alongside `08`

`08` was a reporting decision — how to describe a number that is knowably incomplete.
This is a billing decision, and the options are not the same ones:

- **Show the gap and let a human resolve it.** Flag lines with unpriced postage in the
  report so nobody exports an invoice without seeing them. Cheapest, and consistent with
  how `08` treats the dashboard. Does not answer what to actually charge.
- **Exclude those lines from the invoice** until postage is known, rather than billing
  them at a wrong total.
- **Bill a per-client fallback postage rate**, reconciled later. Charges something, but
  invents a number — which is the thing `08` explicitly refused to do to
  `packages.cost`. Whether that objection carries over to a billing rate a client has
  agreed to is the question.
- **Wait for `05`** to supply real costs. Same caveats as in `08`: Shopify Payments only,
  heuristic matching, and a re-consent problem. It narrows the gap without closing it.

Needs a decision from whoever owns billing, not a reporting default.

## Blocked by

Nothing. `08` is done and did not change this path.

## Comments

### 2026-09-08 — first in the working order, and split in two

Sequenced ahead of every other open issue in this directory, and it is the one that has
nothing to do with Shopify. It is the only open item that is presently wrong in a way that
moves money: `postage` is a term in `line_total`, so a null-cost package under-invoices the
client by the whole postage amount while every other fee on the line charges normally. Each
billing run that goes out is wrong by that much, and nothing on the invoice says so.

Everything else here is exploration on a development store that costs nothing and harms
nobody while it waits. This does not wait.

**The split.** The four options above are not one decision:

- **Flag lines with unpriced postage** so nobody exports an invoice without seeing them.
  This is a *reporting* decision, not a billing one — it is the same treatment `08` gave
  the dashboard, it charges nothing new, and it is safe to ship without the billing owner
  in the room. Do this now.
- **What to actually charge** — exclude the line, bill a fallback rate, or wait for real
  costs — stays with whoever owns billing, and is better answered after `05`, which is
  sequenced last of the substantive work for exactly this reason.

Shipping the flag first is not a substitute for the second half. It converts a silent error
into a visible one, which is worth doing on its own and is the precondition for anyone
noticing how often the second half matters.

**Left at `needs-triage`** because the charging half genuinely needs a decision from the
billing owner. The flag half is specified enough to grab today.

### 2026-09-08 — decision on the flag half, and what was built

**Show the gap.** Of the four options, the report flags the lines and a human reconciles
them against the seller's own billing data. The other three were not taken:

- **Excluding the lines** withholds the label fee, pick fees, materials and surcharges on
  that line too, all of which are correct and earned. It fixes an under-charge by dropping
  a larger correct charge.
- **A fallback postage rate** invents a number, which is what `08` refused to do to
  `packages.cost`, and it would have to be unwound once the real cost arrives.
- **Doing nothing until `05`** leaves each billing run silently wrong in the meantime.

Deliberately small, because the expectation is that real label costs are recoverable —
`05` for Shopify, and a null cost from any other source is a bug in the write path rather
than a permanent unknown. This is disclosure to bridge that, not a billing mechanism.

**What was built** — all of it in `ClientBillingReport`, no schema:

- `buildPackageSubquery()` counts `COUNT(p.id) - COUNT(p.cost)` as
  `uncosted_package_count`, the same name `VolumeReport` uses for the same quantity. It
  describes the condition, not one cause of it: a manual ship or a failed cost write
  reads the same as a Shopify label.
- Both views disclose it under the postage figure — *"N billed at $0.00 — no reported
  postage"* — and the detail line carries a `danger` badge beside the existing
  *"No item data"* one.
- **Unpriced postage only**, a toggle filter on the billable event log. This is the
  reconciliation view: the lines to look up in the seller's billing export, and nothing
  else.
- Both CSVs gain an **Unpriced Packages** column, and the detail export honours the
  toggle, so the exported file matches what was on screen.

`line_total` is unchanged and still under-bills by the missing postage. That is the half
this does not fix, and the badge is what stops it going out unnoticed.

**Still open:** what to actually charge. Better answered after `05`, which is sequenced
last of the substantive work — if it supplies real costs for most Shopify labels, the
remaining population may be small enough that reconciling by hand is the whole answer and
no billing rule is needed.
