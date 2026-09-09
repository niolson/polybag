# Buy the first live Shopify Shipping label and settle what comes back

Status: done

Repo: `polybag`

## Problem

Every remaining unknown about Shopify Shipping is gated behind one manual act: buying a
shipping label through the **Shopify admin**, which is how a shop accepts the Shopify
Shipping terms of service. Until that happens the API answers every purchase with
`TERMS_OF_SERVICE_NOT_ACCEPTED`, and no amount of code changes that.

This is `ready-for-human` because it needs someone with admin access to the shop watching
what comes back. It was originally so because it cost real postage; that turned out not to
hold on a development store, where the labels are free test labels — see the comments.

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

**Questions 7, 8 and 10 moved to `17` on 2026-09-08.** They need a real store shipping a
real parcel, which this issue no longer has any other dependency on. The numbering below is
left intact — every comment in this file refers to these by number.

The remaining questions are answerable on a development store for nothing, so the original
instruction to fold them into as few purchases as possible no longer applies. Spend a
purchase per question if it is clearer.

1. **PDF or ZPL?** `ShippingObjectsShippingDocument.format` reports what the shop's
   admin setting produced. This is the single most-asked question about the feature.
2. ~~**If ZPL, at what DPI?**~~ **Answered and closed 2026-09-09, in two steps.** There is no
   ZPL setting to flip, so no density to record; restated as whether the label PDF's **page
   size** follows the shop's label format setting, it turned out the setting is applied at
   **print time** and never reaches the document at `shippingDocuments[].url`. What PolyBag
   downloads is a true 4×6 page. No code change. See the two comments of that date.
3. **Does the purchase create the Fulfillment itself?** **Answered — yes.** This matters
   twice:
   - ~~`ShopifySource::exportPackage()` calls `fulfillmentCreate` and already swallows
     "already fulfilled" errors, so export degrades safely either way.~~ **Wrong, corrected
     2026-09-09 — it does not degrade safely.** Shopify says *"unfulfillable status= closed"*,
     which the guard does not match, so every Shopify-bought package lands a
     `permanently_failed` export row. Filed as `20`.
   - `ShopifyFulfillmentSynchronizer` **reads fulfillments** to find `LABEL_VOIDED`. If
     Shopify creates no fulfillment and PolyBag's export creates it instead, confirm the
     display status still lands where the synchronizer looks. This is an untested
     assumption in shipped code.
4. ~~**Is the customer notified twice?**~~ **Answered 2026-09-09 — no, one notification.**
   Conditionally, though: the second call site never succeeds (`20`), so there was never a
   second notification to send. **Fixing `20` in a way that makes `fulfillmentCreate` succeed
   reopens this question.** The purchase also revealed that Shopify schedules the customer's
   email for the `shippingDatetime` PolyBag sends, which gives question 9 a consequence it was
   not written to expect.
5. **What does `trackingInfo.company` actually read?** It becomes the package's **carrier
   of record**. Confirm it is a human-sensible carrier name and not an internal code, and
   that `CarrierNormalizer` resolves it — an unrecognised spelling normalizes to null,
   which is a valid terminal state but costs the ship-date cutoff and the export mapping.
6. ~~**Is a customs form returned for an international order**, and as a separate
   `CUSTOMS_FORM` document?~~ **Answered 2026-09-09 — yes.** A separate document, PDF, but
   **three Letter pages**, not a 4×6 label: a UPS commercial invoice, text-bearing. Getting
   there needed HS codes and country of origin set in the Shopify admin (they cannot be sent
   in the purchase) and turned up `19`. See `07`, which this decides more of than expected.
7. **Moved to `17`.** Does `Fulfillment.events` carry scan-level movement? The connection
   is not structurally empty — a purchase node was observed — but a test label never moves,
   so the substance is unanswerable here.
8. **Moved to `17`.** Does `displayStatus` advance? Its two endpoints are now observed and
   the middle is not. Same blocker.
9. ~~**Does Shopify accept a midnight `shippingDatetime`?**~~ **Answered 2026-09-09 — yes,
   and the customer notification is scheduled for that midnight.**
   After the 8 PM cutoff, `getShipDate()` returns a date at midnight and
   `ShopifyShippingLabelService` sends tomorrow at 00:00; before it, `now() + 5 minutes`.
   Confirm Shopify does something sensible with the midnight value rather than rejecting it or
   silently substituting — **and record when the customer notification lands**, since question
   4 established Shopify schedules it for the `shippingDatetime` we send, which would put a
   shipping email at midnight local. From `postage-source-split/06`.
