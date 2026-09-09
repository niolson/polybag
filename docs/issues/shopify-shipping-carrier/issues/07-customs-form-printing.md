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

~~Confirm in `01` that an international purchase really does return a second document, and
what format it comes back in.~~ **Done 2026-09-09 — it does.** PDF, but three Letter pages
rather than a 4×6 label. The gate on this issue is cleared and the options above are now
decidable; see the comment of that date, which argues the format changes their relative cost.

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

### 2026-09-09 — confirmed, and the format decides more than expected

`01`'s question 6 is answered. An international purchase to a Canadian destination returned two documents,
and the second one is not a label:

| | `LABEL` | `CUSTOMS_FORM` |
|---|---|---|
| Format | PDF | PDF |
| Pages | 1 | **3** |
| Page size | 288 × 432 pts (4×6) | **612 × 792 pts (Letter)** |
| Text layer | none — a bitmap | text-bearing, four embedded fonts |

It is a UPS commercial invoice: waybill number, both parties with tax-ID and EORI fields,
Incoterm `DDU`, reason for export `SALE`, and the line items.

**This settles the middle option's real cost.** "Print it as a second document through QZ
Tray" was written as a modelling question about multi-document labels. It is also a
**hardware** question: three Letter pages cannot go to the 4×6 thermal printer the label goes
to, so this needs a second printer configured per workstation, or a document printer that
Device Settings does not currently model. That is a materially larger change than the framing
above implies, and it is not Shopify-specific — a FedEx or USPS customs form has the same
shape.

**The cheapest option is now cheaper by comparison.** Surfacing the URL as a link in the
package UI leaves the operator printing from a browser to whatever printer they already use
for paper, which is the workflow the document actually wants.

The third option — restrict Shopify Shipping to domestic — reads worse than it did. The
international path works and returns a correct commercial invoice; withdrawing it would give
up a working capability to avoid a printing problem that exists for our own carriers too.

**A precondition worth recording here**, because it bites before any of this: an
international Shopify purchase fails unless every variant has `harmonizedSystemCode` and
`countryCodeOfOrigin` set in the Shopify catalogue, and those **cannot** be sent in the
purchase — `ShippingLabelPurchaseInput` has no customs fields. It also fails when the box
weighs less than Shopify's declared item weights, which is `19`. Both surface after packing.
