# Resolve an unresolved shipping offer by hand

Status: done — a notice on the package page with *Ask again* and *Record what I found*, found through a Packages filter linked from the Exceptions widget

Repo: `polybag`

## Parent

Split from [`14`](14-quote-direct-carrier-rates-behind-an-opaque-identifier.md) at triage,
2026-09-18. ADR-0002 decision 4's "spent, nothing confirmed" state.

## Problem

An offer consumed by `OfferStore::redeem()` whose purchase neither confirmed nor declined
— a timeout, a dropped connection — blocks every later purchase on its package:
`settleEarlierPurchases()` refuses with *Earlier Purchase Unresolved* until the seller
answers through `RecoversUnresolvedPurchase`. Only Amazon implements that contract, and
even Amazon's `recoverPurchase()` returns `null` — still unknown — whenever the question
itself fails to get through.

There is no UI for the state. `grep ShippingOffer app/Filament resources/views` finds
nothing. Clearing it means a database edit, and the packer's message tells them to
"check the carrier or channel for a label" without saying what to do once they have.
`PurgeData` keeps these rows forever on purpose, so the count only grows.

`14` keeps direct carriers out of the state (a non-recovering seller's timeout resolves
the offer as failed), so this is the Amazon path and any future recovering seller.

## Shape

Somewhere an administrator already looks — the package view, or a small *Unresolved
Purchases* list — an action per unresolved offer with two outcomes:

- **A label exists**: enter the tracking number (and the source's own reference if
  known); `recordPurchase()` and `markShipped()` from what was entered, so the package
  is shipped on the label that was actually bought.
- **Nothing was bought**: `recordFailure()` with the operator's name as the reason, so the
  package is free to be quoted again.

Both are audit-logged with who decided and what they entered. Neither asks the source
anything — the source has already been asked, by `recoverPurchase()`, and did not answer.

## Open questions

- Whether this lives on `ViewPackage` (one package, found by the packer who hit the
  refusal) or a list (every unresolved offer, found by whoever reconciles the seller's
  account). Probably both, with the list being the one that surfaces old rows.
- Whether a `ShipResponse` can be built from a hand-entered tracking number without
  lying about `postage_source` evidence — ADR-0003 decision 7 has `inferred` and
  `unknown` for exactly this.

## Decisions — 2026-10-02

Triaged and built the same day. What changed since the issue was written:

- **Two states, not one.** Besides an offer whose reply never arrived, a purchase
  is blocked by an offer the source *confirmed* whose Label was never saved
  (`OfferStore::boughtButUnrecorded()`). Both are now `ShippingOffer::unaccounted()`,
  and both are resolved here. The local database had one of the second kind.
- **USPS and UPS recover on their own since `18`.** This is the way out when
  recovery keeps answering `null`, when a channel offer's connection is gone, or
  when a confirmed sale was never saved.

What was built:

- **`UnresolvedPurchaseResolver`** with two outcomes, both audit-logged as
  `AuditAction::PurchaseResolvedByHand` with the user, the outcome, what was
  entered and an optional note. Neither asks the source anything.
  - *A label was bought.* The operator enters only the tracking number. Carrier,
    service, catalog service, price and source come from the offer, so the service
    is `confirmed`. The ship date is the day the offer was spent, in the location's
    timezone. There is no label image, so print and reprint stay hidden, as for a
    purged label. Refused when the package already has an active Label, or when a
    channel offer's connection is gone, because that Label could be neither voided
    nor tracked.
  - *Nothing was bought.* The offer is recorded as failed, naming the operator, and
    the package can be quoted again. Refused for a sale the source confirmed: that
    label exists, so the honest record is to record it and void it. The form says so
    beside the one option it offers, and names *Record Void* for a label already voided
    at the carrier, which un-ships the package without asking the carrier anything.
- **Answering the open questions.** Both places, as the issue guessed: a *Resolve
  earlier purchase* action on `ViewPackage` and an *Unresolved Purchases* page,
  linked from a new Exceptions widget stat rather than the navigation. Both are
  Manager and above, because settling as nothing bought lets the package be bought
  again. The tracking number is prefilled from what the source reported, or from a
  direct carrier's purchase reference. On evidence, the offer is what was quoted
  and paid for, so `confirmed` rather than `inferred` or `unknown`.
