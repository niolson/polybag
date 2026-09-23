# Quote Amazon Shipping for an off-Amazon Package

Status: done

Repo: `polybag`

## What to build

When `04` resolves an off-Amazon Amazon connection for a Package, `ShippingRateService`
asks that connection for rates with `channelType: EXTERNAL`, and the Ship page shows the
results as Offers alongside direct-carrier rates.

- Build the `EXTERNAL` rate payload from what `01` found. Keep the existing `AMAZON`
  payload untouched. The `EXTERNAL` body is the `AMAZON` package builder with these
  differences:
  - `channelDetails` is `{"channelType": "EXTERNAL"}`. `amazonOrderDetails` must be
    absent; sending it is a `400 D-722`.
  - `items` comes from the Shipment's own items, not Amazon order lines, and needs at
    least one entry. `itemIdentifier`, `itemValue` and `description` are optional, so
    send them when known.
  - Every item needs a `weight`; `0` is accepted. The items' total (`weight × quantity`)
    must not exceed the package weight, or the request fails with `400 D-703`. Item
    weights come from product records, which are known to be wrong at times, so scale
    or cap them so the total fits the scanned package weight. Never raise the package
    weight to fit the items.
  - `insuredValue` is required. `0` is accepted and stays the default, as on-Amazon.
  - One package per request.
- Offers are bound to the scoped connection as their source instance, the same way
  on-Amazon Offers bind to the originating connection; `OfferStore` must not re-derive the
  source from the Shipment's import source.
- Observed services from these responses are recorded through `ObservedServiceRecorder` as
  usual, so mapping and approval (ADR-0003) apply unchanged.
- Packaging compatibility filtering and quote logging apply as for other sources.
- An empty rate list is an absence of offers, not an error, matching how on-Amazon
  `getRates` is treated. An account that has not signed up for Amazon Shipping does
  **not** return an empty list. It returns `403 A-101` (`01`). Treat that as "this
  connection is not enabled for off-Amazon shipping": no offers from it, a message that
  says so, and the connection marked not enabled per `04`. Never a generic Amazon error.

## Acceptance criteria

- [x] A Shopify- or Database-imported Package with a scoped Amazon connection shows Amazon
      Shipping Offers on the Ship page
- [x] Request payload asserted in tests: `channelType: EXTERNAL`, no order ID, fields per
      `01`; the vendored request schema validates it if one covers `EXTERNAL`
- [x] Offer records name the scoped connection as source instance
- [x] Observed services are recorded from `EXTERNAL` responses
- [x] Item weights are capped so their total fits the package weight; tested with
      product weights that overshoot it
- [x] `403 A-101` yields no offers and the not-enabled message, not an exception
- [x] On-Amazon rating is unchanged (existing tests pass)

## Blocked by

- `01` — the `EXTERNAL` payload probe
- `04` — resolution of the scoped connection

## Comments

### 2026-09-22 — implemented

- **Which connection.** `AmazonBuyShippingService::quotingSourceFor()` answers for both
  channels: an Amazon order's own connection (only with its order ID), otherwise
  `PostageSourceResolver::offAmazonShippingSourceFor()`. It is asked once, when the
  request is prepared. `parseRateResponse()` reads the connection and the channel off the
  request that was sent (the connector's connection id and the body's `channelType`), so a
  scope edited or a connection switched off while the request is in flight cannot move the
  reply, its Offers or its A-101 onto another connection. Every Offer names that
  connection as its source instance. A Shopify Shipment's Offer names the Amazon connection, never the Shopify one.
- **When Amazon is asked.** As on-Amazon: when the Shipment's shipping method includes the
  `Amazon` catalog row, or when it has no shipping method. A connection scope decides
  *which* connection sells. The shipping method still decides *whether* Amazon is asked.
- **Body.** `buildOffAmazonRatePayload()` is the on-Amazon package builder, with items from
  the packed `PackageItem`s, sent through `01`'s `buildExternalRatePayload()`. Each item
  carries the product's description or name, its SKU as `itemIdentifier`, and the shipment
  line's value where these are known. A missing product weight is sent as 0. Weights
  whose `weight × quantity` total exceeds the package weight are scaled down in proportion
  and floored to the hundredth, so the total never exceeds the scanned weight. The package
  weight is never raised. A Package with nothing packed is sent as one weightless
  "Merchandise" item. Validated against the vendored `GetRatesRequest` schema.
- **Naming.** Rates are named the way on-Amazon rates are: the carrier Amazon names
  (`Amazon Shipping`), or the carrier service an observed-service mapping gives it.
- **A-101.** The adapter re-uses `OffAmazonShippingCheck::refusesAccount()`. It throws
  `CarrierUnavailableException`, and `ShippingRateService` now records that as an exclusion
  on all three quoting paths, so the Ship page shows "Amazon excluded" naming the
  connection and the sign-up step. The connection is marked `not_set_up`. A successful
  production quote marks it `enabled`, which clears a stale refusal once the seller has
  signed up. Sandbox quotes record nothing, for the reason the check skips the sandbox.
- **Two transport fixes needed on the way.** The connector retries three times and then
  throws on any 4xx, and on the concurrent path Guzzle turns a 4xx into a rejected promise.
  Either way the adapter never saw the A-101 body. `GetShippingRates` now retries only
  on 5xx, 429 or a dropped connection, and the adapter sends it with `http_errors` off so a
  refusal arrives as a response on both paths. As a side effect, an on-Amazon `400` on the
  single-source synchronous path now produces no offers and a log line. It used to throw
  out of rate shopping.

Not covered here: buying an off-Amazon Offer. The purchase path would already use the
Offer's connection (`sellingSourceFor()`), but nothing has verified it end to end. That is
`06`.
