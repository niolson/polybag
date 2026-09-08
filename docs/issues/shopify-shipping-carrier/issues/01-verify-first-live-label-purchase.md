# Buy the first live Shopify Shipping label and settle what comes back

Status: ready-for-human

Repo: `polybag`

## Problem

Every remaining unknown about Shopify Shipping is gated behind one manual act: buying a
shipping label through the **Shopify admin**, which is how a shop accepts the Shopify
Shipping terms of service. Until that happens the API answers every purchase with
`TERMS_OF_SERVICE_NOT_ACCEPTED`, and no amount of code changes that.

This is `ready-for-human` because it costs real postage, cannot be voided through the
API, and needs someone with admin access to the shop.

## Before starting

- The staff account needs the **`buy_shipping_labels`** permission — separate from the
  app's OAuth scopes, and not visible through the API.
- `write_orders` was granted 2026-08-29. `read_shipping` is not granted and is not
  needed; `read_orders` also authorises the shipping documents.
- **Check the store is eligible at all.** `polybag-test` is a development store, and
  Shopify Shipping is generally unavailable on those. If the admin will not sell a label
  either, this verification has to move to a real store — the code is store-agnostic, so
  that is a matter of pointing a `DataSource` at one, not a code change.

## What to answer

Fold all of it into as few purchases as possible; each one costs money and can only be
voided in the admin.

1. **PDF or ZPL?** `ShippingObjectsShippingDocument.format` reports what the shop's
   admin setting produced. This is the single most-asked question about the feature.
2. **If ZPL, at what DPI?** PolyBag currently stores its *own* configured DPI on the
   package, not Shopify's. Format follows the label correctly — printing dispatches on
   the stored `label_format` — but density does not, and a 203/300 mismatch prints the
   wrong physical size. If Shopify's ZPL density is fixed or discoverable, record it
   and file a follow-up to store it.
3. **Does the purchase create the Fulfillment itself?** This matters twice:
   - `ShopifySource::exportPackage()` calls `fulfillmentCreate` and already swallows
     "already fulfilled" errors, so export degrades safely either way.
   - `ShopifyFulfillmentSynchronizer` **reads fulfillments** to find `LABEL_VOIDED`. If
     Shopify creates no fulfillment and PolyBag's export creates it instead, confirm the
     display status still lands where the synchronizer looks. This is an untested
     assumption in shipped code.
4. **Is the customer notified twice?** `notifyCustomer` goes on the purchase, and
   PolyBag's export sets it again on `fulfillmentCreate`.
5. **What does `trackingInfo.company` actually read?** It becomes the package's **carrier
   of record**. Confirm it is a human-sensible carrier name and not an internal code, and
   that `CarrierNormalizer` resolves it — an unrecognised spelling normalizes to null,
   which is a valid terminal state but costs the ship-date cutoff and the export mapping.
6. **Is a customs form returned for an international order**, and as a separate
   `CUSTOMS_FORM` document? See `07`.
7. **Does `Fulfillment.events` return anything for a Shopify Shipping label?** Query
   `events(first: 50)` once the parcel has actually moved. The only documented way events
   are created is the `fulfillmentEventCreate` mutation, used by apps and fulfillment
   services; nothing says Shopify writes them for shipments it tracks itself.
   - Populated → read it as the event feed, with `displayStatus` as the summary; it is the
     scan-level detail (`happenedAt`, city/province/zip, lat/long, `message`) that
     `tracking_details['events']` wants and `displayStatus` cannot give.
   - Empty → `displayStatus` plus `inTransitAt`/`deliveredAt`/`estimatedDeliveryAt` is the
     whole feed, which is what shipped code already assumes.

   The query already selects the field, and an empty connection is already tolerated as a
   normal result rather than an error, so this is an observation to record, not a change to
   make. From `postage-source-split/07`.
8. **Does `displayStatus` advance past `LABEL_PURCHASED` at all?** Everything in
   `ShopifyPostageSource`'s `FulfillmentDisplayStatus` → `TrackingStatus` mapping is
   confirmed against Shopify's documentation and nothing else. If the status sits still in
   practice, that slice's tracking is inert rather than wrong — but we would want to know.
   From `postage-source-split/07`.
9. **Does Shopify accept a midnight `shippingDatetime`?** After the 8 PM cutoff,
   `getShipDate()` returns a date at midnight and `ShopifyShippingLabelService` sends
   tomorrow at 00:00; before it, `now() + 5 minutes`. Confirm Shopify does something
   sensible with the midnight value rather than rejecting it or silently substituting.
   From `postage-source-split/06`.