10. **Moved to `17`.** Can a Shopify-bought USPS label go on a USPS SCAN form we create?
    Needs a real IMpb under Shopify's MID and a real answer from USPS. The manifest
    provenance gate stays in place until it is settled, which is unchanged by the move.

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
page recognised `1Z000X00YW00000002`, showed it as *label created*, and moved it to
*cancelled* after the Shopify void. So a Shopify test label is registered with the carrier
and its status follows a void through. The indicator is one **we cannot decode**, not one
UPS rejects.

That sharpens `14`'s first documentation item rather than softening it: bytes 9–10 of a 1Z
can hold values a published service table will not list, and they come back from real
labels that track. The table has to fall through on an unrecognised indicator and let rung
2 answer, which is the discipline `11` already established for the USPS codes.

### 2026-09-08 — what is left here, and a recommendation to split it

Seven of the ten questions are answered above. The three that are not divide cleanly by
*why* they are unanswered, and the division is worth acting on.

**Still answerable on the development store, for nothing:**

- **Question 2 (ZPL, and DPI).** Overlooked so far because both purchases came back PDF.
  Format follows the **shop's own admin setting**, not the carrier — which is what the two
  PDF answers across two carriers demonstrate. So flip this store's label format to ZPL and
  buy one. The DPI half matters: PolyBag stores its own configured DPI on the package, and a
  203/300 mismatch prints the wrong physical size.
- **Question 9 (midnight `shippingDatetime`).** One purchase made after the 8 PM cutoff.
  Both purchases so far were before it, so `buildPurchaseInput()` substituted
  `now() + 5 minutes` and the midnight value has still never been sent.
- **Question 6 (customs form).** Needs an international test order on this store, not a real
  one. It is the confirming step `07` asks for before any of its options can be chosen.

**Not answerable here at any price — questions 7, 8 and 10.** All three need a parcel that
physically moves, or USPS's own behaviour as the subject: whether `Fulfillment.events` ever
carries scan-level movement, whether `displayStatus` advances past its initial value, and
whether a Shopify-bought USPS label is accepted on a SCAN form we create. A test label on a
development store is evidence of nothing for any of them, and no amount of care here changes
that.

**Recommendation: close this issue once the three answerable questions are done, and spin 7,
8 and 10 out into their own issue.** They share a blocker this issue no longer has — access
to a real store shipping real parcels — and leaving them here keeps a "blocked on `01`" edge
alive across `02`, `11` and `14` that has actually been dead since the terms were accepted.
Not done unilaterally; it changes what other files point at.

### 2026-09-08 — split performed: questions 7, 8 and 10 are now `17`

The recommendation in the comment above was taken. `17` carries the three questions that
need a real store shipping a real parcel, along with the observations from this file they
build on — the `LABEL_PURCHASED` event node, the `FULFILLED` starting status, and the
confirmed `LABEL_VOIDED` endpoint.

**The numbering here was not changed.** Every comment in this file refers to these questions
by number, and renumbering would silently break all of it. Items 7, 8 and 10 are stubs
pointing at `17`.

Two cross-references were repointed at the same time, both of which were the original source
of a moved question: `postage-source-split/02` (question 10, the SCAN form) and
`postage-source-split/07` (questions 7 and 8, `events` and `displayStatus`).

**What is left here is three questions and a close.** All of them are answerable on a
development store for nothing:

- **2** — ZPL and its DPI. Flip the shop's label format setting; both purchases so far came
  back PDF because that is what this store is set to.
- **6** — the customs form, which needs an international test order and is the gate on `07`.
- **9** — the midnight `shippingDatetime`, which needs a purchase made after the 8 PM cutoff.

Close this issue when those three are answered. The manifest provenance gate that question 10
guarded is unaffected by the move and stays in place.

### 2026-09-09 — question 2's premise is wrong: there is no ZPL setting to flip

Before buying a third label, I went looking for the setting the last three comments assume
exists. It does not. Both halves of question 2 were resting on it.

**What the schema says**, introspected against `polybag-test` on API version `2026-07`:

