# Quote Amazon Shipping for an off-Amazon Package

Status: needs-triage

Repo: `polybag`

## What to build

When `04` resolves an off-Amazon Amazon connection for a Package, `ShippingRateService`
asks that connection for rates with `channelType: EXTERNAL`, and the Ship page shows the
results as Offers alongside direct-carrier rates.

- Build the `EXTERNAL` rate payload from what `01` found: no Amazon order ID; whatever item,
  value or channel details it requires. Keep the existing `AMAZON` payload untouched.
- Offers are bound to the scoped connection as their source instance, the same way
  on-Amazon Offers bind to the originating connection; `OfferStore` must not re-derive the
  source from the Shipment's import source.
- Observed services from these responses are recorded through `ObservedServiceRecorder` as
  usual, so mapping and approval (ADR-0003) apply unchanged.
- Packaging compatibility filtering and quote logging apply as for other sources.
- An empty rate list (not enrolled, not eligible) is an absence of offers, not an error,
  matching how on-Amazon `getRates` is treated.

## Acceptance criteria

- [ ] A Shopify- or Database-imported Package with a scoped Amazon connection shows Amazon
      Shipping Offers on the Ship page
- [ ] Request payload asserted in tests: `channelType: EXTERNAL`, no order ID, fields per
      `01`; the vendored request schema validates it if one covers `EXTERNAL`
- [ ] Offer records name the scoped connection as source instance
- [ ] Observed services are recorded from `EXTERNAL` responses
- [ ] On-Amazon rating is unchanged (existing tests pass)

## Blocked by

- `01` — the `EXTERNAL` payload probe
- `04` — resolution of the scoped connection
