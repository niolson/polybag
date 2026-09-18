# Resolve an unresolved shipping offer by hand

Status: needs-triage

Repo: `polybag`

## Parent

Split from [`14`](14-quote-direct-carrier-rates-behind-an-opaque-identifier.md) at triage,
2026-09-18. ADR-0002 decision 4's "spent, nothing confirmed" state.

## Problem

An offer consumed by `OfferStore::redeem()` whose purchase neither confirmed nor declined
— a timeout, a dropped connection — blocks every later purchase on its package:
`settleEarlierPurchases()` refuses with *Earlier Purchase Unresolved* until the seller
answers through `RecoversUnresolvedPurchase`. Only Amazon implements that contract, and
even Amazon's `recoverPurchase()` returns `null` — still unknown — whenever the question
itself fails to get through.

There is no UI for the state. `grep ShippingOffer app/Filament resources/views` finds
nothing. Clearing it means a database edit, and the packer's message tells them to
"check the carrier or channel for a label" without saying what to do once they have.
`PurgeData` keeps these rows forever on purpose, so the count only grows.

`14` keeps direct carriers out of the state (a non-recovering seller's timeout resolves
the offer as failed), so this is the Amazon path and any future recovering seller.

## Shape

Somewhere an administrator already looks — the package view, or a small *Unresolved
Purchases* list — an action per unresolved offer with two outcomes:

- **A label exists**: enter the tracking number (and the source's own reference if
  known); `recordPurchase()` and `markShipped()` from what was entered, so the package
  is shipped on the label that was actually bought.
- **Nothing was bought**: `recordFailure()` with the operator's name as the reason, so the
  package is free to be quoted again.

Both are audit-logged with who decided and what they entered. Neither asks the source
anything — the source has already been asked, by `recoverPurchase()`, and did not answer.

## Open questions

- Whether this lives on `ViewPackage` (one package, found by the packer who hit the
  refusal) or a list (every unresolved offer, found by whoever reconciles the seller's
  account). Probably both, with the list being the one that surfaces old rows.
- Whether a `ShipResponse` can be built from a hand-entered tracking number without
  lying about `postage_source` evidence — ADR-0003 decision 7 has `inferred` and
  `unknown` for exactly this.