| Type | Values |
|---|---|
| `ShippingEnumsFileFormat` — the type of `shippingDocuments.format` | `PDF`, `ZPL`. Nothing else |
| `ShippingDocumentType` | `LABEL`, `CUSTOMS_FORM` |
| `ShippingLabelPurchaseInput` | `fulfillmentOrderId`, `shippingDatetime`, `packageInfo`, `totalWeight`, `notifyCustomer`, `preferredRateSelection`, `originAddress` |

So `ZPL` is a real value the field can carry, and **format cannot be requested** — the
purchase input has no format field at all. That much was already recorded in the PRD from
reading; it is now verified against the live schema rather than the documentation.

**What the admin says** is the part that breaks the question. Shopify's label format setting
offers three choices — Thermal (4×6in / A6), Letter (8.5×11in), A4 — and they are **paper
sizes, not languages**. All three are PDF. The help centre confirms the setting is "saved and
used as the default for future label purchases", which is where
`ShopifyShippingLabelService`'s "shop's admin setting" comment came from and is true as far
as it goes; what the comment does not say is that the setting selects a *size*. Nothing in
the help centre, the supported-printer list, or the `ShippingObjectsShippingDocument`
reference offers ZPL or says what would produce it.

**So the two PDF answers across two carriers were not the shop setting confirming itself.**
PDF was the only outcome available. There is no purchase to make here, and the DPI half of
the question dies with it: without ZPL there is no density to record, and the follow-up this
issue contemplated filing has nothing to store.

**But the failure question 2 was guarding against survives, relocated.** An 8.5×11 PDF
dispatched to a 4×6 thermal workstation is the same class of wrong-physical-size failure as a
203/300 DPI mismatch, and PolyBag has no field for it either way — `label_format` is `pdf`
for all three sizes. Restating question 2 as what is actually answerable here:

> **2 (restated).** Does the label PDF's page size follow the shop's label format setting?
> Set the store to Thermal 4×6, buy one, read the PDF's MediaBox; set it to Letter, buy
> another, read it again. If the size tracks the setting, PolyBag is receiving labels whose
> physical dimensions it neither records nor communicates to the print path.

That is still free on this store, and it is still one setting flip and two purchases — the
same shape of work the original question described, aimed at the real hazard.

**The two labels already bought cannot answer it.** Packages 204 and 205 both carry
`label_format: 'pdf'`, but the void cleared `label_data` and `shopify_label_document_url`, so
there is no PDF left to measure. `applyVoid()` doing its job, not a fault.

**One consequence outside this issue.** `14`'s question 2 asks what format Shopify hands back
*per carrier*, listing "PDF, ZPL, PNG". The enum has two values and PNG is not one of them, so
no carrier can report PNG through this API however its own label is drawn — and format is
shop-level rather than carrier-level, which is what two PDFs across two carriers already
suggested. Recorded there.

Corrected alongside this: the code comment in `ShopifyShippingLabelService`, the PRD's
`PDF vs ZPL` row, `14`'s cross-reference, and the campaign order in `docs/issues/README.md`,
all of which told a reader to go and flip a setting that is not there.

### 2026-09-09 — question 4 folded back in; the remainder is four, not three

The close-out comment above listed 2, 6 and 9 as what was left. Question 4 was not answered,
only **skipped**: `notify_customer` is `false` on data source 7, so neither notification call
site ran on either purchase. That is a gap rather than a resolution, and it is as free to
close as the other three.

The risk is real and unobserved. Two call sites set the flag independently:

| Call site | Sets |
|---|---|
| `ShopifyShippingLabelService::buildPurchaseInput()` | `notifyCustomer` on `shippingLabelPurchase` |
| `ShopifySource::exportPackage()` | `notifyCustomer` on `fulfillmentCreate` |

Both read the same `notify_customer` setting, so a shop that turns notifications on turns
both on. Whether that means the customer gets two shipping emails is the question.

**There is now a reason to think it does not**, which is exactly why it should be checked
rather than assumed either way. Question 3 established that **Shopify creates the fulfillment
itself** at purchase — so `exportPackage()` meets an already-fulfilled order and its
`fulfillmentCreate` fails with the "already fulfilled" error the code deliberately swallows.
A mutation that errors sends no notification. If that holds, the second notify can never fire
and the question closes clean.

That is inference from a branch we have observed, not an observation of the notification
itself. Turn `notify_customer` on for data source 7, buy one, and count what arrives at the
test customer's address.

