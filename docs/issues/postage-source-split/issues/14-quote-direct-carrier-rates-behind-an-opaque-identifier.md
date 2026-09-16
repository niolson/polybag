# Quote direct-carrier rates behind an opaque identifier, restored server-side at purchase

Status: needs-triage

Repo: `polybag`

## Parent

[ADR-0002](../../../adr/0002-postage-source-split.md) decision 4 gave an Amazon or
Shopify offer an opaque `offerId`, with everything that can buy the label kept on the
`ShippingOffer` row so that the browser names an offer and restates nothing. Direct
carrier rates — USPS, FedEx, UPS quoted from a `CarrierAccount` — got no such row, because
no purchase authority is issued at quote time and there was nothing to protect.

Raised by code review of `packaging-form-and-carrier-identity/01` (2026-09-16), twice.

## Problem

A direct-carrier rate crosses the Ship page as Livewire state and comes back through
`RateResponse::fromArray()`. Everything the purchase then sends is restated by the
browser: carrier, service code, price, and the metadata the adapter reads with no
fallback — USPS's `mailClass` / `rateIndicator` / `processingCategory`, FedEx's
`serviceType` / `isOneRate` / packaging, UPS's `serviceCode`. `RateQuoteLogger::markSelected()`
matches the logged quote on carrier and service code only, so it cannot tell USPS
variants apart and cannot be used as the server's copy.

Two consequences today, neither new:

- The recorded cost is whatever the browser said the price was.
- Any label the account can buy is buyable by editing state, whether or not it was
  ever quoted for this Package.

And one that ADR-0005 makes sharper. `CarrierAdapterInterface::packagingRequirementFor()`
classifies a rate's packaging requirement from the same metadata the ship body sends, so
the purchase-time packaging check is *consistent* — no metadata can classify as the
shipper's packaging while buying the carrier's — but not *authoritative*: it re-derives
from browser state rather than from something the server quoted. The reviewer's remedy
was to persist, cache or sign the quoted rate behind an opaque identifier and classify
the server-restored rate, which is exactly what `rateFromOffer()` already does for an
offer.

## Shape

Extend the offer model to direct carriers: every rate `ShippingRateService` returns gets
a server-side row (a `ShippingOffer` with no purchase authority, or a sibling table) and
an opaque id; the browser sends the id; `buyPostage()` restores carrier, service, price
and metadata from the row exactly as it does for an Amazon offer, and the packaging check
runs on the restored rate. `markSelected()` then matches on the id rather than on carrier
and service code.

Open questions for triage:

- Whether `ShippingOffer` grows a "quoted, no authority" state or a lighter row is
  cleaner. `OfferStore::inspect()` / `redeem()` carry consumption semantics a plain
  quote does not need.
- Expiry. A direct rate is good for the day; an Amazon offer is good for minutes.
- Batch ship and auto-ship never round-trip through the browser, so they need no id —
  but the purchase path is shared, so the restore must be optional or the automated
  paths must issue rows too.
- Retention: `rate_quotes` is already a per-package log; two tables recording every
  quote is one too many.

## Not this issue

- The packaging check's *consistency* invariant — that each classifier reads exactly the
  fields the ship body sends and refuses unknown ones — lives on the interface docblock
  and is `packaging-form-and-carrier-identity/05`'s to honour for USPS.
- Blind purchases (`blindPurchaseOffersFor()`), which already carry no rate.

## Blocked by

None; not scheduled. Worth doing before or alongside
`packaging-form-and-carrier-identity/05`, which is when a direct classifier first returns
something other than `shipperPackaging()`.
