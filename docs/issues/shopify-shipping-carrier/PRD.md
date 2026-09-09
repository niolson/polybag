# Shopify Shipping as a PolyBag carrier

Status: reference

Background for the issues in this directory. Not a work item.

## Why

A shop without a USPS Negotiated Service Agreement can still reach **USPS Connect
eCommerce (CeC)** rates by buying postage on its Shopify account. That pricing is the
entire motivation; Shopify integration for its own sake is not.

## What the API actually is

Verified by introspecting the live schema on a development store (2026-08-29 and
2026-08-31), not read from documentation. Both `2026-07` and `unstable` expose
**exactly two** shipping-label operations:

- `shippingLabelPurchase` mutation
- `shippingLabel(id:)` query

Everything below follows from that being the whole surface.

| Capability | Status |
|---|---|
| Rate quoting | **Does not exist** on any version. No rates query at all. |
| Buying a label | `shippingLabelPurchase`, asynchronous — poll `node(id:)` until `PURCHASED` / `PURCHASE_FAILED` |
| Label cost | Not on the label. In the order's event stream as a prose string, and via Shopify Payments balance transactions — see `05` |
| Voiding | **No mutation.** `cancellable` is readable; voiding happens in the Shopify admin |
| PDF vs ZPL | **Reported, not requested** — the purchase input has no format field. In practice **always PDF**: the shop's label format setting selects a paper size (Thermal 4×6, Letter, A4), all of them PDF, so nothing merchant-facing reaches the `ZPL` value. Verified against the live schema 2026-09-09, see `01` |
| FedEx | Not supported. Carrier codes are `usps`, `ups_shipping`, `dhl_express`, `canada_post` |

`ShippingLabel` has six fields and no price: `id`, `trackingInfo`, `shippingDocuments`,
`cancellable`, `printed`, `location`. Each `ShippingObjectsShippingDocument` carries
`documentType` (`LABEL` / `CUSTOMS_FORM`), `format` (`PDF` / `ZPL`), `url`, `printedAt`.

Purchases key off a **fulfillment order ID**, so only shipments imported from a Shopify
data source are eligible — Manual Ship and non-Shopify channels are out by construction.

## What shipped in `polybag`

Built 2026-08-29 to 2026-08-31, on `main`, tests green:

- `ShopifyAdapter` — a real `Carrier` + `CarrierService`, not a bespoke Ship-page
  button, so shipping-method mapping reaches it through the path it already uses.
  **Half superseded 2026-09-04 by `09`:** the catalog rows and the shipping-method
  mapping stay; shipping rules, auto-ship and batch ship no longer reach it, because
  ADR-0003 decision 5 excludes blind purchase from every automated path
- `ShopifyShippingLabelService` — purchase, poll, download, and void detection
- `ShopifyLabelVoidSynchronizer` + `packages:sync-shopify-voids`, scheduled every 15
  minutes
- `RateResponse::$priceUnknown` — Shopify rates display as "Price set at purchase" and
  sort behind every real quote, so a `$0.00` row can never win "cheapest" and silently
  reroute all shipping. **Superseded 2026-09-04 by `09`:** ADR-0003 decision 6 rejects
  modelling this as a rate at all. Shopify now returns a `BlindPurchaseOffer`, listed
  apart from the rates and reachable by no automated path; `priceUnknown` survives on
  `RateResponse` for sources that quote a rate whose price is not yet fixed
- UI: warning badge on the package page, disabled Void button with explanation, carrier
  column reading `Shopify` with *"via <actual carrier>"* beneath

Two decisions worth restating because they look like omissions:

**`packages.cost` is left null, not `0.00`.** Shopify reports no price, and a fabricated
zero would read as a free label everywhere cost is totalled. See `08` for the reporting
consequence.

**The carrier of record is always `Shopify`**, with whatever Shopify actually picked
stored in `packages.service` and `packages.metadata.shopify_tracking_company`.
(Both halves of that sentence were reversed later: `postage-source-split/11` and `10`
put the picked carrier in the carrier column and left the service null.) This is
the catch-all for Shopify choosing a carrier PolyBag has no account or catalogued
service for. It also defends against `preferredRateSelection` being ignored — see `02`.

## What blocks verification

The purchase path is complete and runs live end to end, stopping at:

```
TERMS_OF_SERVICE_NOT_ACCEPTED — Shopify Shipping terms of service have not been
accepted. Purchase a shipping label through the Shopify admin first.
```

Somebody has to buy one label in the **Shopify admin** before the API will ever sell
one. That gate is `01`, and it holds up every remaining unknown.

**Cleared 2026-09-08** with a UPS label bought in the admin. On a development store only
UPS sells a label, and what it sells is a test label — free, but never a moving parcel.
That leaves the CeC pricing this feature exists for unverified until a real store is
available. See `01`.
