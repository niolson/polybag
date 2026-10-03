# Find out whether preferredRateSelection works, and catalog the codes that do

Status: done — 2026-09-09; second pass 2026-09-17; UK pass 2026-10-03

Repo: `polybag`

## Problem

`ShippingLabelPurchaseInput.preferredRateSelection { carrierCode, serviceCode }` is how a
specific carrier and service would be requested. Two things were unknown: **whether it is
honored at all** (a community report held that Shopify ignores it outright), and **what
service codes are valid** — Shopify publishes no list and the schema cannot enumerate them.

## What was found

**1. It is honored.** A deliberately invalid service code comes back synchronously with a
`RATES_NOT_FOUND` user error, no purchase result, and the fulfillment order untouched. The
community report does not hold against the 2026-07 API.

**2. There is a free oracle for the whole vocabulary.** Shopify resolves the preferred rate
*before* it validates the rest of the input, so a selection sent alongside a **ship date in
the past** reports whether a rate matched, without buying:

| Reply | Means |
|---|---|
| `RATES_NOT_FOUND` | no rate matched this carrier/service pair |
| `SHIPPING_DATE_IN_THE_PAST` | a rate **did** match; the purchase then failed on the date |

Neither buys a label and neither closes the fulfillment order, so one order can be probed
indefinitely. The rig is in `.scratch/`; it builds the input through
`buildPurchaseInput()` by reflection, so what is probed is byte-for-byte what production
sends.

**Pick the poison carefully.** A zero `totalWeight` looks like the obvious choice and is the
wrong one: weight is an *input to the rate lookup*, so it produces `RATES_NOT_FOUND` for
every pair including the valid ones, and reads as a universal miss. That false negative cost
the first hour. The ship date is inert to the rate engine, which is what makes it usable.

**3. There is no Shopify vocabulary — only each carrier's own, passed through.** True of the
three US carriers probed here; **not** of the UK carriers, whose codes are Shopify's own
snake_case keys — see *The UK pass* below.

- **USPS:** PascalCase, matched **case-sensitively**. `Priority` finds a rate, `priority`
  does not. `GroundAdvantage`, `Priority`, `PriorityExpress`, `MediaMail`.
- **UPS:** UPS's own numeric codes, the same alphabet already seeded under the `UPS` carrier.
- **DHL:** DHL's own product codes — `P` for Express Worldwide.

`usps_ground_advantage`, the spelling this issue proposed and `ShopifyAdapterTest` used as
its example, finds no rate. Neither does `snake_case`, `SCREAMING_SNAKE`, `kebab-case`,
spaced title case, or the carrier-prefixed forms. This changes how a new carrier should be
attacked: not "what would Shopify call this" but "what does this carrier call it", and the
answer is usually published by the carrier.

**4. The carrier code is not validated separately.** `not_a_carrier:GroundAdvantage` returns
plain `RATES_NOT_FOUND`, exactly like a bad service code. `CARRIER_NOT_AVAILABLE` lives in
the *asynchronous result* enum, reachable only after a purchase is enqueued. So there is no
way to tell a wrong carrier from an unavailable service from the reply — which is the
structural limit on discovering an unknown carrier: both halves have to be guessed
correctly at once, with one undifferentiated error for every near miss.

**5. "No rate" is a fact about the parcel, never about the vocabulary.** UPS `92` matches
and `93` misses on a 0.3 lb parcel; at 5 lb they swap — the SurePost weight split
`CarrierSeeder` already documents, reproduced exactly by Shopify. So every negative is
disproved *for the parcel it was probed with* and nothing more.

**6. The purchase settles it.** A label bought through the production path with
`usps:PriorityExpress` came back priced an order of magnitude above what `auto` had been
choosing from the same inputs.

## What shipped

**Twenty-one `carrier:service` pairs** seeded under the `Shopify` carrier — seven USPS,
thirteen UPS, one DHL — plus `auto`, which stays the default and sends no selection.
`ShopifyAdapter` splits on the colon; anything without one leaves the choice to Shopify.
`ShopifyAdapterTest`'s example moved onto confirmed codes so the file stops teaching a
spelling that finds no rate. Seventeen shipped 2026-09-09; the three USPS international
codes and UPS `14` were added 2026-09-17 — see *The second pass* below.

