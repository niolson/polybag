# Answer the three Shopify questions a test label cannot

Status: ready-for-human

Repo: `polybag`

## Problem

`01` answered seven of its ten questions on a development store, where Shopify Shipping
labels are free test labels. Three could not be answered there at any price. They share one
blocker: **a real store, shipping a real parcel that physically moves.**

A test label is more real than it sounds — it is registered with the carrier, and `01`
watched UPS's own tracking page recognise one, show it as *label created*, and move it to
*cancelled* when the label was voided. What it never does is get scanned. Everything below
turns on a scan.

Numbering follows `01`'s original list, because its history refers to these by number.

## What to answer

**7. Does `Fulfillment.events` return anything once the parcel has moved?**

`01` established the connection is not structurally empty: a purchase produced one node,
`LABEL_PURCHASED`, carrying `happenedAt` and the origin city, province and postcode. That
settles the shape, not the substance. The only documented way fulfillment events are created
is `fulfillmentEventCreate`, used by apps and fulfillment services; nothing says Shopify
writes them for shipments it tracks itself.

- **Populated with movement** → read it as the event feed, with `displayStatus` as the
  summary. It is the scan-level detail `tracking_details['events']` wants.
- **Never more than the purchase node** → `displayStatus` plus
  `inTransitAt`/`deliveredAt`/`estimatedDeliveryAt` is the whole feed, which is what shipped
  code already assumes.

Either way this is an observation, not a change: the query already selects the field and an
empty connection is already tolerated.

**8. Does `displayStatus` advance at all?**

`ShopifyPostageSource`'s `FulfillmentDisplayStatus` → `TrackingStatus` mapping is confirmed
against Shopify's documentation and nothing else. Two endpoints are now observed and they
pull in opposite directions: **`LABEL_VOIDED` is real** (a voided label left its fulfillment
`CANCELLED` / `LABEL_VOIDED` and the synchronizer un-shipped correctly), and **the lifecycle
does not start where the mapping's reader would expect** — a purchase landed at `FULFILLED`,
not `LABEL_PURCHASED`. What is untested is the middle: whether `CARRIER_PICKED_UP`,
`IN_TRANSIT`, `OUT_FOR_DELIVERY` and `DELIVERED` ever arrive. Record the sequence and the
timestamps, not just the endpoint.

**10. Can a Shopify-bought USPS label go on a USPS SCAN form we create?**

Needs a real store twice over: a USPS label bought on Shopify's USPS account, and USPS
accepting or rejecting it for real.

PolyBag excludes Shopify-bought postage from its manifests by provenance, and **that gate
stays until this is settled.** The exclusion is correct today regardless of the answer; what
is at stake is whether it can ever be relaxed.

To test: do **not** add the label to a Shopify manifest first. Submit only that tracking
number through PolyBag's existing USPS SCAN request, using the exact label ship date and
origin postcode. Record the complete USPS status and response body, and whether the returned
form includes the tracking number. Worth asking USPS API support alongside it whether a SCAN
Forms v3 "Label Shipment" request may include an IMpb created by a third-party PC Postage
provider under that provider's MID, when the authenticated customer is the physical mailer
but not the label-owner MID. EasyPost documents a stricter rule for its own ScanForm API —
all shipments on one form must share a carrier account — which may reflect a USPS constraint
or an EasyPost one. Evidence, not an answer.

## Why a development store cannot substitute

Worth stating plainly, because the store *does* sell USPS labels through the API and it is
tempting to try. A test label never moves, so 7 and 8 would record the absence of scans that
were never going to happen. Question 10 asks what USPS does with a real IMpb under Shopify's
MID; a test label's number is not that, and a rejection would not tell us why.

## What is needed

A real Shopify store pointed at by a `DataSource` (the code is store-agnostic, so this is
configuration), a real parcel actually tendered, and real postage. This is the one place in
this directory where the original cost objection still applies.

**One thing to record while you are there, which is not a question here.** The pricing
premise is already evidenced from dev-store test labels (see the PRD), with one caveat left
open: whether a production store is priced the same as a development store. A real purchase
answers that for the cost of reading the order timeline, so note the price against the
service and parcel when you make one.

Questions 7 and 8 come free with the first real Shopify shipment anyone makes — observations
on a package that was going to ship anyway. Question 10 needs a deliberate second purchase
and a USPS API call, and can be split off again if it holds the other two up.

## Blocked by

Nothing in this repository. Waiting on access to a real store shipping real parcels.

## Related

- `01` — the seven questions answered on a development store
- `postage-source-split/02` — the manifest provenance gate question 10 would relax
- `postage-source-split/07` — the `displayStatus` mapping and `events` query
- `postage-source-split/13` — the Shopify End of Day row, half-empty while the gate stands
