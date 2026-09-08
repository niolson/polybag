# Answer the three Shopify questions a test label cannot

Status: ready-for-human

Repo: `polybag`

## Problem

`01` answered seven of its ten questions on a development store, where Shopify Shipping
labels are free test labels. Three could not be answered there, and no amount of care on
that store changes it. They share one blocker: **a real store, shipping a real parcel that
physically moves.**

A test label is more real than it sounds — it is registered with the carrier, and `01`
observed UPS's own tracking page recognise one, show it as *label created*, and move it to
*cancelled* when the label was voided. What it never does is get scanned, because nothing is
in a truck. Everything below turns on a scan.

Split out of `01` on 2026-09-08 so that issue can close on the questions it can still
answer. The numbering here follows `01`'s original list, because its comment history refers
to these by number.

## What to answer

**7. Does `Fulfillment.events` return anything once the parcel has moved?**
Originally from `postage-source-split/07`.

`01` established the connection is not structurally empty: a purchase produced one node,
`LABEL_PURCHASED`, carrying `happenedAt` and the origin city, province and postcode. That
settles the shape but not the substance — whether Shopify ever writes *scan-level* nodes as
the parcel moves is exactly what a stationary label cannot show.

The stakes are unchanged. The only documented way fulfillment events are created is the
`fulfillmentEventCreate` mutation, used by apps and fulfillment services; nothing says
Shopify writes them for shipments it tracks itself. So:

- **Populated with movement** → read it as the event feed, with `displayStatus` as the
  summary. It is the scan-level detail (`happenedAt`, city/province/postcode, lat/long,
  `message`) that `tracking_details['events']` wants and `displayStatus` cannot give.
- **Never more than the purchase node** → `displayStatus` plus
  `inTransitAt`/`deliveredAt`/`estimatedDeliveryAt` is the whole feed, which is what shipped
  code already assumes.

Either way this is an observation, not a change: the query already selects the field and an
empty connection is already tolerated as a normal result.

**8. Does `displayStatus` advance at all?**
Originally from `postage-source-split/07`.

`ShopifyPostageSource`'s `FulfillmentDisplayStatus` → `TrackingStatus` mapping is confirmed
against Shopify's documentation and nothing else. If the status sits still in practice, that
slice's tracking is inert rather than wrong — but we would want to know.

Two of the mapping's endpoints are now observed rather than assumed, both from `01`, and
they pull in opposite directions:

- **`LABEL_VOIDED` is real.** A label voided in the admin left its fulfillment at
  `status: CANCELLED`, `displayStatus: LABEL_VOIDED`, and the synchronizer un-shipped the
  package correctly.
- **The lifecycle does not start where the mapping's reader would expect.** A purchase
  landed at `FULFILLED`, not `LABEL_PURCHASED`. The stored `tracking_status` was right
  because `FULFILLED => PreTransit` is already in the table, but nothing in between has ever
  been seen.

So what is untested is the middle: whether `CARRIER_PICKED_UP`, `IN_TRANSIT`, `OUT_FOR_DELIVERY`
and `DELIVERED` ever arrive. Record the sequence and the timestamps, not just the endpoint.

**10. Can a Shopify-bought USPS label go on a USPS SCAN form we create?**
Originally from `postage-source-split/02`.

This one needs a real store for a second reason on top of the moving parcel: it needs a USPS
label bought on Shopify's USPS account, and it needs USPS to accept or reject it for real.

PolyBag currently excludes Shopify-bought postage from its manifests by provenance, and
**that gate stays until this is settled.** The exclusion is correct behaviour today
regardless of the answer here; what is at stake is whether it can ever be relaxed.

To test: do **not** add the label to a Shopify manifest first. Submit only that tracking
number through PolyBag's existing USPS SCAN request, using the exact label ship date and
origin postcode. Record the complete USPS status and response body, and whether the returned
form includes the tracking number.

Regardless of the result, ask USPS API support whether the use is officially supported: may
a SCAN Forms v3 "Label Shipment" request include an IMpb created by a third-party PC Postage
provider under that provider's MID, when the authenticated API customer is the physical
mailer but is not the label-owner MID? EasyPost documents a stricter rule for its own
ScanForm API — all shipments on one form must belong to the same carrier account
(https://docs.easypost.com/docs/scan-form) — which may reflect a USPS constraint or an
EasyPost one. Useful evidence, not an answer.

## Why a development store cannot substitute

Worth stating plainly, because the store *does* sell USPS labels through the API and it is
tempting to try:

- A test label never moves, so questions 7 and 8 would record the absence of scans that were
  never going to happen. An empty `events` connection or a `displayStatus` stuck where it
  started is evidence of nothing.
- Question 10 asks what USPS does with a real IMpb under Shopify's MID. A test label's
  number is not that, and a rejection would not tell us why.

## What is needed

- A real Shopify store with Shopify Shipping available, pointed at by a `DataSource`. The
  code is store-agnostic, so this is configuration rather than a code change.
- A real parcel actually tendered to the carrier.
- Real postage. This is the one place in this directory where the original cost objection
  still applies, and it is why this is `ready-for-human`.

Questions 7 and 8 come free with the first real Shopify shipment anyone makes — they are
observations on a package that was going to ship anyway, so they cost only the attention to
go and look afterwards. Question 10 needs a deliberate second purchase and a USPS API call,
and can be split off again if it is holding the other two up.

## Blocked by

Nothing in this repository. Waiting on access to a real store shipping real parcels.

## Related

- `01-verify-first-live-label-purchase` — the seven questions answered on a development
  store, and the observations these three build on
- `postage-source-split/02` — the manifest provenance gate that question 10 would relax
- `postage-source-split/07` — the `displayStatus` mapping and the `events` query behind
  questions 7 and 8
- `postage-source-split/13` — the Shopify End of Day row, which stays half-empty for as long
  as the manifest gate in question 10 stands

## Comments