**The admin's own *Preferred services* screens are the denominator** this issue never had —
25 services across three carriers, of which **21 are mapped**. The four that remain are
accounted for rather than open:

| Admin lists | Status |
|---|---|
| USPS First Class Mail | `usps:First` finds a rate — **but only with `packageInfo.customPackage.type: ENVELOPE`**, and neither `BOX` (what production sends) nor `SOFT_PACK` rates it. It is the letters-and-flats product, and PolyBag's box types are Box, Polybag and Padded Mailer, none of which is an envelope. Not seeded: it would be an offer that never finds a rate. |
| USPS First Class Package | Retired by USPS in July 2023, folded into Ground Advantage. The admin's list is stale. Nothing will rate it. |
| USPS Parcel Select Ground | Same — retired July 2023 into Ground Advantage. `ParcelSelectGround`, `ParcelSelect`, `RetailGround` all miss at 3.1 lb and 25 lb, where Ground Advantage matches. |
| UPS `54` Worldwide Express Plus | Toggled on in the admin; no rate on Montréal, Poland or Singapore. The one still genuinely unresolved — Express Plus is a postal-code-limited product and the only international fulfillment order on hand may simply be outside its footprint. |

**One thing deliberately not done.** `ShopifyAdapter` still records `service: null` and
`ServiceEvidence::Unknown` for every purchase, including one made with an explicit pair
Shopify is now known to honor. That is a weaker claim than the evidence supports, and it
belongs to `11`'s inference ladder rather than here. Raised there — and built there
2026-09-16 as the ladder's third rung, which fills in where the tracking number and label
decline and treats a decode that disagrees with an honored pair as a reason to resolve
nothing.

## The negatives, so nobody probes them twice

Twenty-nine further spellings missed against the same domestic parcel, with a control run
confirming the rig still returned the known hits — **USPS:** `FirstClassPackageService`,
`ParcelSelectLightweight`, `ParcelSelectGround`, `Ground`, `Standard`, `Retail`,
`RetailGround`, the four `…Cubic`, the four `…Return`, `ConnectLocal`, `ConnectRegional`,
the two `…HFP`; **UPS:** `14`, `M2`–`M7`, `70`–`72`. Read these as "not offered for this
parcel on this store", not "no such code" — the `92`/`93` split is the standing warning.

**Canada Post** missed on five spellings including its own `DOM.RP`/`DOM.EP`/`DOM.XP`
product codes — expected for a US-origin shop, and consistent with it having no *Preferred
services* screen here at all.

**UPS `14` was a false negative** on the first pass: it missed on a 0.3 lb parcel to
Washington DC 20515 on 2026-09-09, and matched on the same destination at 3.1 lb and on
Greenwich NY 12834 at 0.15 lb on 2026-09-17. Read the first miss as one more instance of
rule 5, not as a service that appeared in the meantime.

**A probe caveat.** A shipment whose destination address carries no province returns
`Select a region` — a user error with a **null code** — for every pair, because the address
fails before the rate resolves. A screen of those looks nothing like `RATES_NOT_FOUND`.
Check the destination is complete before reading a sweep as a result; the rig prints unknown
error codes verbatim for exactly this reason.

## The second pass: USPS international, resolved

Twenty spellings across Poland and Singapore missed, then nine more on Canada, while UPS and
DHL matched on the same shipments and USPS domestic worked in the same session. That read as
"this shop is not offered USPS international rates at all" — and the admin's own rate list
for a Canada parcel (First Class Package International $36.57, Priority Mail International
$48.81, Priority Mail Express International $74.85) showed the reading was wrong.

**The codes are the full USPS product name in PascalCase**, confirmed 2026-09-17 against
shipment 6770 / package 215 (2.30 lb, Montréal QC):

