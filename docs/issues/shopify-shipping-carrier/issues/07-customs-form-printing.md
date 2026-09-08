# International Shopify labels return a customs form PolyBag cannot print

Status: needs-triage

Repo: `polybag`

## Problem

A Shopify purchase returns its documents as a list. The label is
`documentType: LABEL`; an international shipment also gets
`documentType: CUSTOMS_FORM` as a **separate document with its own URL**.

PolyBag stores one label per package (`packages.label_data`) and prints that. There is
nowhere for a second document to go, so `ShopifyShippingLabelService` keeps only the
customs form's URL in `packages.metadata.shopify_customs_form_url` and drops the file.

The practical consequence: for an international Shopify label, somebody opens Shopify and
prints the customs form by hand. That sits exactly where this feature is most attractive
— DHL eCommerce and Canada Post are on the supported carrier list, and USPS CeC covers
international.

The URL is deliberately stored rather than the file: downloading a document PolyBag has
no way to print would waste the transfer and bloat the row for nothing.

## Options

- **Surface the URL** in the package UI as a link, so the manual step is at least
  discoverable rather than buried in metadata. Cheapest useful move.
- **Print it as a second document** through QZ Tray, which means a real decision about
  how PolyBag models multi-document labels — this is not Shopify-specific, and other
  carriers' customs forms would benefit.
- **Restrict Shopify Shipping to domestic** via the carrier-service catalogue until one
  of the above lands.

## First

Confirm in `01` that an international purchase really does return a second document, and
what format it comes back in. Everything above assumes it does.

## Comments

### 2026-09-08 — the confirming step is now free; fold it into the campaign

"First: confirm in `01` that an international purchase really does return a second
document" was written when a purchase cost real postage. It does not any more — on a
development store these are test labels — so the precondition this issue has been waiting
on is one international test order away.

Both purchases in `01` so far were domestic, which is why nothing here has moved. Create an
international test order on the same store, buy a label through PolyBag, and record whether
a `CUSTOMS_FORM` document comes back and in what format. That is `01`'s question 6, and it
is the whole gate on this issue.

**Do not choose between the three options above before that observation exists.** Each one
assumes a second document; the format it arrives in also decides whether the middle option
is even reachable through QZ Tray. Sequenced after the campaign, not during it.