**Note the two answers are not symmetric.** "One email" closes the question. "Two emails"
means the swallowed error is not the whole story and PolyBag is double-notifying every shop
that enables the setting — which would be a bug worth its own issue, not a note here.

### 2026-09-09 — question 2 answered and closed: the format setting never touches what we download

Two admin-printed PDFs of one UPS Ground Saver label, printed eighteen seconds apart at the
two settings, plus the API-side document still reachable on package 177.

| Document | Page size | Pages | Rot |
|---|---|---|---|
| Admin print, Thermal 4×6 | **288 × 432 pts** — a true 4×6 page | 1 | 0 |
| Admin print, Letter | 612 × 792 pts | 1 | 0 |
| **API `shippingDocuments[].url`** (package 177) | **288 × 432 pts** | 1 | 0 |

Both admin renders carry the identical 1400×800 image object, 34.6K in each, so they are two
renders of one label rather than two labels. The 4×6 is a **genuine 4×6 page**, not a Letter
page with a label parked in a corner — which was the failure mode worth checking for.

**The setting is applied at print time and does not reach the API document.** The file
PolyBag downloads is 4×6, and PolyBag dispatches it to a 4×6 workstation. The
wrong-physical-size hazard the restated question was aimed at does not exist on this path.

**Question 2 closes with no code change.** Neither half survives: there is no ZPL setting,
and the page size we are handed is already the right one. What replaces it in the service is
a comment saying the page size is not reported at all — true, and now known not to matter.

One caveat kept deliberately: this is one API document. If a shop is ever found whose API
documents come back Letter, the assumption above is what broke.

### 2026-09-09 — the admin's PDF is a flat bitmap and the API's is not; `11` nearly took the wrong lesson

Checking the page sizes turned up something with more consequence than the sizes had.

| | Fonts | Extractable text | Images |
|---|---|---|---|
| Admin print (either size) | **none** | **0 bytes** | one 1400×800 grayscale bitmap |
| API document | four embedded Arial/Consolas subsets | 308 bytes | three tiny indexed images — the barcodes |

`pdftotext` pulls `PRIORITY MAIL EXPRESS®` straight out of the API document. It returns
nothing at all from the admin renders, which have no font resources to return.

**On the admin renders alone the conclusion is that rung 2 of `11` is dead for every Shopify
label and OCR is the only route** — a serious finding, and wrong. Shopify rasterises when it
renders for printing; the document the API hands us is text-bearing. Anyone reaching for a
Shopify label to test service inference must take it from `shippingDocuments[].url` and not
from the admin's print dialog, or they will measure the wrong file. Recorded in `11`.

**One confound not cleared.** The text-bearing document is USPS and the rasterised ones are
UPS. "API is text, admin render is raster" fits, and so does "USPS is text, UPS is raster" —
two data points, two readings. Clearing it needs a UPS label bought **through PolyBag**, so
its API document is captured; today's UPS label was bought in the admin and no package holds
its URL. That capture is already on `14`'s list.

**Label content, for `14` and `15`.** The UPS Ground Saver label prints `UPS GROUND SAVER`,
and also `US POSTAGE PAID / UPS / eVS` and `USPS PARCEL SELECT` — a consolidator label with a
USPS last mile, which is `14`'s question 3 answering itself. It carries two tracking numbers:
`1Z000X00YW00000001` and a 26-digit IMpb, `9261 0000 0000 0000 0000 0000 01`. The USPS
document's number is 26 digits too, `9270 0000 0000 0000 0000 0000 02`. Both are `15` again.

The USPS document also carries a `SAMPLE - DO NOT MAIL` watermark, so Shopify's USPS test
labels are USPS's own sample labels — which is the visible test marking `01` went looking for
earlier and found on USPS but not UPS.

### 2026-09-09 — question 6 answered: a customs form does come back, and it is not a label

An international test order to a Canadian destination, order #1240, package 207. Getting there took two
preconditions and a bug, all recorded below, but the answer is unambiguous.

**Yes — a separate `CUSTOMS_FORM` document, and it is nothing like the label.**

| | `LABEL` | `CUSTOMS_FORM` |
|---|---|---|
| Format | PDF | PDF |
| Pages | 1 | **3** |
| Page size | 288 × 432 (4×6) | **612 × 792 (Letter)** |
| Text layer | none — one bitmap | text-bearing, four embedded fonts |

