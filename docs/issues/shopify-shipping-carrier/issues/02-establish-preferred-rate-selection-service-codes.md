# Find out whether preferredRateSelection works, and catalogue the codes that do

Status: done

Repo: `polybag`

Unblocked 2026-09-08. The terms of service gate is cleared and two labels have been
bought through the API — see `01`.

## Problem

`ShippingLabelPurchaseInput.preferredRateSelection { carrierCode, serviceCode }` is how
a specific carrier and service would be requested. Two things are unknown:

1. **Whether it is honoured at all.** A community report holds that Shopify ignores it
   outright. Plausible — the error enum contains `CARRIER_NOT_SUPPORTED` and
   `PACKAGE_CARRIER_MISMATCH`, which implies it is read, but that is inference, not
   evidence.
2. **What service codes are valid.** Shopify publishes no list and the schema cannot
   enumerate them. Carrier codes are known (`usps`, `ups_shipping`, `dhl_express`,
   `canada_post`); service codes are carrier-defined strings.

## Why it is not urgent

PolyBag does not depend on it. `auto` is the only seeded service and sends **no**
selection, letting Shopify choose the way its admin would. The adapter records what
Shopify actually did rather than what was asked for, so an ignored selection cannot
corrupt the data:

```php
service: $label->trackingCompany ?? $request->selectedRate->serviceName,
metadata: ['shopify_requested_service_code' => …, 'shopify_tracking_company' => …]
```

Both sides are kept precisely so a silent override is visible. A query for packages
where the two disagree answers question 1 from production data, without spending
anything on experiments.

## What to do

- Attempt one purchase with an explicit selection, e.g.
  `{carrierCode: "usps", serviceCode: "usps_ground_advantage"}`, and compare
  `trackingInfo.company` and the resulting service against what was asked for.
- A deliberately invalid service code is a **free** probe: it fails validation before
  anything is charged. Useful for telling "ignored" apart from "rejected" — an ignored
  selection buys a label anyway, a read one errors with `RATES_NOT_FOUND`.
- Each confirmed pair becomes a `CarrierService` row under the `Shopify` carrier, with
  service code `carrier:service` (e.g. `usps:usps_ground_advantage`). `ShopifyAdapter`
  splits on the colon; anything without one leaves the choice to Shopify. Add them under
  Carrier Services — no migration needed.
- If the selection turns out to be ignored, say so in the seeder comment and leave
  `auto` as the only catalogued service, so nobody re-litigates it later.

## Comments

### 2026-09-08 — unblocked, free, and now the gating experiment for the capture work

The terms of service gate is gone and `01` bought two labels through the API. On a
development store these are **test labels**: no postage is charged. So the "not urgent"
framing above was written against a cost that no longer applies here.

It moves to the front of the queue for a second reason. `auto` returned USPS on the first
purchase and UPS on the second from identical inputs, so `auto` is not a usable instrument
for gathering one label per carrier and service — which is exactly what `14` needs. Until
we know whether `preferredRateSelection` is honoured, the capture work has no way to ask
for a specific label.

Two things to fold in while running it:

- **The free probe first.** A deliberately invalid service code fails validation before
  anything is bought, and tells "ignored" apart from "read". Do that before spending a
  purchase on the real one.
- **The CeC question `01` reopened.** `auto` sold USPS through the API on a store whose
  admin refuses to sell USPS. An explicit `{carrierCode: "usps"}` purchase with an origin
  address matching the Shopify location exactly is the cheap test — it changes one variable
  where `01`'s comparison had two.

### 2026-09-09 — it is honoured, and the whole catalogue is enumerable for free

Both questions are answered. **`preferredRateSelection` is read and obeyed**, and the
service codes are not the strings this issue guessed at.

**1. The free probe says "read", not "ignored".** A deliberately invalid service code
comes back synchronously with a `RATES_NOT_FOUND` user error, no purchase result, and the
fulfillment order untouched. That is the discriminator this issue predicted, and it costs
nothing. The community report that Shopify ignores the field does not hold against the
2026-07 API.

**2. There is a free oracle for the whole vocabulary, and it turns this issue from a
budget problem into an afternoon.** Shopify resolves the preferred rate *before* it
validates the rest of the input. So send the selection alongside a ship date in the past
and read which error comes back:

| Reply | Means |
|---|---|
| `RATES_NOT_FOUND` | no rate matched this carrier/service pair |
| `SHIPPING_DATE_IN_THE_PAST` | a rate **did** match; the purchase then failed on the date |

