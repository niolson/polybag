# Gather the label evidence the inference tables need

Status: ready-for-human — both US carriers are finished; the other seventeen are gated on the install-base question

Repo: `polybag`

## Problem

`11` built the inference ladder and both of its data tables, but populated them from labels
we happened to hold. Shopify Shipping sells through **nineteen** carriers, and every one a
client actually ships with is a carrier whose packages currently infer nothing.

The tables cannot be filled from carrier documentation. `11` established why: what a carrier
*calls* a service and what it *prints* are different strings — DHL eCommerce's API says
`GND` where its label says `GRD` — and neither is a `CarrierService.service_code`. A token
added from documentation is a token that has never been observed matching anything.

This is the gathering work, listed so it can be done incrementally by whoever has access to
a given carrier, rather than blocking on one person having all nineteen.

## The cheapest path is labels, not research

One real label per carrier and service answers four questions at once, three of which no
amount of reading resolves:

1. **What service token does this carrier print?** Feeds `label-tokens.json`.
2. **Does its document carry a text layer, or is it a full-page bitmap?** A bitmap is
   rung-2-unreadable and **invisible in the reported format**, which says `PDF` either way.
   Check with `pdffonts`. (This replaced "what format does Shopify hand back" — that turned
   out not to be a per-carrier question at all: the enum has two values, the shop's setting
   reaches only PDF, and no carrier can report PNG however its own label is drawn.)
3. **Is there a last-mile handoff?** A consolidator label says so on its face — `US Postage
   Paid`, `eVS`, `USPS TRACKING #`. This is what tells us whether `11`'s consolidator guard
   needs extending to a carrier nobody has thought about.
4. **What number family is the tracking number in?** Decides whether rung 1 can ever apply.

## Capture protocol

A label on its own is not evidence — the point is the pairing of a label with what was
actually bought. For each capture record together:

- **the label bytes from `shippingDocuments[].url`, never from the admin's print dialog.**
  The API document is always 4×6; the admin re-renders at whatever page size is chosen at
  print time, so a dialog capture can misreport the size. Record whether it has a text layer.
- the tracking number, and `trackingInfo.company` exactly as Shopify returned it
- the `preferredRateSelection` requested, if any
- **what the Shopify admin says was bought** — the order's own record of service and price.
  This is the ground truth the inference is checked against, and it exists nowhere in the
  API. Screenshot it.
- **`Order.events` for the price**, which `05` needs and which this campaign produces for
  free. Repeated purchases and voids against one order are exactly the ambiguous case it
  needs characterised.

**Captures go in `.scratch/`, never in the repo.** A real label carries the recipient's name
and address, and this is a public repository. Only the *derived* token belongs in
`label-tokens.json`, named by description rather than by a path to something uncommitted.
Where a test needs a fixture, synthesise one.

**Use the free probe from `02` first.** A pair sent with a past ship date reports whether
Shopify has a rate for it without buying, so every capture can be confirmed available before
a label is spent. And **availability is per shipment** — UPS `92`/`93` swap at the 1 lb
SurePost boundary — so a capture list has to name the parcel it applies to.

## The carriers

The regional grouping is a **starting hypothesis, not a fact**: Shopify gates carrier
availability by the merchant's ship-from country, so the authoritative grouping is whatever a
given client's Shopify admin actually offers.

| Carrier | Believed region | State |
|---|---|---|
| USPS | US | **Done.** 342 service type codes resolve USPS domestic on the tracking number alone. Rung 2 **descoped** — Shopify passes USPS's own label through, so a token could be sourced from our labels, but rung 1 already answers |
| UPS | US, CA | **Rung 1 done; rung 2 permanently closed.** UPS offers ZPL or GIF and no PDF, so Shopify's PDF is a wrapped raster necessarily. The 1Z indicator table is built. Token on the printed face: `UPS GROUND SAVER` — and that label is a **consolidator**, USPS last mile, dual `1Z` + IMpb |
| FedEx | US | Partial: domestic tokens from sandbox PDFs. International prints `IP`/`XQ` |
| DHL | US, intl | One ZPL token from vendor docs. **Which DHL** — Express or eCommerce — is unconfirmed, and they are different carriers with different labels |
| Canada Post | CA | Nothing. PDF or ZPL |
| Purolator | CA | Nothing |
| Australia Post | AU | Nothing |
| Sendle | AU | Nothing. A reseller — check for a last-mile handoff |
| Royal Mail | UK | Nothing. S10 candidate |
| Evri | UK | Nothing |
| Yodel | UK | Nothing |
| DPD | UK, FR | Nothing |
| Colissimo | FR | Nothing. S10 candidate |
| Chronopost | FR | Nothing |
| Mondial Relay | FR | Nothing. Parcel-shop network — the "service" may not be a service at all |
| Correos | ES | Nothing. S10 candidate |
| SEUR | ES | Nothing |
| BRT Bartolini | IT | Nothing |
| Poste Italiane | IT | Nothing. S10 candidate |

