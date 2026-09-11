# Represent Shopify as a blind purchase, not a fabricated rate

Status: done — 2026-09-04

Repo: `polybag`

## Problem

`ShopifyAdapter::getRates()` returned `RateResponse` objects carrying an invented price, an
invented service name and `Shopify` as the carrier. None of those are facts. ADR-0003
decisions 5 and 6 reject that model outright: `ShippingLabel` exposes no service and no
price before or after purchase, and omitting `preferredRateSelection` gives Shopify an
unconstrained choice. It is a blind purchase, not a rate.

## What shipped

A type, two contracts, a column, and a second radio group.

- **`BlindPurchaseOffer`** — source, source label, service code, selection label, postage
  data source. **No price field**, deliberately: the invented `0.00` has nowhere to go, so
  nothing downstream can sort, rank or compare it.
- **`BlindPurchaseSource`** — `blindPurchaseOffers()`, implemented by `ShopifyAdapter`
  alone. Splitting it out of `CarrierAdapterInterface` needed a base contract for what
  every source answers whether or not it quotes, so `PostageOfferSource` holds
  `getCarrierName()`, `isConfigured()`, `offerCapability()`, `offerDeclaredValueCap()` and
  `createShipment()`, and `CarrierAdapterInterface` is the quoting half alone.
- **`clients.blind_purchase_enabled`** — off by default, per client, and in Settings for a
  single-client install.
- **The Ship page** lists offers in their own block below the rates, dashed and
  warning-coloured, behind a panel saying price and service are unknown. Selecting one
  clears the rate selection and vice versa; shipping opens a confirmation modal naming what
  is not known — including that the label cannot be voided from PolyBag — and only the
  confirmation buys anything. Consent resets after every attempt.
- **`ShipRequest`** carries either a `selectedRate` or a `blindOffer`, never both.

`ShopifyAdapter` lost `getRates()` and `resolvePreSelectedRate()` outright rather than
stubbing them, which is what makes "no method could return a `RateResponse`" structural.

## The decisions the issue left open

**Automation is excluded structurally, not by permission.** The criterion read "no
automated path without a client opt-in", which implies that with the opt-in automation may.
It is stricter: no automated path reaches a blind purchase at all, because `selectBest()`,
auto-ship, batch ship and shipping rules are typed in `RateResponse` and a
`BlindPurchaseOffer` is not one. Three places enforce it because they fail differently —
`RateSelector::selectBest()` now **drops** unpriced rates and returns null (`classify()`
unchanged, so the attended list still shows them); `RuleEvaluator` skips a `UseService`
rule naming a blind-purchase source and carries on; `selectedRateForAutoShip()` falls
through to rate shopping and refuses anything `priceUnknown`. Neither is a name check —
both ask the registry.

**The offer is re-derived at the purchase, not trusted.** `blindPurchaseOffers` is a public
Livewire property, so what comes back is the client's words. `blindPurchaseOffersFor()`
answers "was this ever offered?" by building the same carrier tasks quoting builds and
asking only the blind-purchase sources — skipping `fetchRatesConcurrently()`, so no carrier
is called and no money is spent finding out — and `resolveBlindOffer()` matches by
identifier and **buys the server's copy**. Caught in review: without it, an opted-in user
could name a selection outside the shipping method, or one a hard-required service had just
excluded, and have it bought. That also collapsed two enforcement points into one, so the
hard-required special service is checked by `buildCarrierTask()` alone and the refusal
quotes that reason back rather than restating the rule in a second wording.

**Consent is checked twice, separately** — in `blindPurchaseOffers()` so nothing is ever
advertised, and in `resolveBlindOffer()` before re-derivation, because a client who has not
opted in produces an empty list too and "no longer available" would send an operator
looking for the wrong thing.

**A blind offer has no `ShippingOffer` row and writes nothing to `rate_quotes`.** The offer
store exists for things that can be spent twice — opaque tokens, expiry, atomic
consumption. A blind offer holds no token and expires never; it is an advertisement that
this source will sell a label. Inventing a `rate_quotes` row for the audit log would put
the fabricated price back one layer down.

- [x] `ShopifyAdapter` no longer implements the interface that could return a `RateResponse`
- [x] The offer is selectable by a human, with confirmation, and never ranked
- [x] No automated path can reach it — with or without the opt-in
- [x] A hard-required special service excludes Shopify, visibly
- [x] `ShopifyAdapterTest` and `RateSelectorTest` cover both exclusions

## Two things the split made visible

- **`AsyncRateQuoting` now extends `CarrierAdapterInterface`.** `prepareRateRequest()` may
  decline and the caller then asks `getRates()` for the same quote, so anything quoting
  asynchronously must also quote synchronously. It was previously true by luck.
- **The Ship page's Alpine highlight reads server state** through `$wire` rather than
  mirroring `selectedRateIndex`; with a second list able to take the selection away, two
  copies of "what is selected" would disagree the moment it did.

## Related

- `postage-source-split/08` — the adapter interface split this needed
- `10`, `11` — the service a blind purchase cannot report, and inferring it later