Neither buys a label and neither closes the fulfillment order, so the same order can be
probed indefinitely. The probe rig is in `.scratch/` — it builds the input through
`ShopifyShippingLabelService::buildPurchaseInput()` by reflection, so what is probed is
byte-for-byte what production sends.

**Pick the poison carefully.** A zero `totalWeight` looks like the obvious choice — it has
its own error code — and is the wrong one: weight is an *input to the rate lookup*, so it
produces `RATES_NOT_FOUND` for every pair including the valid ones, and reads as a
universal miss. That false negative cost the first hour. The ship date is inert to the
rate engine, which is what makes it usable.

**3. The vocabulary — and it is two vocabularies, not one.**

- **USPS: a PascalCase of Shopify's own**, matched **case-sensitively**. `Priority` finds
  a rate, `priority` does not. Confirmed: `GroundAdvantage`, `Priority`, `PriorityExpress`,
  `MediaMail`.
- **UPS: UPS's own numeric service codes, passed straight through** — the same alphabet
  already seeded under the `UPS` carrier. Confirmed: `01`, `02`, `03`, `12`, `13`, `59`,
  `92`, `93`.

`usps_ground_advantage` — the spelling this issue proposed, and the one
`ShopifyAdapterTest` used as its example — finds no rate. So did every other convention
tried: `snake_case`, `SCREAMING_SNAKE`, `kebab-case`, spaced title case, and the
carrier-prefixed forms.

**4. The carrier code is not validated separately.** `not_a_carrier:GroundAdvantage`
returns plain `RATES_NOT_FOUND`, exactly like a bad service code. `CARRIER_NOT_AVAILABLE`
lives in `ShippingLabelPurchaseErrorCode`, the *asynchronous result* enum, not in the
input-validation enum — so it is only reachable after a purchase is enqueued. There is no
way to tell a wrong carrier from an unavailable service from the reply.

**5. "No rate" is a fact about the parcel, never about the vocabulary.** UPS `92` matches
and `93` misses on a 0.3 lb parcel; at 5 lb they swap. That is the SurePost weight split
the `UPS` block in `CarrierSeeder` already documents, reproduced exactly by Shopify. So
every negative above is disproved *for the parcel it was probed with* and nothing more —
the misses are not a closed list of invalid codes, and `ParcelSelect`, `First`,
`LibraryMail` and the international spellings may well be real on a shipment that suits
them.

**6. The purchase settles it.** One label bought through the production path with
`usps:PriorityExpress`, on a domestic parcel that `auto` had been pricing at $5.68 and
$9.04:

| | |
|---|---|
| requested | `usps:PriorityExpress` |
| Shopify charged | **$47.63** |
| fulfillment carrier | USPS |
| format | PDF, consistent with the two `auto` purchases |

Priority Mail Express pricing, an order of magnitude above what `auto` chose from the same
inputs. The selection was honoured.

**7. There is no rate quote anywhere, and there never was.** A full schema introspection
confirms it: the only `ShippingRate` in the Admin API hangs off `CalculatedDraftOrder`
and is checkout shipping, unrelated to Shopify Shipping. `PreferredRateSelectionInput`
requires **both** fields as non-null, so "any rate from this carrier" cannot be expressed
either. The probe above is the only instrument for asking what Shopify would sell.

**On the CeC question this inherited from `01`.** An explicit `{carrierCode: "usps"}`
purchase went through on a development store whose admin refuses to sell USPS, which
closes the smaller half of that question: the API path is not bound by the admin's
carrier restriction, and the origin-address theory is not needed to explain the first
purchase. The pricing half is untouched — these are test-label prices and CeC cannot be
read off them.

**What shipped with this:** the twelve confirmed pairs are seeded under the `Shopify`
carrier as `carrier:service`, with the USPS half and UPS Ground Saver marked PO Box and
military capable on the same rule the `UPS` and `FedEx` blocks use. `auto` stays and stays
the default. `ShopifyAdapterTest`'s split example was moved onto confirmed codes so the
file stops teaching a spelling that finds no rate.

**One thing deliberately not done.** `ShopifyAdapter` still records `service: null` and
`ServiceEvidence::Unknown` for every purchase, including one made with an explicit pair
that Shopify is now known to honour. That is defensible — Shopify still reports no
purchased service, and honoured-in-four-observations is not honoured-always — but it is a
weaker claim than the evidence now supports, and it belongs to `11`'s inference ladder
rather than to this issue. Raised there rather than changed here.

**Spun out: `18`.** Probing turned up that a voided label leaves its fulfillment order
`CLOSED`, so the packages `01` voided can never buy another Shopify label — which
contradicts a docblock in shipped code.