| Admin name | `serviceCode` |
|---|---|
| USPS First Class Package International | `usps:FirstClassPackageInternationalService` |
| USPS Priority Mail International | `usps:PriorityMailInternational` |
| USPS Priority Mail Express International | `usps:PriorityMailExpressInternational` |

Two details of the vocabulary that the domestic table did not predict: international keeps
the "Mail" that domestic `Priority` drops, and the trailing "Service" is load-bearing —
`FirstClassPackageInternational` misses where `…Service` hits.

**The 2026-09-09 negatives for these exact spellings were wrong about the parcel, not the
vocabulary.** All three were in the Canada list that day and came back `RATES_NOT_FOUND`.
The order probed then was almost certainly one where USPS returned nothing for its own
reasons — the box-weight failure that became `19` is the likeliest — and rule 5 applied to
this issue's own findings: a negative is only ever about the parcel it was probed with. The
control `usps:Priority` misses on the Canada parcel, so the oracle does discriminate there.

**The USPS international API's `mailClass` enum is not the vocabulary.** The question was
whether international codes might be encoded differently because USPS serves them from a
different API. `PRIORITY_MAIL_INTERNATIONAL`, `PRIORITY_MAIL_EXPRESS_INTERNATIONAL` and the
hyphenated `FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE` all miss on the parcel where the
PascalCase names hit — consistent with the domestic finding that `PRIORITY_MAIL` misses
where `Priority` hits. Whatever Shopify calls USPS, it is one mapping layer for both.

**Further negatives on the same Canada parcel**, so nobody probes them twice:
`PriorityMailIntl`, `ExpressMailIntl`, `FirstClassMailIntl`, `FirstClassPackageIntl`,
`PriorityIntl`, `PriorityExpressIntl`, `ExpressIntl`, `FirstClassIntl`,
`InternationalFirstClass`, `InternationalExpress`, `PMI`, `PMEI`, `FCPIS`,
`FirstClassPackageInternational`, `FirstClassMailInternational`, `FirstClassInternational`,
`FirstClassPackageServiceInternational`, `GroundAdvantageInternational`.

The probe lists are `canada2.json` and `canada3.json` in the rig, and `enumerate.php` now
takes a fourth argument overriding the package type, which is what found `usps:First`.

**One thing this turned up that is not this issue's to fix.** `buildPurchaseInput()` sends
`type: BOX` for every package, and Shopify's rate engine does look at the type — `usps:First`
rates only under `ENVELOPE`. PolyBag knows a box size's type (`BoxSizeType`: Box, Polybag,
Padded Mailer), and the latter two are plausibly Shopify's `SOFT_PACK`. Whether that changes
any rate PolyBag can actually buy is untested; noted here so the `BOX` constant is not
mistaken for a fact about the parcel.

## The UK pass: Shopify's own keys, read from the admin

2026-10-03, against shipment 6981 / package 235 (0.35 lb, 4×6×6 box), London E12 to London
NW9, from a London location on the development store. The rig's `enumerate.php` now takes a
fifth argument, a JSON file merged over the origin address, because PolyBag's origin for
this order is still the Woodinville location.

**Guessing failed outright.** Thirty-six spellings missed — Royal Mail's own `TPN`/`TPS`
product codes, `Tracked24`, DHL's `N` under seven carrier names, and so on — while
`dhl_express:P` matched on the same package from the US origin, so the oracle was working.

**The admin's own traffic gave the answer.** On the order's *Shipping service* screen,
DevTools → Network shows two admin GraphQL responses that settle a carrier without guessing:

- `availableShippingCarriers` — every carrier code the shop can see: `usps`, `dhl_express`,
  `ups_shipping`, `fedex`, `canada_post`, `ups_ca`, `purolator`, `hermes_uk`, `dpd_uk`,
  `yodel`, `royal_mail`, `dhl_express_uk`. It includes `fedex`, which the label API does not
  sell, so presence here proves nothing about the API.
- `shippingRates` — each rate offered for the order, with its `carrierCode` and
  `serviceCode` beside the admin's display name.

UK service codes are **snake_case, prefixed with the carrier code** — nothing like the
carrier's own product codes. Six of the nine rates the admin offered match through
`preferredRateSelection`:

