# Find out whether preferredRateSelection works, and catalogue the codes that do

Status: done — 2026-09-09

Repo: `polybag`

## Problem

`ShippingLabelPurchaseInput.preferredRateSelection { carrierCode, serviceCode }` is how a
specific carrier and service would be requested. Two things were unknown: **whether it is
honoured at all** (a community report held that Shopify ignores it outright), and **what
service codes are valid** — Shopify publishes no list and the schema cannot enumerate them.

## What was found

**1. It is honoured.** A deliberately invalid service code comes back synchronously with a
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

**3. There is no Shopify vocabulary — only each carrier's own, passed through.**

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

**Seventeen `carrier:service` pairs** seeded under the `Shopify` carrier — four USPS, twelve
UPS, one DHL — plus `auto`, which stays the default and sends no selection. `ShopifyAdapter`
splits on the colon; anything without one leaves the choice to Shopify. `ShopifyAdapterTest`'s
example moved onto confirmed codes so the file stops teaching a spelling that finds no rate.

**The admin's own *Preferred services* screens are the denominator** this issue never had —
25 services across three carriers, of which **17 are mapped**. Still unmapped: six USPS (the
four international ones plus First Class Mail, First Class Package and Parcel Select Ground)
and two UPS — `14` Next Day Air Early and `54` Worldwide Express Plus, both toggled on in
the admin and finding no rate on any parcel or destination probed. The likeliest reading is
that a development account is not offered the premium tiers.

**One thing deliberately not done.** `ShopifyAdapter` still records `service: null` and
`ServiceEvidence::Unknown` for every purchase, including one made with an explicit pair
Shopify is now known to honour. That is a weaker claim than the evidence supports, and it
belongs to `11`'s inference ladder rather than here. Raised there.

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

**A probe caveat.** A shipment whose destination address carries no province returns
`Select a region` — a user error with a **null code** — for every pair, because the address
fails before the rate resolves. A screen of those looks nothing like `RATES_NOT_FOUND`.
Check the destination is complete before reading a sweep as a result; the rig prints unknown
error codes verbatim for exactly this reason.

## The one real gap: USPS international

Twenty spellings across Poland and Singapore missed, then nine more on Canada — the
most-served USPS international destination there is — while UPS and DHL matched on the same
shipments and USPS domestic worked in the same session. That read as "this shop is not
offered USPS international rates at all".

**That reading is wrong, and the admin proves it.** For the same parcel the admin's own rate
list offers USPS First Class Package International ($36.57), Priority Mail International
($48.81) and Priority Mail Express International ($74.85), alongside the UPS and DHL rates
the oracle *did* match. So the rates exist and the codes do not name them: **the vocabulary
is what is missing**, not the rates.

That also narrows the search. The admin's strings are USPS's own product names, and the
pattern here is that each carrier's vocabulary passes through — so the codes are likely
USPS's, in the PascalCase form USPS domestic already uses. None of the obvious
concatenations matched. Cheap to continue: the oracle is free and an international
fulfillment order now exists to run it against. Worth a second pass with USPS's **published
international product identifiers** rather than guessed spellings, which is the discipline
that produced the domestic table.

## Spun out

**`18`** — probing turned up that a voided label leaves its fulfillment order `CLOSED`, so
the packages `01` voided could never buy another Shopify label, contradicting a docblock in
shipped code.

## Related

- `01` — the purchases that unblocked this
- `14` — the capture campaign this is the instrument for
- `11` — where the "an honoured selection is evidence" question belongs
