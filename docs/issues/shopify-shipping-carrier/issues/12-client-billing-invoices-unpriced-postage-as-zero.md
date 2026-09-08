# Client billing invoices unpriced postage as $0.00

Status: needs-triage

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
