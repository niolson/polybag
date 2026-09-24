# A shipping rule that uses the Amazon catalog row can never buy

Status: needs-triage

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

- [ ] A *Use service* rule naming the Amazon catalog row buys the cheapest acceptable
      approved Amazon offer, and never a rate from another source
- [ ] An unapproved Amazon offer under such a rule is withheld and named, as in rate shopping
- [ ] A rate built from a rule never reaches `selectForAutomation()` looking like authored
      configuration when it stands for a discovered source

## Blocked by

None.
