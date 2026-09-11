# Buy the first live Shopify Shipping label and settle what comes back

Status: done — 2026-09-09

Repo: `polybag`

## What this was

Every unknown about Shopify Shipping was gated behind one manual act: buying a label
through the **Shopify admin**, which is how a shop accepts the Shopify Shipping terms of
service. Until that happened the API answered every purchase with
`TERMS_OF_SERVICE_NOT_ACCEPTED`.

**The gate was cleared 2026-09-08** with a UPS label bought in the admin. Ten questions
followed; seven were answered here, three moved to `17`.

## The answers

| | Question | Outcome |
|---|---|---|
| 1 | PDF or ZPL | **PDF**, both carriers — and `01` later established there is no ZPL setting to reach |
| 2 | ZPL DPI | **Dissolved.** No ZPL setting exists; restated as page size, and the API document is always a true 4×6. No code change |
| 3 | Does the purchase create the fulfillment | **Yes**, `status: SUCCESS`, `displayStatus: FULFILLED` — and the export does *not* degrade safely, `20` |
| 4 | Is the customer notified twice | **No**, one notification — and Shopify schedules it for the `shippingDatetime` we send |
| 5 | What `trackingInfo.company` reads | Shopify's **internal carrier code**, `ups_shipping`. `16`, fixed |
| 6 | Customs form on an international order | **Yes**, a separate `CUSTOMS_FORM` — three Letter pages, not a label |
| 7, 8, 10 | Movement, `displayStatus`, SCAN forms | Moved to `17` — they need a parcel that physically moves |
| 9 | Midnight `shippingDatetime` | **Accepted**, and the customer notification is scheduled for it |

Five issues came out of it: `15` and `16` (both fixed the same day), `19`, `20` and `21`.

## What the store can and cannot do

`polybag-test` is a development store (`partnerDevelopment: true`), and what that means is
narrower than Shopify support's answer suggests.

**The admin's buy-label flow sells UPS only** — USPS and FedEx both fail there with a
generic "something went wrong", and support's explanation is that only UPS supports test
labels. **That does not hold for the API**: `shippingLabelPurchase` sold USPS Ground
Advantage on the first `auto` purchase and has sold USPS repeatedly since. FedEx is
irrelevant either way — Shopify Shipping does not sell it through this API at all.

**Every label bought here is a test label**: free, `Test: True` in the order timeline,
registered with the carrier and trackable (UPS's own tracking page recognised one, showed
it as *label created*, and moved it to *cancelled* after the void), but never a moving
parcel. USPS test labels come back as USPS's own `SAMPLE - DO NOT MAIL` artwork; UPS's carry
no visible marking.

So the cost objection this issue was written under does not apply. **A test label still
carries a real price**, written into the order timeline, and that turned out to be enough to
settle the pricing question this issue could not: the prices come in below USPS list
commercial and match Pirate Ship's and Veeqo's for the same parcels. See the PRD for what
that does and does not establish.

## Findings that outlived the questions

**The purchase was on Shopify's account, not ours.** Worth stating because the first label
came back USPS Ground Advantage, which looks at a glance like PolyBag's own USPS account
answering. `postage_source: postage_data_source`, `carrier_account_id: null`, the label
served from Shopify's S3 bucket, `cost: null` as designed.

**`trackingInfo.company` reads differently on the two nodes**, and in different cases:
`usps` on the `ShippingLabel`, `USPS` on the `Fulfillment`, `UPS®` on a `Fulfillment` for a
UPS label. Anything comparing these strings must fold case. `16` covers the translation.

**The lifecycle does not start where the mapping's reader would expect.** A purchase lands
at `FULFILLED`, not `LABEL_PURCHASED`. The stored `tracking_status` is right because
`FULFILLED => PreTransit` is already in the table, but the assumption that the lifecycle
*starts* at `LABEL_PURCHASED` is wrong.

**`Fulfillment.events` is not structurally empty** — a purchase produces one node,
`LABEL_PURCHASED`, with `happenedAt` and the origin city/province/postcode. Whether it ever
carries scan-level movement is `17`.

