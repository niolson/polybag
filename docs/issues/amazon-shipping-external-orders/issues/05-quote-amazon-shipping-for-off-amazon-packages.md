# Quote Amazon Shipping for an off-Amazon Package

Status: needs-triage

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

- [ ] A Shopify- or Database-imported Package with a scoped Amazon connection shows Amazon
      Shipping Offers on the Ship page
- [ ] Request payload asserted in tests: `channelType: EXTERNAL`, no order ID, fields per
      `01`; the vendored request schema validates it if one covers `EXTERNAL`
- [ ] Offer records name the scoped connection as source instance
- [ ] Observed services are recorded from `EXTERNAL` responses
- [ ] Item weights are capped so their total fits the package weight; tested with
      product weights that overshoot it
- [ ] `403 A-101` yields no offers and the not-enabled message, not an exception
- [ ] On-Amazon rating is unchanged (existing tests pass)

## Blocked by

- `01` — the `EXTERNAL` payload probe
- `04` — resolution of the scoped connection
