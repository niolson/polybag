# Build the Amazon Buy Shipping adapter

Status: done

Repo: `polybag`

## Problem

The adapter itself: quote through `getRates`, purchase through `purchaseShipment`, void
through `cancelShipment`, track through `getTracking`.

Both reasons this was `needs-info` are now discharged. `01` established what is on offer —
three carriers eligible simultaneously against a 105-entry ineligible catalog — and
`postage-source-split/08` shipped the seam, so an adapter written now keeps Amazon's per-offer
`carrierName` instead of discarding it, which is the whole reason for the split.

What remains is ordinary sequencing, not missing information: `02` owns the offer and
observed-service stores this adapter reads and writes, so it stays blocked on `02` rather than
on an unanswered question.

## What to build

Notes that predate `01` and survived it unchanged:

- **Credentials** come from the shipment's own Amazon `DataSource`, not a `CarrierAccount` —
  the order lives in that seller's account, and per-client sources are how 3PL scoping already
  works. Amazon Shipping on non-Amazon orders is the opposite case and is out of scope here.
- **`directPurchaseShipment`** is the better fit for pre-selected rates, batch ship and
  auto-ship: one call, no token to expire.
- **Rate limits** are 80 rps / burst 100, so batch ship is not constrained.
- **Buying a label auto-confirms the Amazon order.** `AmazonSource::exportPackage()` calls
  `ConfirmShipment` for every Amazon package and Ship+ orders 400 on a manual confirm, so the
  export must skip it when the label came from Buy Shipping — gate on the stored Amazon
  `shipmentId`.
- **Order item IDs** for the request already exist: `PackageExportService::buildAmazonExportContext()`
  builds them from `packageItems.shipmentItem.source_item_id`. It is private and throws
  `PermanentExportException`; extract rather than duplicate.
- **Labels** are base64 with a format that must be one of the chosen rate's
  `supportedDocumentSpecifications` — the *purchase* fails otherwise, not the quote. PNG is not
  a format our printing path supports. Request 4x6 INCH explicitly.
- **Every rate this adapter returns must carry an `ObservedServiceIdentity`** on its
  `RateResponse`. That is what `07` gates automated purchase on, and it is the only thing that
  tells a discovered service apart from an authored `CarrierService` at selection time — a rate
  that arrives without one is treated as authored configuration and can win `selectBest()`
  unapproved. Build it from the same `(source, environment, carrierId, serviceId)` the adapter
  is already handing `ObservedServiceRecorder`, so the two cannot disagree.

## Acceptance criteria

- [x] Rates appear alongside direct-carrier rates with the real carrier per offer
- [x] Purchase, void and tracking work end to end against a real order — three purchases,
      two voids and a `pre_transit` tracking read on 2026-09-11, see the comment below
- [x] The channel export does not double-confirm a Buy Shipping shipment
- [x] Request bodies validate against the vendored Shipping v2 schema, following the pattern
      in `tests/Fixtures/Schemas/`
- [x] An expired offer re-quotes rather than failing the packer
- [x] Every returned rate carries its observed-service identity, so `07`'s approval gate applies

## Blocked by

- `02-specify-observation-and-offer-stores`
- `postage-source-split/08-split-carrier-adapter-interface`

## Comments

### 2026-09-04 — retriaged `needs-info` → `ready-for-agent`

The `needs-info` state was stale. It was set pending `01`, which returned on 2026-09-02 with a
GO, and pending the postage-source seam, which shipped as `postage-source-split/08`. Nothing
here is waiting on information any more — only on `02`, which is already recorded under
**Blocked by** and is the ordinary kind of dependency.

Left as `ready-for-agent` rather than started, so the blocker is expressed in one place.

## What shipped