### 2026-09-09 — the negatives, so nobody probes them twice

Twenty-nine further spellings were probed against the same domestic parcel and **all
missed**, with a control run in the same session confirming the rig still returned the six
known hits. So the twelve seeded pairs are the whole domestic catalogue this store offers,
not merely the first twelve found:

- **USPS, tried and absent:** `FirstClassPackageService`, `ParcelSelectLightweight`,
  `ParcelSelectGround`, `Ground`, `Standard`, `Retail`, `RetailGround`, the four `…Cubic`
  spellings, the four `…Return` spellings, `ConnectLocal`, `ConnectRegional`, and the two
  hold-for-pickup `…HFP` spellings.
- **UPS, tried and absent:** `14` (Next Day Air Early), the Mail Innovations range
  `M2`–`M7`, and `70`–`72`.

Read these as "not offered for a 0.3 lb domestic parcel on a development store", not as
"no such code" — the `92`/`93` weight split is the standing warning against the stronger
reading. `14` in particular is a real UPS service that a production account would likely
be offered.

**Three groups remain genuinely unprobeable here**, and none of them is a matter of
guessing harder:

1. **International services**, USPS and otherwise. This store has no international order —
   its two non-domestic destinations are DPO/FPO military addresses, which rate as
   domestic. That is the same order `07` needs for the customs form.
2. **The other fifteen carriers.** Shopify documents four carrier codes (`usps`,
   `ups_shipping`, `dhl_express`, `canada_post`) and sells through around nineteen.
3. **Any carrier whose service vocabulary we cannot guess** — which is a structural limit,
   not an effort one. Because carrier and service are validated *jointly* (finding 4
   above), discovering a new carrier means guessing both halves correctly at the same
   time, with a single undifferentiated `RATES_NOT_FOUND` for every near miss. USPS and
   UPS fell only because Shopify borrowed vocabularies that already existed in public —
   EasyPost's service levels and UPS's own numeric codes. There is no reason to expect
   that to hold for the rest, and no way to tell a wrong carrier code from a wrong service
   code while hunting for it.

### 2026-09-09 — the admin's own service list, and what is mapped against it

The shop's *Preferred services* screens — one per carrier, under the carrier's settings —
list every service the shop can buy, in Shopify's own words. That is the denominator this
issue never had, and it turns "codes we have found" into "codes we are missing". Twenty-five
services across three carriers; **seventeen are mapped** (sixteen at the time this table was
first written — `UPS® Standard` was added by the Canada run recorded below).

The UPS screen also independently confirms every numeric code inferred from UPS's published
table, including the two Ground Saver tiers, which the admin distinguishes as
`UPS® Ground Saver (<1 lb)` and plain `UPS® Ground Saver`.

**USPS — 4 of 10**

| Admin name | Code | |
|---|---|---|
| Ground Advantage | `usps:GroundAdvantage` | mapped |
| Priority Mail | `usps:Priority` | mapped |
| Priority Mail Express | `usps:PriorityExpress` | mapped, and price-confirmed by a purchase |
| Media Mail | `usps:MediaMail` | mapped |
| First Class Mail | — | unmapped |
| First Class Package | — | unmapped |
| Parcel Select Ground | — | unmapped |
| Priority Mail International | — | unmapped |
| Priority Mail Express International | — | unmapped |
| First Class Package International | — | unmapped |

**UPS — 12 of 14**

| Admin name | Code | |
|---|---|---|
| UPS® Ground | `ups_shipping:03` | mapped |
| UPS 3 Day Select® | `ups_shipping:12` | mapped |
| UPS 2nd Day Air® | `ups_shipping:02` | mapped |
| UPS 2nd Day Air A.M.® | `ups_shipping:59` | mapped |
| UPS Next Day Air® | `ups_shipping:01` | mapped |
| UPS Next Day Air Saver® | `ups_shipping:13` | mapped |
| UPS® Ground Saver (<1 lb) | `ups_shipping:92` | mapped |
| UPS® Ground Saver | `ups_shipping:93` | mapped |
| UPS Worldwide Express® | `ups_shipping:07` | mapped |
| UPS Worldwide Expedited® | `ups_shipping:08` | mapped |
| UPS Worldwide Saver® | `ups_shipping:65` | mapped |
| UPS Next Day Air® Early | `14` expected | unmapped — no rate on the parcels probed |
| UPS Worldwide Express Plus® | `54` expected | unmapped — no rate on the parcels probed |
| UPS® Standard | `ups_shipping:11` | mapped — confirmed on a Canada destination |