- **The refusal** a packer sees now points at the action.
- **USPS cancel-by-key** (`18`'s item 2) was not built. "Nothing was bought" records
  what a person found, and a refund for a label that does exist is a void after
  recording it.
- **USPS "key not found" is only believed inside USPS's look-back.** Found while
  building this: `160412` reads "Idempotency-Key not found for a mailing date within
  the last 7 days" in production (14 in TEM), and `18` treated it as a definite
  "nothing was bought" at any age. A package left unresolved past the window, then
  retried, would have been settled as not bought and bought a second label.
  `UspsAdapter::keyLookupCovers()` now reads the window from the error (7 days if
  absent) and believes it only for an attempt a day inside it. Anything older stays
  unresolved and comes here.
- **UPS gets the same guard, with a guessed window.** Label Recovery's `9801031`
  states no look-back, and the `18` probe only showed it finding a shipment seconds
  old. `UpsAdapter` now believes it only for an attempt under 7 days old, matching
  USPS production (`RECOVERY_TRUST_DAYS`). Older attempts stay unresolved and come
  here. Unverified: raise the window if a production case shows UPS finding older
  shipments.
- **FedEx is left off the list.** An unanswered FedEx purchase never blocks a
  package, because the next attempt settles it as failed, and FedEx bills only on
  tender. `UnresolvedPurchaseResolver::needingAPerson()` leaves unanswered offers on
  carriers that cannot be asked out of the list, the widget count and the package
  page action. A sale such a carrier confirmed but never saved stays, since a retry
  cannot settle a confirmed sale.
- **Amazon: a confirmed sale is fetched by its shipment ID, not replayed.** Recovery
  used to re-send `purchaseShipment` under the same idempotency key for both
  unresolved kinds, which depends on the old `requestToken` still being accepted.
  When Amazon has already confirmed the sale, the offer carries its shipment ID, so
  `AmazonBuyShippingService::documentsFor()` now calls `getShipmentDocuments`
  (`GetShipmentDocuments`) instead. It sends no `format` or `dpi` for an Amazon
  order's own postage, and sends the format the purchase would choose for Amazon
  Shipping on another channel. A tracking number the purchase reply reported is kept
  over the re-fetch's. An unanswered sale, with no shipment ID, still replays.
  Sandbox probe 2026-10-02 (`.scratch/amazon-shipping-v2/probe-16-documents.php`,
  `probe-16-documents.json`): bought the canned EXTERNAL parcel, then fetched it
  with the app's request both ways. Both returned 200 with a PNG `LABEL` that
  `labelFrom()` parsed, and the shipment was cancelled. The sandbox again answered
  with a different tracking number (`9007045147226` against the purchase's
  `TBA335137414166`) and different bytes, as `amazon-shipping-external-orders/01`
  saw. Not yet run on a production shipment.
- **Check again.** Recovery otherwise runs only on the next attempt to buy. The
  Unresolved Purchases list has a *Check again* row action for sources that can be
  asked (USPS, UPS, Amazon) that runs it now, through
  `PackageShippingWorkflow::checkEarlierPurchases()`, under the per-package purchase
  lock and with this workstation's label format. It buys nothing. A recovered label
  ships the package with its image; a definite "nothing bought" settles the offer;
  anything else stays on the list.

## Revised after use — 2026-10-02

The first cut was confusing in use: a separate list page held two overlapping row
actions (*Check again* asked the source, *Resolve* did not), the statuses were jargon,
and the page existed for something that should almost never happen. FedEx settles
itself, and USPS, UPS and Amazon settle on the next attempt to buy, so a package
reaches a person only when the source keeps failing to answer, the attempt is past
the lookup window, or a confirmed sale was never saved.

- **The list page is gone.** The Exceptions widget stat, now *Unfinished Label
  Purchases*, counts packages rather than offers and links to the Packages list
  filtered by a new *Unfinished label purchase* toggle.
- **One place to act: a notice at the top of the package page.** It says in plain
  words what happened ("PolyBag tried to buy a USPS Ground Advantage label for $8.40
  on …, but never heard back from USPS") and offers the two steps in order: *Ask USPS
  again* (the old *Check again*, which can bring the label back with its image), then
  *Record what I found* ("I found the label" / "There is no label"). Shippers see the
  notice and are told a manager has to settle it; only managers see the actions.
- **The Ship page refusal** is now titled *Unfinished Label Purchase* and says that
  trying again asks the source again, where the source can be asked.
- `ShippingOffer::sellerName()` names who to look it up with: the carrier, or Amazon
  or Shopify for a channel's postage.

- **USPS will not reprint past the mailing date.** Found the same evening on the local
  database's confirmed-but-unsaved USPS sale, three days old, with `sandbox_mode` on:
  TEM answered the reprint by idempotency key with `160981`, "Label is unavailable for
  reprint past its mailingDate of 2026-09-29". That is USPS's documented rule for
  reprint by key: labels "can only be reprinted up to the mailing date", "up to 3
  times", and not once cancelled. The mailing date is the ship date the purchase sent
  (`shipDate`, else today), so a label is recoverable through its ship date and no
  later. Production has been seen recovering only seconds after purchase (`18`), but
  the rule is documented, not inferred. Not handled: a label whose three reprints are
  spent. Its error code is unknown, so it would read as no answer; a label nobody saved
  has had no ordinary reprints, so this needs repeated recoveries to reach. The
  adapter used to read `160981` as "no answer", which offered *Ask again* forever and
  showed a refusal that sent the manager to the page they were on. Since the error itself says the label exists, it
  now throws `LabelNotRecoverableException`, a fourth answer beside the three
  `RecoversUnresolvedPurchase` names: the label exists and will not be handed over.
  The workflow keeps the reason on the offer (`OfferStore::UNRECOVERABLE_REASON`),
  the package stays blocked, the notice and refusal quote it, and *Ask again* is no
  longer offered: the way out is *Record what I found*, then a void.
- **A confirmed sale asks what to do, not what was found.** The source said it sold
  the label, so "can't find it" changes nothing; the form offered only "I found the
  label", which read as a dead end. It is now *Void it* (the default: recorded, then
  voided through the source in one step, `recordLabelAndVoid()`), *Keep it*, or *It
  was already voided* (recorded, then `recordVoid()`, which asks nothing). A purchase
  nobody heard back about gets the same three plus *There is no label*. A void the
  source refuses leaves the label recorded and active, to void again from the page.
- **USPS `DISPUTED` is a void.** USPS's cancel endpoint cancels a label until its
  Shipping Services File exists and opens a refund request after, answering
  `DISPUTED` with a `disputeId` (documented, not yet observed). The adapter read
  anything but `CANCELED` as a failed void, leaving a package shipped on a label whose
  refund had been requested, and a same-day retry is rejected as a duplicate. It now
  un-ships the package and names the dispute; whether USPS pays is its own decision.
- **The export listener skips a package no longer shipped.** Recording and voiding in
  one step queues a `PackageShipped` export that runs after the void; it now returns
  early rather than fulfill the channel with a voided tracking number. The same race
  already existed for a quick void at the bench.
- **Fixed on review.** Every by-hand resolution now holds the package's purchase lock
  (`PurchaseLock`, shared with purchase and *Ask again*), so a purchase still in flight
  cannot be settled as nothing bought underneath it. A label recorded to be voided, or
  recorded as already voided, is not announced (`markShipped(announce: false)`) and is
  written as `exported`, which holds it from every export path, the scheduled
  `packages:export` included. It is released (`Package::releaseForExport()`) only
  when the source refused the void. Not when the source voided it and PolyBag failed
  to record that (`LabelVoidResult::$voidedAtSource`), and never for "already
  voided". A source saying the label exists
  (`UNRECOVERABLE_REASON`) counts as a sale with or without a reply
  (`ShippingOffer::isKnownSold()`), so "nothing bought" is refused for it. An Amazon
  label recorded by hand keeps the shipment, carrier and service identifiers that
  voiding and tracking read (`AmazonBuyShippingAdapter::labelMetadata()`). The
  dashboard cache invalidation now clears the Exceptions widget's `v3` key, and a
  resolution clears it too.