`AmazonBuyShippingAdapter`, registered in `CarrierRegistry` as **Amazon**, quoting through
`AsyncRateQuoting` so it goes out concurrently with the direct carriers. Beside it,
`AmazonBuyShippingService` (Amazon's vocabulary: payloads, tokens, document specs) and
`AmazonPostageSource` (void, track, manifest eligibility). Requests: `PurchaseShipment`,
`CancelAmazonShipment`, `GetShipmentTracking`, alongside the `GetShippingRates` that `01`
left behind.

Quoting and purchasing are now off carrier-name dispatch, which discharges
`postage-source-split/08`'s deferral: `PostageSourceDispatcher::sellerFor()` resolves who to
ask from the offer's own `postage_source`, and `unsupportedDispatch()` — the guard `08` and
`02` both said this issue would delete — now means "no source sells this any more" rather
than "not built yet". `ShipRequest` gained the `ShippingOffer`, which is the other half of
what `02` said belonged here: the opaque tokens reach the adapter from the store rather than
from round-tripped browser state.

### `purchaseShipment`, not `directPurchaseShipment`

The pre-`01` note preferred `directPurchase` for "one call, no token to expire". Reading the
spec against what `01` found kills it: `DirectPurchaseRequest` has **no `rateId`** — its body
is addresses, packages and a channel, and Amazon picks the carrier and service itself. That
is a blind purchase in this codebase's terms. It cannot buy the offer a packer looked at, it
cannot buy the one `07`'s approval gate cleared, and it throws away precisely the choice `01`
went to production to establish existed (three carriers, priced independently, for one
parcel).

So the ten-minute window is accepted and tracked, which is what the offer store was built
for. Offers expire at **eight** minutes rather than ten, so a packer is sent back for a fresh
quote instead of into a `TOKEN_EXPIRED`.

### The document specification is per rate, and the note about it was half right

"Request 4x6 INCH explicitly" is right, but only because a 4x6 entry happens to exist. The
production capture shows `supportedDocumentSpecifications` listing **PDF twice** — 8.5x11
*and* 4x6 — plus ZPL at 4x6/300 DPI and a PNG we cannot print. A naive "first PDF" prints
letter-size labels on a 4x6 printer. So the spec is chosen from the chosen rate's own list,
preferring 4x6 INCH, taking the requested DPI only when that rate published it. The list is
stored on the offer's `rate_metadata`, because re-quoting to find out what a rate offered
would invalidate the token being spent.

A rate offering neither PDF nor ZPL is dropped at quote time. That failure is a *purchase*
failure at Amazon, so catching it after the money is not catching it.

### Value-added services, and where decision 8 actually lands

`availableValueAddedServiceGroups` is per rate and `01` found the Confirmation group marked
`isRequired` on every UPS and USPS offer and **absent from OnTrac's**. Two consequences:

- `offerCapability()` answers `Supported` for signature and adult signature, and the
  per-*rate* judgement happens after the quote: an offer that cannot honour a hard-required
  service is dropped, and the offers beside it are not. Answering at `offerCapability()`
  would have excluded Amazon wholesale for a service most of its offers carry, which is the
  conflation ADR-0002 decision 8 exists to undo.
- A required group has to be answered, so a purchase with nothing requested sends
  `NO_CONFIRMATION` explicitly rather than omitting the group.

### Recovery is real, and it is one call

`02` said `03` would turn "spent, nothing confirmed" into an actual recovery call.
`purchaseShipment` accepts `x-amzn-IdempotencyKey`, and the offer's public identifier is sent
as it — so a repeat is recognized as the same purchase and answered with the shipment Amazon
already made. `RecoversUnresolvedPurchase` is the contract; the workflow asks before
refusing, and a recovered label ships the package it was already paid for.

**Only success resolves the offer.** `TOKEN_EXPIRED` on the retry is consistent both with a
purchase that never happened and with one that did, if Amazon validates the token before
replaying the key. The two mistakes are not symmetrical — guessing wrong buys a second
label — so the ambiguous answer stays ambiguous and the package stays blocked. Implementing
this contract is a claim that a repeated request is recognized as the same purchase; a source
without an equivalent must not implement it, because the question and the second purchase
would be the same call.

### An expired offer re-quotes

`OfferRejection::requiresRequote()` is true for every rejection but `AlreadyConsumed`, and
the Ship page acts on it: it drops the rate cache and re-prepares, so the packer is looking
at a current list by the time they read the message. `AlreadyConsumed` deliberately does not
— a label may exist for that offer, and re-quoting would invite a second one.

The unattended paths have nobody to show a list to and still just fail, which is why the
wording on the enum stayed an instruction rather than a report.

### Naming, and what an offer is filed under

A rate uses the `CarrierService` its identity has been mapped to (`05`) when there is one —
carrier name, service code and service name — and Amazon's own strings when there is not.
That is what lets a shipping rule written against Ground Advantage match whichever source
quoted it. The `ObservedServiceIdentity` on the rate is unchanged either way: approval stays
keyed on what Amazon called it, so naming a service is not approving it.

`selectedRateIndex()` now matches on the offer identifier first, which is the collision
ADR-0002 decision 4 named and `02` flagged: the same carrier and service code can arrive
twice in one list, once direct and once resold, at different prices.

### Other decisions worth stating

- **`$serviceCodes` is ignored.** `getRates` is one call returning whatever the order is
  eligible for; there is nothing to filter before. Post-quote filtering is the stopgap `08`
  names, and `08` stays open.
- **One seeded `CarrierService`** under a new `Amazon` carrier, `AMAZON_BUY_SHIPPING`. Not a
  service — it is how a shipping method says "ask Amazon", the same shape as Shopify's
  `auto`. The adapter never reads it back. Nothing else is authored from a response.
- **`AmazonOrderItems`** extracts what `PackageExportService::buildAmazonExportContext()` did
  privately, and adds the Shipping v2 `Item` shape beside the `confirmShipment` one, so the
  all-or-nothing completeness rule has one home. Its exception is
  `MissingAmazonOrderItemsException extends PermanentExportException`, so export behaviour is
  byte-identical and the adapter can catch the narrower type to *decline an offer* rather
  than fail an export.
- **The export gate is on the stored `shipmentId`, not the postage source**, and it is set in
  sandbox too. What matters is that Amazon bought this label; a package re-pointed at another
  data source keeps the ID and correctly stays unconfirmed. It is also checked *before*
  credential validation: there is nothing left to send, so a seller who rotates credentials
  after buying must not end up with a correctly shipped package that can never export.
- **The purchase uses the account the offer was quoted on**, read off
  `shipping_offers.postage_data_source_id` rather than the shipment's current source. The two
  come apart when a shipment is re-pointed at a second Amazon account between quote and
  purchase, and account A's `requestToken` would then go out under account B's credentials
  while the package recorded A as provenance. Amazon would presumably refuse, but a refusal
  that depends on Amazon happening to check is not the guarantee. An offer whose account is
  gone is refused, which settles it: nothing was bought.
- **The recorded label DPI is the one the purchase asked for**, not the one Device Settings
  prefers. They differ whenever the chosen rate did not publish the configured resolution —
  `01` found ZPL offered only at 300 — and recording 203 against 300 DPI bytes prints the
  label at the wrong physical size and misleads every later reprint.
- **`insuredValue` is zero unless the shipment asked to declare a value.** The field is
  required by the schema, and defaulting it to the contents value would buy coverage nobody
  chose, on every parcel.
- **`shipDate` is omitted from `getRates`.** Amazon computes the promise from now; our ship
  date is our own pickup policy's answer, not a constraint on theirs. The `01` capture omitted
  it too.
- **`AmazonShipping_US` is sent on every request.** Omitted, Amazon defaults the header to
  `AmazonShipping_UK`. Every marketplace `AmazonMarketplace` knows is North America, where US
  is the only shipping business, so there is nothing to derive.

### Testing

`tests/Feature/AmazonBuyShippingTest.php`, 22 cases, built from the shapes in the `01`
capture — OnTrac cheapest with no Confirmation group, UPS with a required one, an ineligible
4PX entry whose reason code is `UNKNOWN`. `GetRatesRequest` and `PurchaseShipmentRequest` are
validated against the newly vendored `tests/Fixtures/Schemas/shippingV2.json`, and the
README there records the two things that schema cannot catch (document spec against the
rate, required VAS groups) because both fail the purchase rather than the quote.

`PostageSourceDispatchTest`'s "a postage source it has no integration for" case used
`AmazonSource` as its stand-in and now uses `DatabaseSource` — Amazon stopped being an
example of the case.

### 2026-09-11 — live run on a fresh order: purchase, void and tracking all work

An unshipped order was placed in the seller account (the store was taken out of vacation
mode for the evening) and run through the app end to end. Three purchases, two voids —
identifiers in `.scratch/amazon-shipping-v2/live-run-2026-09-11.md`.

**The fresh-order quote is nothing like the old-order ones.** 32 eligible offers across
four carriers — OnTrac, USPS, UPS and, for the first time, **FedEx** — with ground services
qualifying: OnTrac Ground $5.53, USPS Ground Advantage $5.68, GA Cubic $6.48, UPS Ground
$7.42. `01`'s Express-only skew was the expired promise, as suspected.

| # | Offer | Format asked | What came back | Void |
|---|---|---|---|---|
| 1 | OnTrac Ground $5.53 | ZPL @300 (device is 203; rate offers 300 only) | one `LABEL`, ZPL, 1056 bytes — tracking, "Ship To:", "ONTRAC", barcode, **no address block** | `cancelShipment` OK |
| 2 | OnTrac Ground $5.53 | PDF 4×6 (`PACKSLIP`+`LABEL`, joined) | one `LABEL`, PDF, **two 4×6 pages: page 1 blank, page 2 the pack slip** | `cancelShipment` OK |
| 3 | USPS Ground Advantage $5.68 | PNG 4×6 | one `LABEL`, PNG 1200×1800, complete — from, to, barcode | kept |

Findings, each of which changed the adapter the same day:

1. **The joined PDF is one file, label page first, pack slip page second, both 4×6.** Both
   pages go to the label printer. There is no way to ask for the PDF without the pack
   slip (every spec offers joining as `[true]` only, `10`), and nothing in this stack
   splits a PDF. So PDF is now the *last* format asked for. PNG carries the label alone,
   has no DPI list, and prints through the pixel path UPS's GIF labels already use — the
   "PNG is not supported by our print path" note in this file was wrong. Order is now
   ZPL-at-device-DPI → PNG → PDF for a ZPL workstation, PNG → PDF for a PDF one.
   Recorded as `label_format = image`.
2. **Raw ZPL at a resolution the device does not print is unprintable**, not "scaled by
   the printer" as the code said: the workstation print path refuses the job, and the
   package sat shipped with a label nobody could print. Purchase 1 was that. The
   fallback above is what fixes it; a rate offering only ZPL at the wrong resolution is
   refused at purchase, before any money is spent, rather than bought.
3. **OnTrac labels come back without content**, in both formats, and Seller Central's own
   reprint shows the same blank PDF. USPS from the same order rendered fully. Amazon-side,
   for this account at least. Spun out as [`11`](11-ontrac-labels-via-amazon-are-blank.md).
4. **A void left `amazon_shipment_id` on the package.** `clearShipping()` does not touch
   metadata, and that key is what the channel export reads as "already confirmed, skip".
   A re-ship on a direct account would have skipped the confirmation.
   `AmazonPostageSource::voidLabel()` now strips the Amazon identifiers on success, the
   way Shopify's void sync strips its label IDs.
5. **A print failure left the Ship page open with the rate list and Ship button live**, on
   a package already shipped, and nothing refused a second purchase. Confirmed by test
   that a second Ship with another rate would have bought a second label. The workflow
   now refuses a shipped package from the database, and the page renders a "Label
   Purchased" panel with a Print again action instead of the rates.
6. **The scheduled import never filled barcodes** — only the historical import asked the
   catalog. The order's product arrived with none and had to be packed by hand. Every
   import now looks up barcodes, for products that lack one.
7. `getShipmentDocuments` refuses `format`/`dpi` for on-Amazon shipments ("same options
   selected in Purchase call will be applied") — it only ever returns what was bought,
   so it is a reprint source, not a way to get another format.

Not exercised: the channel export's confirm-skip (`export_enabled` was off on the local
source). It stays covered by test; the order was confirmed by the purchase regardless.

### Still open

- **No successful live run — and the first attempt found the body invalid.** On 2026-09-11
  `purchaseShipment` was sent live for the first time (`09`) and Amazon refused the
  document specification and then the value-added services before looking at the order:
  `needFileJoining` is hard-coded `false` where every rate offers only `true`,
  `requestedDocumentTypes` omits the `PACKSLIP` every PDF spec marks mandatory, and USPS's
  required Confirmation group has no `NO_CONFIRMATION` to answer with. Both are in the two
  fields the schema README said the schema cannot catch. Spun out as
  [`10`](10-purchase-body-is-refused-by-amazon.md) with the rules established by oracle.
  `10` was fixed the same day and the adapter's body now passes Amazon's validation. With a
  valid body the purchase was refused because the order had already shipped ("doesn't exist
  in Rigel"). *Superseded the same evening by the live run above.*
- **`accountNoLongerResolves()` is still a comparison rather than plumbing.** `ShipRequest`
  now carries the offer, so the account *could* be named — but making adapters honour it
  means touching `ResolvesCarrierAccount` in all three direct adapters, which is a
  direct-carrier change with no Amazon in it. Left where `02` put it.
- **The 60-second rate cache and the eight-minute offer window still only coincide.** `02`
  asked for them to be reconciled deliberately. The cache is far shorter than the window so
  nothing misbehaves today, and redemption fails closed if it ever does.
- **`08-servicecodes-constraint-decision`** is unchanged by this and still `needs-triage`.
  Post-quote filtering is what shipped, which is the option it names.