It is a UPS commercial invoice: waybill number, both parties with tax-ID fields, Incoterm
`DDU`, reason for export `SALE`, and the line items. **Three Letter pages cannot go to the
4×6 thermal printer the label goes to**, which decides more of `07` than the question was
expected to — its middle option, printing the second document through QZ Tray, means a second
*printer*, not a second dispatch to the same one. Recorded there.

**Two preconditions PolyBag cannot satisfy, found in order:**

1. **`Missing harmonized system code` / `Missing country of origin`**, once per line item.
   These live on Shopify's `InventoryItem` — `harmonizedSystemCode`, `countryCodeOfOrigin` —
   and **cannot be sent in the purchase**: `ShippingLabelPurchaseInput` has seven fields and
   none is customs-related. They are catalogue data, set in the Shopify admin. PolyBag holds
   `country_of_origin` on its own `Product` but has nowhere to put it, and no way to see
   whether the Shopify catalogue is populated before trying.
2. **`UNKNOWN_ERROR`**, which turned out to be PolyBag's own bug and is now `19`: Shopify
   rejects an international label whose `totalWeight` is below the sum of its declared item
   weights, and PolyBag sends the scale reading. Isolated by varying one field at a time
   against the production input; the fix direction PolyBag already implements for its own
   carriers — scaling declared item weights *down* — is the one Shopify forbids.

Both failed **after packing**, with a Shopify-worded message in the first case and an
unactionable one in the second. That is the shape of the problem, more than either instance.

**A note on the free oracle.** `02`'s past-ship-date probe was what made this tractable: it
established a rate had resolved, so the failure was downstream of rate selection, before any
label was spent. Enumerating the Canada pairs found rates for `ups_shipping:11/07/08/65` and
`dhl_express:P` — and **no USPS or Canada Post rate for any spelling tried**, while the
admin's own rate list for the same parcel shows USPS First Class Package International,
Priority Mail International and Priority Mail Express International at $36.57, $48.81 and
$74.85. So those rates exist and the seeded `usps:` international codes do not name them.
That is a gap in `02`'s vocabulary, not an absence of rates, and it is recorded there.

### 2026-09-09 — correcting the raster finding: it is the carrier, not the source

Two comments above I recorded that Shopify rasterises when it renders for printing, and that
the API's document is text-bearing — with the confound stated: the text-bearing sample was
USPS and the rasterised ones were UPS.

**The confound resolves against that reading.** The international UPS label came from
`shippingDocuments[].url` — the API, not the print dialog — and it has zero fonts, zero
extractable text, and the same 1400×800 grayscale bitmap as the admin renders did.

So the axis is **carrier**: UPS labels are bitmaps and USPS labels are text-bearing, wherever
you fetch them. Shopify is not rasterising at print time; I compared a UPS admin render
against a USPS API document and read the wrong variable. The rule "take the label from the
API" survives on **page size** — the admin re-renders at the chosen page size at print time — but not as an
explanation of the text layer, and not as a way to make a UPS label readable.

The consequence for `11` is the one flagged as the worse branch: rung 2 cannot read a
Shopify UPS label at all, from any source. Corrected in `11` and `14`.

### 2026-09-09 — question 4 answered: one notification, and question 3's inference was wrong

`notify_customer` turned on for data source 7, package 208 shipped through the UI on order
#1241 — a routine domestic purchase, $5.97, USPS.

**One notification. No duplicate.** The order timeline carries a single event:

> Shipping notification scheduled to be sent to Test Customer (customer@example.com) on
> September 9, 2026, 10:28 am.

One fulfillment (`SUCCESS` / `FULFILLED`), one notification event, nothing repeated.

**Two things worth more than the answer itself.**

**1. Shopify schedules the notification for the `shippingDatetime` we send.** The purchase
input was built at 10:23:5x PDT and `buildPurchaseInput()` substituted `now() + 5 minutes`;
the notification is scheduled for 10:28. That is not a coincidence — the customer email fires
at the ship datetime PolyBag asks for, not at purchase.

Which gives **question 9 a consequence it was not written to expect**. It asks whether Shopify
rejects or silently substitutes a midnight value. There is now a third possibility: it accepts
it and schedules the customer's shipping email for then. After the 8 PM cutoff PolyBag sends
*tomorrow at 00:00*, so the shipping confirmation would be scheduled for midnight local. Worth
recording when that purchase is finally made — the question should now also ask *when the
notification lands*, not only whether the purchase succeeds.