**Prioritise by where clients actually ship from**, not down the list. A US-only install
never sees fourteen of these.

## Documentation work

**1. UPS 1Z service indicator — done 2026-09-09** in `11`. The authority bar this item set
turned out to be unreachable: **UPS does not publish this mapping at all.** Built from
observed pairs with that recorded in its provenance. The finding to carry forward is that the
indicators are a **fourth vocabulary**, agreeing with UPS's API service codes domestically and
diverging on every international service — so sourcing the table from UPS's published codes,
the obvious move, looks confirmed domestically and is silently wrong abroad.

**2. UPU S10 — worth investigating as a second decodable family.** International postal items
carry a 13-character identifier: a 2-letter service indicator, an 8-digit serial, a check
digit, a 2-letter ISO country code. The check digit is a weighted modulus 11 over the serial
(weights 8, 6, 4, 2, 3, 5, 9, 7), so the same validate-before-inferring discipline applies.
Source: [UPU S10-12](https://www.upu.int/UPU/media/upu/files/postalSolutions/programmesAndServices/standards/S10-12.pdf).
If it works it covers Royal Mail, Correos, Poste Italiane, Colissimo, Australia Post and
Canada Post international from one table rather than six sets of labels.

**But confirm the resolution is useful before building it.** The first character indicates a
*type* of product and the second is assigned by the origin operator, so it may be a class
("registered", "express", "parcel") rather than a service — and a class written into
`packages.service` is a different and worse thing than a service. Decide against real numbers;
falling through is the correct outcome if the answer is only ever a class.

## What to answer

1. **Which carriers does our install base actually use?** Everything prioritises off this,
   and nobody should gather labels for fourteen carriers nobody ships with. It is a business
   question this repository cannot answer, and it gates the remaining seventeen carriers.
2. **Is a UPU S10 service indicator specific enough to be a service?** If not, S10 is
   `wontfix` for rung 1 and those carriers are rung-2-only.
3. **Which DHL does Shopify sell** — Express, eCommerce, or both by region? Different
   carriers, different labels, and only one of them is the consolidator.

## Acceptance criteria

- [ ] A prioritised carrier list, ordered by our own install base
- [ ] For each prioritised carrier: at least one captured label per service, with the admin's
      record of what was bought alongside it
- [ ] `label-tokens.json` extended from those captures, every token traceable to a label we
      hold
- [ ] Every carrier whose label shows a last-mile handoff is covered by `11`'s consolidator
      guard, and a test says so
- [ ] Carriers whose document rung 2 cannot read are recorded as such rather than left
      looking un-gathered
- [x] The UPS 1Z question resolved — table generated from a named source
- [ ] The UPU S10 question resolved: table generated, or `wontfix` with the class-versus-
      service reasoning recorded
- [ ] `app:infer-package-services` re-run and coverage reported after each carrier lands, so
      the tables are judged on measured coverage rather than row count

## Comments

- **2026-09-08** — the US half became free: dev-store labels are test labels, and both USPS
  and UPS sell through the API there. Two caveats on reading such captures: tokens and number
  families are real evidence, but a test label never moves, so nothing gathered here says
  anything about scan-level movement.
- **2026-09-09** — `02` confirmed `preferredRateSelection` is honoured and seeded the pairs,
  so captures can be taken deliberately rather than by taking what `auto` hands back. That was
  the blocker named here.
- **2026-09-09** — first two labels read. **UPS Ground Saver is a consolidator, and `auto`
  selects it unprompted**, so `11`'s guard is on this seller's default path rather than at a
  corner of it. A naive scan of that one face finds `UPS GROUND SAVER` *and* `USPS PARCEL
  SELECT` and has to prefer the right one. The USPS document carries a `SAMPLE - DO NOT MAIL`
  watermark, so Shopify's USPS test labels are USPS's own sample labels — the token is real,
  the surrounding label is a sample.
- **2026-09-09** — correcting the capture rule: **UPS labels are bitmaps wherever you get
  them.** An earlier reading blamed the admin's print dialog; the confound was the carrier.
  The rule still stands for **page size**. This is question 2's answer arriving early and it
  is the bad one: capturing more UPS labels yields tokens for a rung that cannot run on them.
  UPS is *gathered and unreadable*, which is a different state from Canada Post's *unknown*.
- **2026-09-10** — the open premise settled: **Shopify is a passthrough**, proven by identical
  font subset tags between a USPS-bought and a Shopify-bought label for the same package. So
  USPS tokens could be sourced from our own labels — and are **descoped anyway**, because rung
  1 already answers for that carrier.

## Blocked by

- `11` — the ladder and tables these fill
