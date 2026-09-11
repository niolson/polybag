# Shopify Shipping as a PolyBag carrier

Status: reference

Background for the issues in this directory. Not a work item.

## Why

A shop without a USPS Negotiated Service Agreement can still reach **USPS Connect
eCommerce (CeC)** rates by buying postage on its Shopify account. That pricing is the
entire motivation; Shopify integration for its own sake is not.

## What the API is

Introspected against the live schema on a development store (2026-08-29, 2026-08-31,
2026-09-09), not read from documentation. Both `2026-07` and `unstable` expose **exactly
two** shipping-label operations — `shippingLabelPurchase` and `shippingLabel(id:)` — and
everything below follows from that being the whole surface.

| Capability | Status |
|---|---|
| Rate quoting | **Does not exist.** No rates query on any version. A purchase probe sent with a past ship date reports whether a rate *matched* without buying — see `02` |
| Buying a label | `shippingLabelPurchase`, asynchronous — poll `node(id:)` until `PURCHASED` / `PURCHASE_FAILED`. Keys off a **fulfillment order ID**, so only Shopify-imported shipments are eligible |
| Choosing a service | `preferredRateSelection` **is honoured** (`02`). Each carrier's own vocabulary passed through — USPS in Shopify's PascalCase, UPS's numeric codes, DHL's letter codes. 17 pairs seeded |
| Label cost | Not on the label. Written into the order timeline as prose — *"PolyBag purchased a shipping label for $5.68."* — readable via `Order.events`. See `05` |
| Voiding | **No mutation.** Voiding happens in the Shopify admin, and it closes the fulfillment order permanently and creates a replacement — see `18` and `21` |
| PDF vs ZPL | **Always PDF.** The purchase input has no format field, and the shop's label format setting is a *paper size* applied at print time that never reaches the document at `shippingDocuments[].url`. What PolyBag downloads is always 4×6 |
| Customs | International purchases return a separate `CUSTOMS_FORM` document — PDF, three Letter pages. HS code and country of origin must already be on the Shopify catalogue; they cannot be sent in the purchase |
| Carriers | Shopify sells through ~19. Documented codes are `usps`, `ups_shipping`, `dhl_express`, `canada_post`. **FedEx is not among them** |

`ShippingLabel` has six fields and no price: `id`, `trackingInfo`, `shippingDocuments`,
`cancellable`, `printed`, `location`. Each `ShippingObjectsShippingDocument` carries
`documentType` (`LABEL` / `CUSTOMS_FORM`), `format` (`PDF` / `ZPL`), `url`, `printedAt`.

## What shipped in `polybag`

- **`ShopifyAdapter`** — a `BlindPurchaseSource`, not a rate source. It returns a
  `BlindPurchaseOffer` listed apart from the rates, behind a per-client opt-in and an
  explicit confirmation, and reachable by no automated path (ADR-0003 decisions 5 and 6,
  `09`). Its catalog rows and shipping-method mapping remain.
- **`ShopifyShippingLabelService`** — purchase, poll, download, void detection, and the
  pre-purchase declared-weight check (`19`).
- **`ShopifyFulfillmentSynchronizer`** + `packages:sync-shopify-fulfillments`, every 15
  minutes — detects a Shopify-side void, un-ships the package, and re-points the shipment
  at the replacement fulfillment order.
- **Service inference at purchase time** (`11`) — the ladder runs inside the purchase, so
  a Shopify package carries an `inferred` service with its method and ruleset version
  where a rung concludes, and `unknown` where none does.
- **UI** — warning panel and confirmation modal on the Ship page, disabled Void button,
  the carrier Shopify picked in the carrier column.

Two decisions that look like omissions and are not:

**`packages.cost` is left null, not `0.00`.** A fabricated zero would read as a free label
everywhere cost is totalled. `08` fixed the reporting consequence; `12` carries the
billing one.

**The carrier of record is the carrier Shopify picked**, translated from Shopify's
internal code (`ups_shipping` → UPS) through `ShopifyShippingLabelService::CARRIER_NAMES`,
with the raw string kept in `metadata.shopify_tracking_company`. The service is not
Shopify's to report, so it is inferred or left null — never filled with a carrier name.

## What is verified, and what it rests on

**The terms of service gate is cleared** — accepted 2026-09-08 by buying one label in the
Shopify admin. Purchases through the API have run end to end since.

**The API sells USPS on a development store, even though that store's admin will not.**
Shopify support's line that only UPS supports test labels describes the admin's buy-label
flow; `shippingLabelPurchase` sold USPS Ground Advantage on the first `auto` purchase and
has sold USPS repeatedly since.

**Every label bought so far is a test label** — free, marked `Test: True` in the order
timeline, registered with the carrier and trackable, but never a moving parcel. USPS test
labels come back as USPS's own `SAMPLE - DO NOT MAIL` artwork.

**The pricing premise holds.** The label prices in the order timeline come in below USPS
list commercial rates and match what Pirate Ship and Veeqo quote for the same parcels — two
independent resellers on the same discounted tier. That is the economic claim this feature
rests on: a shop reaches rates here it could not reach on its own account without an NSA.

**Which USPS programme is behind that discount is not worth chasing.** CeC is the working
assumption and the prices are consistent with it; nothing about the integration changes if
it turns out to be another commercial tier those platforms also reach. The rates are the
rates, and they can be documented precisely if a reason ever appears.

**One caveat left, and it is cheap to close.** Every price observed so far is from a
development store. Nothing suggests Shopify's rate engine varies by store type — and this
store already sells USPS through the API where its own admin refuses to — but the first
label bought on a production store settles it for the cost of reading the order timeline.
`17` asks for that.

What still waits on a real store is **everything that needs a parcel to physically move** —
scan events, whether `displayStatus` advances, and whether USPS accepts a Shopify-bought
IMpb on a SCAN form we create. That is `17`, and pricing is no longer part of it.