**2. The answer is right for a reason nobody intended, and that reason is a bug.** Filed as
`20`. The second call site — `ShopifySource::exportPackage()`'s `fulfillmentCreate` — never
succeeds: Shopify closed the fulfillment order when it created the fulfillment itself, and the
export fails with *"Fulfillment order … has an unfulfillable status= closed."* There was never
a second notification to send.

**This corrects question 3 above.** Its answer records that the export "will meet an
already-fulfilled order and swallow it, which is the branch that was assumed and never
observed". The first half holds; the second does not. `ShopifySource::exportPackage()` swallows
only messages containing `already fulfilled`, and Shopify sends a different string, so the
package lands a `permanently_failed` export row instead. That branch has now been observed
rather than assumed, and it does not do what the code's own docblock claims.

**A note for whoever fixes `20`.** Making `fulfillmentCreate` succeed would **reopen question
4**, because both call sites would then run with `notifyCustomer` true. The answer recorded
here is conditional on the export failing.

**What is left on this issue: question 9 alone.** It needs a purchase whose ship date is a
future midnight. The cutoff is `pickup_cutoff_hour = 20` on carrier `Shopify` and location 1
is `America/Los_Angeles`, so either wait for 8 PM Pacific, or lower that hour so the same
branch fires now — Shopify only ever sees the resulting `shippingDatetime`, so how the date
was reached does not change what is being tested.

### 2026-09-09 — question 9 answered, and this issue is done

Shipment 6771, package 209, bought through the UI with `pickup_cutoff_hour` on carrier
`Shopify` temporarily lowered to `10` so the cutoff branch fired at 10:53 PDT rather than
waiting for 8 PM. Shopify only ever sees the resulting `shippingDatetime`, so how the date was
reached does not affect what was tested. `getShipDate("Shopify", 1)` returned
`2026-09-10T00:00:00-07:00`, which is in the future, so `buildPurchaseInput()` sent it rather
than substituting `now() + 5 minutes`.

**Shopify accepts a midnight `shippingDatetime`.** The purchase went through — $5.97, USPS, no
error, no `userErrors`, and nothing in the response or the timeline suggesting the value was
altered. So the third possibility the question worried about — silent substitution — does not
happen either.

**But the customer's shipping email goes with it.** The order timeline:

> Shipping notification scheduled to be sent to Test Customer (customer@example.com) on
> **September 10, 2026, 12:00 am.**

Which is exactly the `shippingDatetime` sent, confirming question 4's finding on a second and
much clearer case — a midnight value produces a midnight notification. In production, where
the cutoff is 8 PM, a label bought at 20:05 local schedules the customer's shipping
confirmation for four hours later at 00:00. That is defensible — the parcel does ship that
day — but it is a behaviour nobody chose, and it follows from a column with no UI.

**Scheduled is approximate.** The earlier purchase scheduled 10:28 and the email arrived
10:34. Six minutes. Worth knowing before anyone reads a post-midnight arrival as a date
rollover.

**`pickup_cutoff_hour` has no UI**, which this test made concrete. `CarrierForm` exposes only
`name` and `active`; the column is set by migration and seeder — 20 for USPS and Shopify,
`null` for FedEx and UPS — and changing it needs a database write. It decides the ship date
and, through it, when a merchant's customers are notified. Not filed as an issue here: the
`null` on two carriers is a question about intent, not an oversight to be papered over with a
form field.

## Done

Every question is answered or moved.

| | Question | Outcome |
|---|---|---|
| 1 | PDF or ZPL | **PDF**, both carriers |
| 2 | ZPL DPI | No ZPL setting exists; restated as page size, and the API document is always 4×6. No change |
| 3 | Purchase creates the fulfillment | **Yes** — and the export does *not* degrade safely, `20` |
| 4 | Customer notified twice | **No**, conditional on `20`; fixing it reopens this |
| 5 | `trackingInfo.company` | Shopify's internal code; `16`, fixed |
| 6 | Customs form | **Yes**, separate document — three Letter pages, not a label |
| 7, 8, 10 | | Moved to `17` |
| 9 | Midnight `shippingDatetime` | **Accepted**, and the notification is scheduled for it |

Five issues came out of it: `15` and `16` (both fixed), `19`, `20` and `21`.

`pickup_cutoff_hour` on carrier `Shopify` was restored to `20` immediately after the test.
