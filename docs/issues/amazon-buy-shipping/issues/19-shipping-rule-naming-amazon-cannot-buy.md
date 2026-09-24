# A shipping rule that uses the Amazon catalog row can never buy

Status: done — shipped 2026-09-24; a rule naming Amazon now names the source, for *Use service* and *Exclude service* alike

Repo: `polybag`

## Problem

A *Use service* shipping rule can name the `Amazon` / `AMAZON_BUY_SHIPPING` catalog row,
which is how a shipping method says "ask Amazon". Every unattended purchase under such a
rule fails, whether the service Amazon offers is approved or not, and for Amazon orders and
orders from other channels alike.

What happens, in `EloquentPackageShippingWorkflow::selectedRateForAutoShip()`:

1. `RuleEvaluator` builds the pre-selected rate from the rule: carrier `Amazon`, service
   code `AMAZON_BUY_SHIPPING`, price 0. It has no Offer and no observed-service identity.
2. `CarrierRegistry::quotingAdapterFor('Amazon')` returns `AmazonBuyShippingAdapter`, whose
   `resolvePreSelectedRate()` hands that rate straight back after the packaging filter.
3. `RateSelector::selectForAutomation()` sees no observed service and passes the rate as
   authored configuration, without asking about approval.
4. The purchase refuses it: *"Amazon Buy Shipping labels are bought against a quoted offer
   for a saved package, which this request did not carry."*

Nothing unapproved is bought, so this is not a spending hole: the purchase path's own offer
check stops it. It does break the rule. It is also one step from becoming a spending hole.
Anything that turned the pre-selected rate into an Offer without asking `RateSelector`
would bypass approval, because step 3 already treats the rate as authored.

Found by `amazon-shipping-external-orders/07`, whose test
`does not let a shipping rule that names Amazon buy an unapproved off-Amazon service` pins
the current "no purchase" outcome.

## What to decide

What *use Amazon* means for a rule. Amazon's services are discovered per quote, so a rule
cannot name one in advance. The likely reading: rate-shop, keep only the offers Amazon
quoted, and select among them through `selectForAutomation()`, so that approval, the
shipping method's due-by and OTDR requirements, and the rule's exclusions all apply.
`resolvePreSelectedRate()` returns a single rate, so this does not fit it as it stands.

## Acceptance criteria

- [x] A *Use service* rule naming the Amazon catalog row buys the cheapest acceptable
      approved Amazon offer, and never a rate from another source
- [x] An unapproved Amazon offer under such a rule is withheld and named, as in rate shopping
- [x] A rate built from a rule never reaches `selectForAutomation()` looking like authored
      configuration when it stands for a discovered source
- [x] An *Exclude service* rule naming the Amazon catalog row drops Amazon's offers

## Resolution

Decided 2026-09-24:

- **Strict, not a preference.** Under a rule naming Amazon, automation buys an
  acceptable Amazon offer or nothing. It does not fall back to another source or to a
  blind purchase, so the rule is a guarantee.
- **The rule names the source.** A new `DiscoversServices` contract, implemented by
  `AmazonBuyShippingAdapter`, marks a source whose services are discovered per quote.
  `RuleEvaluator` returns `preSelectedSource` for such a source instead of making up a
  price-0 rate. The auto-ship path rate-shops, keeps only offers whose observed-service
  source matches, applies the rule's exclusions and selects through
  `selectForAutomation()`. Approval, due-by and OTDR requirements apply as in rate
  shopping. No rule-built rate for a discovered source exists any more, so criterion 3
  holds by construction rather than through a guard in `RateSelector`.
- **Exclusion by source.** Found while scoping this issue: an *Exclude service* rule
  naming the Amazon row excluded the `AMAZON_BUY_SHIPPING` service code, which no offer
  carries, so it did nothing. It now excludes by source (`excludedSources`). This applies
  on the Ship page too.
- On the Ship page, the rule pre-selects the cheapest Amazon offer, on-time offers first.
  The packer can still pick any offer.

The test that pinned the old "no purchase" outcome in
`OffAmazonShippingAutomationTest` now asserts that the offer is withheld and named, with
a cheaper direct rate present that must not be bought.

## Blocked by

None.
