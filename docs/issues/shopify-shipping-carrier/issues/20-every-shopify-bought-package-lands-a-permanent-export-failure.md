# Every Shopify-bought package lands a permanent export failure

Status: done — 2026-09-09

Repo: `polybag`

## Problem

`ShopifySource::exportPackage()` swallowed one error message and one only — `already
fulfilled` — and threw `PermanentExportException` on anything else.

**Shopify does not say "already fulfilled" here.** When a label bought through
`shippingLabelPurchase` has already produced a fulfillment, Shopify closes the fulfillment
order and the export's `fulfillmentCreate` comes back with *"Fulfillment order … has an
unfulfillable status= closed."* The guard missed, and the package's `PackageExport` row was
written `permanently_failed` with `exported` left `false`.

**This is not an edge case — it is every Shopify-bought package.** Shopify creating the
fulfillment itself is the normal path (`01` question 3), so the condition holds for every
purchase, and the correct outcome was recorded as a permanent failure needing attention.

Shipped code and its docblock both claimed the export "degrades safely". The branch was
inferred from the fulfillment existing, and never run.

## What shipped — option three: do not export at all

**The fix does not read the message.** `exportPackage()` returns early when the package
carries a `shopify_shipping_label_id`, before the credential check: Shopify sold the label,
Shopify created the fulfillment, there is nothing to tell it. Three small changes —
`ShopifyAdapter::shippingLabelIdFor()` mirroring `AmazonBuyShippingAdapter::shipmentIdFor()`,
`PackageExportService` passing it as `_shopify_shipping_label_id`, and the skip itself. This
is the shape `AmazonSource::exportPackage()` has had all along for Buy Shipping labels; the
Shopify path never got it and the prose guard was standing in for it.

**Matching a code instead was never available.** `fulfillmentCreate` returns the *base*
`UserError` type, whose only fields are `field` and `message`. Any guard on this mutation
reads prose Shopify controls, which is exactly how the shipped one broke.

**Matching the real message too was considered and rejected**, not merely skipped. That
message is not unique to this cause: a package shipped on one of *our own* carrier accounts,
whose stored fulfillment order has since been closed and replaced (`18`), gets the identical
reply — and there the export has genuinely failed. Swallowing on the message would convert a
real failure into a silent success. The label ID separates the two cases exactly; the
message cannot. There is a test for each.

**Gated on the label ID rather than `postage_source`, deliberately.** What matters is that
*Shopify* bought this label. It also fails in the right direction on a void: `applyVoid()`
strips the marker, so a package voided and re-shipped on a carrier account exports again.

- [x] A Shopify-bought package records no `permanently_failed` export
- [x] Its `exported` state ends up truthful — the skipped export records `Succeeded`, so the
      operator sees an ordinary exported package rather than a failure needing attention.
      Honest here: the export exists to tell the channel what shipped, and the channel wrote
      the tracking number itself
- [x] A genuine export failure still records as a failure
- [x] A test covers the exact `unfulfillable status= closed` message

**Existing rows heal themselves** — `php artisan packages:export --retry-permanent` reopens
a `permanently_failed` row and the retry now hits the skip. One command, not a migration.

## Comments

- **2026-09-09** — found while answering `01`'s question 4 (is the customer notified
  twice?). The answer was *no*, and **the reason was this bug**: the second call site could
  never succeed, so there was never a second notification to send. An option-one or -two fix
  that made `fulfillmentCreate` succeed would have **reopened** that question, because both
  call sites would then run with `notifyCustomer` true. Option three keeps the answer true
  for the right reason — the second call site is never reached.

## Related

- `01` — question 3, whose recorded inference this corrects, and question 4
- `18` — the fulfillment order churn that produces the closed order
