# Log quotes for purchases that never rate-shopped

Status: needs-triage

Repo: `polybag`

## Parent

Split from [`14`](14-quote-direct-carrier-rates-behind-an-opaque-identifier.md) at triage,
2026-09-18, where `rate_quotes`' purpose was restated.

## Problem

`rate_quotes` exists to answer "what would the other options have cost?" — so that when
the app picks a method to meet a delivery window, or a packer picks the wrong one, the
saving is visible afterwards. `RateComparison` reads it for exactly that.

It only has an answer when the purchase rate-shopped. Three paths buy without ever
calling `getShippingRates()` and log nothing:

- a shipping rule's pre-selected rate, resolved by `resolvePreSelectedRate()` and bought
  through `autoShip()`;
- a Shopify blind purchase, which has no rate at all until after the label exists;
- any future blind-purchase source.

Those are precisely the purchases most worth checking, because no person compared them.

## Shape

A shadow quote, after the fact and off the packer's path: a queued job that, for a
shipped package with **no `selected` quote row**, asks `getShippingRates()` what it would
have offered and writes the rows with the bought service marked `selected`. Then
`RateComparison` covers every package, and a rule buying a too-expensive service shows up
as savings foregone.

"No selected row", not "no rows": an attended Shopify blind purchase already has
`rate_quotes` rows, because `getShippingRates()` logs whatever the other carriers
returned while the blind offer is advertised beside them — and none of those rows is
selected, because nothing in the list was bought. A job keyed on an empty log would skip
exactly those packages. For one that already has rows, the job adds the bought service as
a further row (its cost from the label where the source reports one, null where it does
not) marked `selected`, rather than re-quoting the alternatives that are already there.

## Open questions

- **Cost.** A shadow quote is a carrier call per package. USPS and UPS rating are free;
  FedEx rating is free today, but FedEx has already started metering tracking, so a
  free rate call is not a promise either. On a batch of hundreds this is hundreds of rate
  calls a day for a report. Sample rather than quote everything? Quote only
  rule-selected purchases, where the rule is what's being audited?
- **Timing.** The quote should be for the ship date and the package as shipped; both are
  on the row, but a rate quoted an hour later on a different ship day is not the same
  comparison.
- **Which packages.** Is a shadow quote against a Shopify blind purchase meaningful when
  Shopify's cost is null (`shopify-shipping-carrier/12`)? Savings need two numbers.
- **The report itself.** `RateComparison` answers "did the packer pick something pricier
  than the cheapest quoted" and nothing else. Whether it earns the calls this issue
  would spend is the first thing to decide.
