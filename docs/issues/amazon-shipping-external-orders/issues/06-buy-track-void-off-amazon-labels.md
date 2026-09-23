# Buy, print, track and void an off-Amazon Amazon Shipping Label

Status: done

Repo: `polybag`

## What to build

Redeeming an off-Amazon Amazon Shipping Offer through `PackageShippingWorkflow` buys the
Label with `channelType: EXTERNAL`, prints it, and records provenance so every later
operation goes back to the same connection.

- The Label's `postage_data_source_id` is the **scoped connection that sold the Offer**,
  not the Shipment's import source. For a Shopify-imported Shipment these differ, and
  confusing them would send tracking or cancellation to the wrong account (or to Shopify).
- Carrier of record is Amazon Shipping (`AMZN_US` / `std-us-swa-mfn` in the sandbox),
  normalized the same way on-Amazon purchases are. The `01` sandbox results apply:
  every format (PNG, ZPL at 203/300, PDF; 4×6) returns `LABEL` only, never a
  `PACKSLIP`, with file joining `false`. The existing `documentSpecification()` builds
  an accepted spec.
- Keep the tracking ID from the purchase response. The sandbox's
  `getShipmentDocuments` (reprint) returned a different one, so a reprint must never
  overwrite the Label's tracking ID.
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

- [x] End-to-end feature test: Shopify-imported Shipment → scoped Amazon connection → Offer
      → purchase → Label recorded with the scoped connection as postage source
- [x] Tracking and void dispatch to the recorded connection (tests with a faked connector)
- [x] Fulfillment update to the originating Shopify connection carries the Amazon Shipping
      tracking number
- [x] Manifest behavior decided and tested
- [x] Label printing works for the formats `01` observed (PNG/PDF/ZPL)
- [x] Reprint leaves the tracking ID unchanged. *Amended 2026-09-23:* it reprints the label
      bytes stored at purchase, as every other source does, rather than fetching through
      `getShipmentDocuments`. Nothing is asked of Amazon, so there is no second tracking ID
      to overwrite the first with

## Blocked by

- `05` — quoting off-Amazon Offers

## Comments

### 2026-09-23 — verified, no production code changed

Everything this issue asks for was already in place. `05` bound the Offer to the scoped
connection, and every later step reads the Offer or the Package's recorded provenance, never
the Shipment's import source. `tests/Feature/OffAmazonShippingPurchaseTest.php` proves it
end to end, through the real workflows with Saloon faking Amazon and Shopify:

- **Purchase.** `AmazonBuyShippingService::sellingSourceFor()` buys on the Offer's
  connection, and the adapter records `postage_data_source_id` from the Offer. A Shopify
  Shipment's Label names the Amazon connection. Carrier of record is `Amazon Shipping`
  (`AMZN_US` / `std-us-swa-mfn` in metadata). No `Carrier` row matches it, so
  `normalized_carrier_id` stays null, as it does for on-Amazon OnTrac. The test fails if
  the adapter records the import source instead.
- **Formats.** `documentSpecification()` reads joining and document types off the chosen
  print option, so an `EXTERNAL` rate is bought `needFileJoining: false` with
  `requestedDocumentTypes: ["LABEL"]`. PDF workstations get PNG, ZPL is bought at 203 or
  300 as the device prints, and PDF is bought only when nothing else is offered. Each body
  validates against the vendored `PurchaseShipmentRequest` schema.
- **Tracking and void** go through `PostageSourceDispatcher` → `AmazonPostageSource` →
  `postageSourceFor()`, which reads the recorded connection. Tracking still reaches that
  connection after its scope is deleted. A void keeps the Label as voided history and
  returns the Package to unshipped.
- **Fulfillment.** The Shopify export has no Amazon gate (only a Shopify Shipping label
  suppresses it), so `fulfillmentCreate` carries the `TBA…` number, and no
  `confirmShipment` goes to Amazon.
- **End of Day.** Excluded. `AmazonPostageSource::supportsPackageManifest()` is false,
  `getUnmanifestedPackages()` only takes carrier-account Labels, and `createManifest()`
  refuses one. Amazon Shipping tenders on Amazon's account, so there is no SCAN form of
  ours to make.
- **Recovery.** A spent Offer with no reply is re-sent with the same idempotency key on the
  same connection, and the Package ships once.

The off-Amazon fixtures (`amazonShippingGroundRate()`, `externalRatesResponse()`,
`externalPackage()`) moved from `OffAmazonShippingQuoteTest` into `tests/Pest.php` so both
files can use them under Paratest.

**Tracking company, confirmed 2026-09-23.** Shopify receives `trackingInfo.company:
"Amazon Shipping"`, which `ShopifySource::CARRIER_MAP` passes through untranslated. A manual
test on the dev store (sandbox purchase on a Shopify-imported order) showed Shopify accepts
it, and the Shopify admin shows a working tracking link. No mapping is needed.