**DHL Express — 1 of 1**

| Admin name | Code | |
|---|---|---|
| DHL Express Worldwide | `dhl_express:P` | mapped |

`Canada Post` has a documented carrier code but no screen on this shop, so it is not in the
denominator at all.

#### The third vocabulary

DHL settled the pattern the USPS and UPS halves only hinted at: **there is no Shopify
vocabulary, only each carrier's own, passed through**. `P` is DHL's product code for
Express Worldwide, exactly as `03` is UPS's for Ground. Seven other DHL letter codes were
probed and none matched, which is consistent with the shop having a single DHL service
enabled.

This is worth stating because it changes how the remaining gaps should be attacked: the
question for a new carrier is not "what would Shopify call this" but "what does this
carrier call it", and the answer is usually published by the carrier.

#### USPS international is absent, and that is not a naming failure

Twenty spellings were probed across two countries — Poland and Singapore — including
`PriorityMailInternational`, `PriorityInternational`, `InternationalPriority`,
`PriorityIntl`, the `…Intl` and `FirstClassPackageInternational…` families, and USPS's own
`SCREAMING_SNAKE` form. All missed, and USPS domestic codes were confirmed working on the
same store in the same session.

The honest reading is that **this store is not offered USPS international rates at all**,
rather than that the codes are misspelled — but the two cannot be told apart, because
carrier and service are validated jointly and both produce the same bare
`RATES_NOT_FOUND`. Shopify support's line that a development store only supports UPS test
labels was already known to be false for USPS *domestic*; it may well hold for USPS
international. Settling it needs a production store, and it is the same evidence `17`
already waits on.

The three UPS international services and the DHL one *were* found on the same shipments, so
the probe itself is sound and the destinations are rateable — the gap is USPS-specific.

#### A probe caveat worth recording

One of the three international shipments returns `Select a region` — a user error with a
**null code** — for every pair, because its destination address carries no province. That
is an address failure raised before the rate is resolved, so such a shipment cannot be
probed at all, and a screen of them looks nothing like `RATES_NOT_FOUND`. Check the
destination is complete before reading a sweep as a result. The probe rig prints unknown
error codes verbatim for exactly this reason.

**Seeded with this pass:** the three UPS international codes and DHL's `P`, bringing the
catalog to sixteen. None is PO Box or military capable.

### 2026-09-09 — Canada closes UPS, and makes the USPS international answer much harder to explain away

A Montréal destination was added to the store, which settles the last reachable UPS gap and
puts the USPS question on far firmer ground.

**`ups_shipping:11` — UPS Standard — is confirmed**, which is what the service is for:
Canada and Mexico ground. It is now seeded, bringing UPS to twelve of fourteen and the
catalog to seventeen. `07`, `08`, `65` and `dhl_express:P` matched here too, so four
carriers' worth of international service is now confirmed on three different countries.

**`ups_shipping:03` correctly found no rate** — UPS Ground is domestic and Standard is its
cross-border equivalent, so the pair behaves exactly as UPS's own catalog says it should.
Worth noting because it is a small independent check that these codes mean what we think
they mean, rather than matching some fuzzy internal lookup.

**USPS international is now a strong negative, not an open naming question.** Canada is the
most-served USPS international destination there is, and none of nine spellings matched
there — the same nine that missed on Poland and Singapore. Three countries, three carriers
succeeding on the same shipments, USPS domestic working on the same store in the same
session. The remaining explanation that fits all of it is that **this shop is not offered
USPS international rates at all**, which is a shop-or-store-type fact rather than a
vocabulary one.

That does not make the codes *known* — a shop that is offered the rates might still call
them something none of us guessed, and joint carrier/service validation means a production
store would have to re-run the same sweep to find out. But it does mean nobody should spend
more time guessing spellings against this store: the instrument cannot distinguish "wrong
name" from "not sold here", and everything else points at the second.

**Canada Post remains outside the denominator.** Five spellings, including Canada Post's own
`DOM.RP`/`DOM.EP`/`DOM.XP` product codes, all missed — expected for a US-origin shop, and
consistent with the carrier having no *Preferred services* screen here at all.

**Still unmapped after all of this: eight.** Six USPS (the four international ones plus
First Class Mail, First Class Package and Parcel Select Ground) and two UPS — `14` Next Day
Air Early and `54` Worldwide Express Plus. Both UPS ones are toggled on in the admin and
found no rate on any parcel or destination probed, domestic or international; the most
likely reading is that a development account is not offered the premium tiers, which is the
same class of limitation as the USPS international finding and equally unresolvable here.