10. **Can a Shopify-bought USPS label go on a USPS SCAN form we create?** Only if the
    opportunity arises cheaply — this is the one question here that needs a *second*
    controlled purchase rather than an observation on the first, so it may be worth
    splitting out once a label can be bought at all.

    PolyBag currently excludes Shopify-bought postage from its manifests by provenance, and
    that gate stays until this is settled. To test: do **not** add the label to a Shopify
    manifest first; submit only that tracking number through PolyBag's existing USPS SCAN
    request using the exact label ship date and origin ZIP; record the complete USPS status
    and response body and whether the returned form includes the tracking number.

    Regardless of the result, ask USPS API support whether the use is officially supported:
    may a SCAN Forms v3 "Label Shipment" request include an IMpb created by a third-party PC
    Postage provider under that provider's MID, when the authenticated API customer is the
    physical mailer but is not the label-owner MID? EasyPost documents a stricter rule for
    its own ScanForm API — all shipments on one form must belong to the same carrier account
    (https://docs.easypost.com/docs/scan-form) — which may reflect a USPS constraint or an
    EasyPost one. Useful evidence, not an answer. From `postage-source-split/02`.

## How to run it

The whole path already works against the live store. With a real Shopify-sourced
package:

```php
$package = Package::with('shipment.dataSource')->findOrFail(<id>);
$adapter = new ShopifyAdapter;
$rates = $adapter->getRates(RateRequest::fromPackage($package), ['auto']);
$response = $adapter->createShipment(ShipRequest::fromPackageAndRate($package, $rates->first()));
```

Before the terms were accepted this returned Shopify's error verbatim, which is what
confirmed the chain end to end. Afterwards it should return a label.

## Comments

### 2026-09-08 — terms accepted with a UPS label; on a dev store only UPS sells one

A label was bought through the Shopify admin. **The gate is cleared** — or should be:
the terms are accepted per shop, not per carrier, so `TERMS_OF_SERVICE_NOT_ACCEPTED`
is expected to be gone from the API path. That is an expectation until the mutation is
actually run; running it is the remaining work here.

USPS and FedEx both failed in the admin's buy-label flow with a generic "something went
wrong". **Shopify support gave the reason: on a development store only UPS supports test
labels.** `polybag-test` is one — `shop { plan { displayName: "Shopify Plus App
Development", partnerDevelopment: true } }`. So the error is the absence of test-label
support for those carriers, not a shop misconfiguration and not something to chase.

Two consequences, and they pull in opposite directions.

**The cost objection to this issue does not apply on this store.** A dev-store label is a
test label: no postage is charged and nothing ships. Most of the question list can be
worked through here for nothing, which is a much better position than the issue was
written in. FedEx failing is doubly irrelevant — Shopify Shipping does not sell FedEx
through this API either way, and `ShopifyShippingLabelService::CARRIER_CODES` has never
listed it.

**But a test label is not a moving parcel, and USPS is the point.** USPS CeC pricing is
the entire motivation for the feature (see the PRD's "Why"), and it cannot be exercised on
this store at all. Splitting the questions by what this store can actually answer:

- **Answerable here, with one UPS test purchase through PolyBag:** 1 and 2 (format, and
  DPI if ZPL — a shop admin setting rather than a carrier one, so it should hold across
  carriers; record which carrier it was observed on regardless), 3 (does the purchase
  create the fulfillment), 4 (double notification), 5 (`trackingInfo.company` and whether
  `CarrierNormalizer` resolves it), 9 (midnight `shippingDatetime`).
- **Not answerable by a test label:** 7 and 8. Both ask what happens once the parcel
  moves, and a test label never moves. An empty `events` connection or a `displayStatus`
  stuck at `LABEL_PURCHASED` here is evidence of nothing.
- **Needs a real store:** 10, which needs a USPS label to put on a SCAN form, and any
  answer where USPS's own behaviour is the subject.
- **Needs an international order:** 6.

The `ready-for-human` label stays, but for the remaining reason rather than the original
one: it needs admin access to the shop and a person watching what comes back, not a
postage budget.

### 2026-09-08 — first label bought through PolyBag: Shopify chose USPS, and USPS *is* sellable through the API

Package 204, shipment 6756, `preferredRateSelection` omitted (`auto`). It went through.
Nothing in the log but the rate-list debug lines — no error, no retry.

**It was bought on Shopify's account, not ours.** Worth stating because the label came
back USPS Ground Advantage, which looks at a glance like PolyBag's own USPS account
answering. It wasn't:

| Evidence | Value |
|---|---|
| `postage_source` | `postage_data_source` |
| `postage_data_source_id` | the Shopify source |
| `carrier_account_id` | `null` — no carrier account was used |
| `metadata.shopify_shipping_label_id` | `gid://shopify/ShippingLabel/…` |
| `metadata.shopify_requested_service_code` | `auto` |
| label document | served from `shopify-shipify.s3.amazonaws.com` |
| `cost` | `null`, as designed — Shopify reports no price |

**The headline finding: `auto` returned USPS on a store whose admin refuses to sell
USPS.** The support answer recorded above — only UPS supports test labels — describes the
admin's buy-label flow, and does not hold for `shippingLabelPurchase`. That reopens the
CeC question: the API path may be exercisable on a development store after all. It does
not settle it, because a test label has no price attached to compare against anything.

Answers, against the numbered list:

1. **PDF.** One `LABEL` document, `format: PDF`. No `CUSTOMS_FORM` — domestic order, so
   `07` is untouched by this.
2. N/A — nothing to say about DPI for a PDF. Still open for a ZPL shop.
3. **Shopify creates the fulfillment itself**, `status: SUCCESS`. So
   `ShopifySource::exportPackage()` will meet an already-fulfilled order and swallow it,
   which is the branch that was assumed and never observed. The display status is
   `FULFILLED` — **not** `LABEL_PURCHASED`, which is what a reader of
   `ShopifyPostageSource`'s mapping table would expect to see first. The mapping already
   has `FULFILLED => PreTransit`, so the package's stored `tracking_status` is right, but
   the assumption that the lifecycle *starts* at `LABEL_PURCHASED` is wrong.
4. Not exercised: `notify_customer` is off on this data source.
5. **`trackingInfo.company` is `usps` on the `ShippingLabel` and `USPS` on the
   `Fulfillment`** — same shop, same label, different case in the two places. Anything
   comparing these strings must fold case. `CarrierNormalizer` resolved it and the
   package carries the normalized carrier.
6. Not exercised — domestic.
7. **`events` is populated.** One node: `LABEL_PURCHASED`, with `happenedAt` and the
   origin city/province/zip. So the connection is not structurally empty, which is what
   the shipped code assumed it might be. Whether it ever carries *scan-level* movement is
   still unknown and unknowable here — a test label never moves.
8. Cannot be answered on a test label, per the entry above. It began at `FULFILLED`.
9. Not exercised. The package's ship date was today at midnight, already in the past, so
   `buildPurchaseInput()` substituted `now() + 5 minutes` and the midnight value was never
   sent. Answering this one needs a purchase made after the 8 PM cutoff.

**One bug fell out of it**, filed as `15`: the label's tracking number is a 26-digit IMpb,
`ImpbTrackingNumber::tryParse()` accepts only the 22-digit form, so rung 1 of `11`'s
inference ladder declined a number whose check digit is valid and whose service type code
decodes to exactly what the label says. `packages.service` is null on a package whose
service was sitting in its tracking number.

### 2026-09-08 — what the order timeline added

The admin's own record of the purchase, which is the ground truth `14`'s capture protocol
asks for, and which the API does not return:

- **`Test: True`.** Confirmed a test label, as expected on a development store.
- **`$5.68`, and the timeline says so in words.** A test label still carries a price, and
  the price is readable through `Order.events`. That is a finding for `05`, recorded
  there — the PRD's claim that cost is reachable only through Shopify Payments balance
  transactions was wrong.
- **The purchase archived the order.** Worth knowing before an import filter meets it.
- **Service reads `Manual (Shipping label)`** on the fulfillment Shopify created.

**`LABEL_VOIDED` is confirmed, from the manual purchase earlier the same day.** That
label — UPS, `1Z…`, $5.69, bought and voided in the admin — left its fulfillment at
`status: CANCELLED`, `displayStatus: LABEL_VOIDED`. Both strings are in
`ShopifyShippingLabelService::VOIDED_STATES`, so the synchronizer's central assumption is
now observed rather than assumed, and a void does emit a timeline event of its own.

**Question 5 has a second answer, and it is a bug:** that voided label's
`trackingInfo.company` reads `UPS®`, with the registered-trademark sign, and
`CarrierNormalizer` returns null for it. Filed as `16`. Had that label been bought through
PolyBag rather than the admin, the package would have shipped with no carrier of record.

**On why the API sold USPS when the admin would not**, the theory that the origin address
PolyBag sends differs from the shop's location address and that this is what gets it
through: the timeline does not support it, and does not rule it out. What it shows is that
the successful manual purchase was **UPS**, not USPS — so the admin still never sold a
USPS label, and the shipping address was edited *after* that purchase and before ours,
which leaves two changed variables rather than one. The cheap test is a second API
purchase with `preferredRateSelection: usps` and an origin address matching the Shopify
location exactly. On a development store that costs nothing.

### 2026-09-08 — second purchase: `auto` chose UPS, and question 5's answer is the bad one

Shipment 6758, package 205, `auto` again. Shopify picked **UPS Ground Saver**, $9.04, PDF
again — so `01`'s format answer holds across two carriers, which is what a shop-level
admin setting predicts.

**Question 5 is answered, and the answer is the one it was afraid of: it is an internal
code.** `ShippingLabel.trackingInfo.company` returned `ups_shipping`. USPS resolving on
the first purchase was luck — `usps` is both Shopify's carrier code and the carrier's
name, so nothing was ever normalizing these and the first label hid it. Package 205
shipped with `carrier = "ups_shipping"` and no `normalized_carrier_id`: a UPS parcel with
no carrier of record. `16` now covers this as the primary case, with the `UPS®` spelling
from the `Fulfillment` node as the second half.

`service` is null again, as expected — the UPS 1Z service-indicator table named in `14` is
not built, so rung 1 has nothing to decode with for a UPS number.

**On the label not being marked as a test.** The API cannot answer this: `ShippingLabel`
has six fields and none of them says. The admin timeline's expanded event does — it read
`Test: True` on the first purchase and is the place to check for this one. Two things
suggest the printed marking is a carrier-side difference rather than a live label:

- Both UPS tracking numbers from this store — this one and the manually-bought, voided
  `1Z…` — carry a **non-numeric service indicator** in bytes 9–10, `YW` and `YN`. A real
  1Z has two digits there. USPS test labels get a dummy IMpb serial and, apparently, a
  visible marking; UPS test labels appear to get a malformed 1Z and no marking.
- Nothing else about the purchase differed from the first one.

That is inference, not proof, and the timeline entry settles it either way.

It also lands directly on `14`'s first documentation item: a UPS 1Z table keyed on bytes
9–10 has to **decline a non-numeric indicator** rather than fail to find it, or every test
label on a development store becomes a lookup miss that looks like a coverage gap.

### 2026-09-08 — the void path ran end to end, and a test label is real enough for UPS to track

Both labels were voided in the Shopify admin. `packages:sync-shopify-fulfillments` picked
both up on its next scheduled run, twelve and sixteen minutes later:

```
21:30:01  Shopify label voided outside PolyBag; package returned to unshipped  package 204
21:30:02  Shopify label voided outside PolyBag; package returned to unshipped  package 205
```

Both packages are back to `unshipped` with tracking, carrier, provenance
(`postage_source`, `postage_data_source_id`) and the label identifiers cleared, and an
audit row recorded. **This closes the untested assumption in question 3**: the
synchronizer reads a fulfillment Shopify created for a label Shopify sold, finds
`LABEL_VOIDED` where it expected to, and un-ships correctly. It was shipped code that had
never once run against a real void.

`metadata.shopify_tracking_company` and `shopify_requested_service_code` survive the void
by design — `applyVoid()` drops only the four identifiers that could recover a dead
purchase. Nothing reads the two that remain, so they are description of a label that no
longer exists rather than a live reference. Noted so it does not read as a leak.

**`Test: True` on the UPS purchase too.** So the missing marking on the printed label is a
carrier-side rendering difference — USPS prints its test labels visibly, UPS apparently
does not — and not a live label. That question is closed.

**Correcting the entry above.** I read the non-numeric service indicator (`YW`, `YN`) as a
malformed number and therefore as evidence of a test label. It is not: UPS's own tracking
page recognised `1Z28X87GYW00010815`, showed it as *label created*, and moved it to
*cancelled* after the Shopify void. So a Shopify test label is registered with the carrier
and its status follows a void through. The indicator is one **we cannot decode**, not one
UPS rejects.

That sharpens `14`'s first documentation item rather than softening it: bytes 9–10 of a 1Z
can hold values a published service table will not list, and they come back from real
labels that track. The table has to fall through on an unrecognised indicator and let rung
2 answer, which is the discipline `11` already established for the USPS codes.
