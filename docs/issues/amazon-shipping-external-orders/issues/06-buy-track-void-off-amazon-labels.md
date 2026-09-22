# Buy, print, track and void an off-Amazon Amazon Shipping Label

Status: needs-triage

Repo: `polybag`

## What to build

Redeeming an off-Amazon Amazon Shipping Offer through `PackageShippingWorkflow` buys the
Label with `channelType: EXTERNAL`, prints it, and records provenance so every later
operation goes back to the same connection.

- The Label's `postage_data_source_id` is the **scoped connection that sold the Offer**,
  not the Shipment's import source. For a Shopify-imported Shipment these differ, and
  confusing them would send tracking or cancellation to the wrong account (or to Shopify).
- Carrier of record is Amazon Shipping (or whatever `01` found the response names),
  normalized the same way on-Amazon purchases are.
- `PostageSourceDispatcher` routes tracking and void for these Labels to the recorded
  connection. A void retains Label history and returns the Package to unshipped.
- Fulfillment write-back to the Shipment's originating channel (e.g. Shopify fulfillment
  with tracking) happens through the existing export path, not through Amazon — confirm and
  test that an Amazon-bought Label on a Shopify Shipment reports tracking to Shopify.
- End of Day: record whether Amazon Shipping labels need a manifest; if not, confirm they
  are excluded from SCAN forms as other non-direct Labels are.
- Purchase recovery: an unresolved purchase follows the existing recovery path for Amazon
  Offers.

## Acceptance criteria

- [ ] End-to-end feature test: Shopify-imported Shipment → scoped Amazon connection → Offer
      → purchase → Label recorded with the scoped connection as postage source
- [ ] Tracking and void dispatch to the recorded connection (tests with a faked connector)
- [ ] Fulfillment update to the originating Shopify connection carries the Amazon Shipping
      tracking number
- [ ] Manifest behavior decided and tested
- [ ] Label printing works for the formats `01` observed (PNG/PDF/ZPL)

## Blocked by

- `05` — quoting off-Amazon Offers