| Admin name | `carrier:service` |
|---|---|
| Royal Mail Tracked 24® | `royal_mail:royal_mail_tracked_24` |
| Royal Mail Tracked 48® | `royal_mail:royal_mail_tracked_48` |
| Royal Mail Tracked 24® with Signature | `royal_mail:royal_mail_tracked_24_with_signature` |
| Royal Mail Tracked 48® with Signature | `royal_mail:royal_mail_tracked_48_with_signature` |
| DPD UK Next Day | `dpd_uk:dpd_uk_next_day` |
| DHL Express Domestic | `dhl_express_uk:dhl_express_uk_express_domestic` |

**UK DHL is a separate carrier**, `dhl_express_uk`, not `dhl_express` with a UK origin.

**Evri and InPost do not match through the oracle — and the oracle is what is wrong.**
`hermes_uk:hermes_uk_parcelshop_dropoff`, `…_dropoff_nextday` and
`yodel:yodel_direct_store_to_door` (InPost UK trades under Yodel's carrier code) return
`RATES_NOT_FOUND` with a past ship date under every variation tried, while Royal Mail matches
as the control: a UK phone and none, `SOFT_PACK` and `ENVELOPE`, 2 lb and 5 lb, the admin's
own 8.6 × 5.4 × 1.6 in sample box at its 5.88 lb, other carrier spellings, 46 of
ShipStation's own Yodel and Evri codes (Shopify's UK labels come from Auctane, its owner —
`14`), and the admin's composite rate key (`yodel-yodel_direct_store_to_door`, which misses
for Royal Mail too). The admin's own purchase request for InPost sends exactly
`yodel` / `yodel_direct_store_to_door`.

Then the same pair was sent **with a real ship date** (shipment 6983 / package 238) and was
enqueued and `PURCHASED` — an InPost label, tracking company `yodel`. A pair that does not
match is refused synchronously with `RATES_NOT_FOUND` and never enqueued (finding 1), so the
selection was honored.

**So the oracle has a blind spot: for a drop-off service, the ship date is not inert.**
Presumably the rate exists only for days the parcel can be dropped off, so a past date reads
exactly like an unknown code. Finding 2's premise — "the ship date is inert to the rate
engine" — holds for every US carrier and for Royal Mail, DPD UK and DHL Express UK, and is
false for InPost. **Evri is assumed to behave the same, unconfirmed** — proving it costs a
purchase and a fulfillment order. The consequence for every future carrier: an oracle miss
on a drop-off, locker or parcel-shop service proves nothing, and the only test is a
purchase.

**Not offered for this parcel, so their misses mean nothing yet:** the other services on
the admin's *Preferred services* screens — Evri Shop to Shop (and Next Day), DPD UK Two Day,
Classic, Air Classic and Air Express, Royal Mail Tracked 24/48 with Local Collect, DHL
Express Domestic Pickup Point, Express Worldwide and Express Worldwide Pickup Point. Guessed
in the same pattern (`dpd_uk:dpd_uk_two_day`, …) and all missed; read rule 5, and for the
drop-off and pickup-point ones, the blind spot above as well.

**Two side findings.** An origin with no phone returns `FULFILLMENT_ORDER_INVALID` for every
pair — an error about the wrong field, with the fulfillment order untouched. And whether the
US carriers *also* answer to snake_case keys of this kind is untested: `usps_ground_advantage`
missed on 2026-09-09, but that was a guess, not a key read from the admin.

**Not seeded.** PolyBag buys from the Package's Location address, so these pairs are
unbuyable until a UK Location exists to send them from. The probe lists are `uk.json`,
`uk-admin.json` and `uk-round2.json` in the rig.

## Spun out

**`18`** — probing turned up that a voided label leaves its fulfillment order `CLOSED`, so
the packages `01` voided could never buy another Shopify label, contradicting a docblock in
shipped code.

## Related

- `01` — the purchases that unblocked this
- `14` — the capture campaign this is the instrument for
- `11` — where the "an honored selection is evidence" question belongs