**The void path ran end to end**, un-prompted by any test: both labels voided in the admin
were picked up by the next scheduled `packages:sync-shopify-fulfillments` run, twelve and
sixteen minutes later, and both packages returned to `unshipped` with tracking, carrier,
provenance and label identifiers cleared and an audit row recorded. That closed the untested
assumption in question 3 — shipped code that had never once run against a real void.
`metadata.shopify_tracking_company` and `shopify_requested_service_code` survive a void by
design; nothing reads them, so they describe a label that no longer exists rather than
holding a live reference.

**The price is in the order timeline**, as prose — *"PolyBag purchased a shipping label for
$5.68."* That corrected the PRD's claim that cost is reachable only through Shopify Payments
balance transactions. Recorded on `05`.

**The purchase archives the order.** Worth knowing before an import filter meets it.

**The label format setting never touches what PolyBag downloads.** Two admin prints of one
label at Thermal 4×6 and at Letter carry the identical image object, so they are two renders
of one label; the API document is 288×432 pts — a genuine 4×6 page, not a Letter page with a
label parked in a corner. The setting is applied at print time. One caveat kept deliberately:
this is one API document, and if a shop is ever found whose API documents come back Letter,
that assumption is what broke.

**UPS labels are bitmaps and USPS labels are text-bearing, wherever you fetch them.** An
early reading — that Shopify rasterises at print time and the API document is always
text-bearing — was wrong, and named its own confound at the time: the text sample was USPS
and the raster ones UPS. An international UPS label taken from the API settled it with zero
fonts and the same 1400×800 grayscale bitmap. The rule "take the label from the API" survives
on **page size** only. Consequence for `11`: rung 2 cannot read a Shopify UPS label from any
source.

**A midnight ship date produces a midnight customer email.** Shopify schedules the
notification for the `shippingDatetime` sent, and accepts a midnight value without altering
it. After the 8 PM cutoff PolyBag sends tomorrow at 00:00, so a label bought at 20:05 local
schedules the customer's shipping confirmation for four hours later. Defensible — the parcel
does ship that day — but a behaviour nobody chose, following from `pickup_cutoff_hour`, a
column with **no UI**: `CarrierForm` exposes only `name` and `active`, and the value is 20
for USPS and Shopify, `null` for FedEx and UPS. "Scheduled" is approximate: one email
scheduled for 10:28 arrived at 10:34.

## Comments

- **2026-09-08** — terms accepted with an admin UPS label; first purchase through PolyBag
  chose USPS for an `auto` selection; second chose UPS Ground Saver from identical inputs,
  which is what made `auto` unusable as an instrument for `14`'s captures and pushed `02` to
  the front of the queue. Both labels voided, and the void path observed working.
- **2026-09-08** — split performed: questions 7, 8 and 10 moved to `17`, which is the only
  thing here that needs a real store. Numbering left intact, because every comment refers to
  these by number. `postage-source-split/02` and `/07` repointed at `17`.
- **2026-09-09** — question 2's premise found to be wrong: the shop's label format setting
  offers Thermal / Letter / A4, which are **paper sizes, all PDF**. Nothing in the help
  centre, the printer list or the schema reference offers ZPL or says what would produce it.
  Corrected in the service's code comment, the PRD, `14` and the README.
- **2026-09-09** — question 4 folded back in: it had been *skipped*, not answered, because
  `notify_customer` was off. Turned on and re-run — one notification, no duplicate — and the
  reason turned out to be `20`, a bug, rather than the swallowed-error branch the code
  claimed. Fixing `20` by matching the message would have reopened this; it was fixed by
  skipping the export instead, so the answer holds unconditionally.
- **2026-09-09** — question 6 answered with an international test order, which needed HS
  codes and country of origin set in the Shopify admin (they cannot be sent in the purchase)
  and turned up `19`.
- **2026-09-09** — question 9 answered by temporarily lowering `pickup_cutoff_hour` on
  carrier `Shopify` to 10 so the cutoff branch fired without waiting for 8 PM; Shopify only
  ever sees the resulting `shippingDatetime`. Restored to 20 immediately after.

## Related

- `17` — the three questions a test label cannot answer
- `02` — the service codes, unblocked by this
- `14` — the capture campaign this was the gate on
