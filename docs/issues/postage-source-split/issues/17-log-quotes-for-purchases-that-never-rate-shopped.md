# Record a blind purchase as the selected quote

Status: needs-triage — unblocked 2026-10-03: `shopify-shipping-carrier/05` now records a USD Shopify label's cost, within the hour of purchase

Repo: `polybag`

## Parent

Split from [`14`](14-quote-direct-carrier-rates-behind-an-opaque-identifier.md) at triage,
2026-09-18, where `rate_quotes`' purpose was restated. Narrowed 2026-10-03, below.

## Problem

`rate_quotes` exists to answer "what would the other options have cost?" — so that when
the app picks a method to meet a delivery window, or a packer picks the wrong one, the
saving is visible afterwards. `RateComparison` reads it for exactly that, and lists only a
package with a `selected` row.

A blind purchase has none. Attended or unattended, the package is rate-shopped first —
`soleBlindPurchaseOfferForAutomation()` refuses to run before `getShippingRates()` — so the
alternatives are logged. But a blind offer has no `rate_quote_id`, `markSelected()` skips
it, and nothing in the log says what was bought. Those packages never reach the report.

## What is left to build

When a blind purchase completes, add the bought service as a further `rate_quotes` row,
marked `selected`, with the cost from the Label. The alternatives are already there; this
does not re-quote them.

That needs two numbers to be worth having, and Shopify reports no cost today. Until
`shopify-shipping-carrier/05` recovers one, the row would carry a null price — which also
needs `quoted_price`, `NOT NULL` today, made nullable, and the report taught to show it —
and would say only which service Shopify chose, which the Label already says. So this
waits for `05` and is built with it or after it.

## Blocked by

- [`shopify-shipping-carrier/05`](../../shopify-shipping-carrier/issues/05-shipping-label-cost-reconciliation.md) —
  a cost for a Shopify label.

## Not in scope: shadow quotes

The issue first proposed a queued job that, after the fact, asks `getShippingRates()` what
it would have offered for a shipped package with no `selected` row. Nothing needs that now:
every purchase path rate-shops before it buys. It is worth reopening only if a path appears
that buys without quoting — and then the cost of a carrier call per package, and quoting
for a different ship day than the label's, are the questions to answer first.

## Open questions

- **The report itself.** `RateComparison` answers "did the packer pick something pricier
  than the cheapest quoted" and nothing else. Whether a blind purchase belongs in it once it
  has a cost — Shopify's rate is not one a packer could have picked from a list — is worth
  deciding when `05` lands.

## Comments

- 2026-10-03 — Narrowed. Of the three paths the issue named, rule-selected purchases are
  covered: since `c406c37` a *Use service* rule selects among rate-shopped rates, and
  `09e239a` removed `resolvePreSelectedRate()`, so every rule purchase names an Offer that
  points at its quote row and `markSelected()` marks it. Blind purchases are the gap that
  remains, and the shadow-quote job moved out of scope, so its cost and timing questions
  went with it.
- **2026-10-03** — `shopify-shipping-carrier/05` shipped. The cost reaches
  `package_labels.cost` by an hourly sync rather than at purchase, so the `selected` row would be
  written then or updated when the cost lands. USD only; other currencies stay null.
