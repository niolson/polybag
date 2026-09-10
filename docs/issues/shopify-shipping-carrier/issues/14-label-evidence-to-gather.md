# Gather the label evidence the inference tables need

Status: ready-for-human

Repo: `polybag`

## Problem

`11` built the inference ladder and both of its data tables, but populated them only from
labels we happened to already hold: FedEx sandbox PDFs, and DHL eCommerce's documentation
sample. Shopify Shipping sells through **nineteen** carriers. Every one of them that a
client actually ships with is a carrier whose packages currently infer nothing.

The tables cannot be filled from carrier documentation. `11` established why: what a carrier
*calls* a service and what it *prints* on the label are different strings — DHL eCommerce's
API says `GND` where its label says `GRD` — and neither is a `CarrierService.service_code`.
A token added from documentation is a token that has never been observed matching anything.

This issue is the gathering work, listed so it can be done incrementally by whoever has
access to a given carrier, rather than blocking on one person having all nineteen.

## The cheapest path is labels, not research

One real label per carrier and service answers four questions at once, and three of them are
questions no amount of reading resolves:

1. **What service token does this carrier print?** The only way to know. Feeds
   `label-tokens.json`.
2. ~~**What format does Shopify hand back for this carrier?**~~ **Answered, and it was not a
   per-carrier question.** `ShippingEnumsFileFormat` has exactly two values, `PDF` and `ZPL`,
   and the shop's label format setting reaches only PDF — so every carrier reports PDF and no
   carrier can report PNG through this API however its own label is drawn. See `01`,
   2026-09-09. A carrier whose native label is PNG is therefore **not** rung-2-unreachable
   here; whatever Shopify wraps it in is a PDF, and whether that PDF carries extractable text
   or a bitmap is the real question, which is `11`'s OCR argument on different ground.
3. **Is there a last-mile handoff?** A consolidator label says so on its face — the DHL
   sample prints `US Postage Paid`, `eVS` and `USPS TRACKING #`. This is what tells us
   whether `11`'s consolidator guard needs extending to a carrier we have not thought about.
4. **What number family is the tracking number in?** Printed right there, and it decides
   whether rung 1 can ever apply.

So the gathering task is mostly "capture labels systematically", not "research nineteen
carriers". Only two items on the list below are documentation work.

## Capture protocol

A label on its own is not evidence — the point is the pairing of a label with what was
actually bought. For each capture record, together:

- the label bytes, unmodified, with the format Shopify reported — **taken from
  `shippingDocuments[].url`, never from the admin's print dialog.** The API document is always
  4×6; the admin re-renders at whatever page size is chosen at print time, so a capture from
  the dialog can misreport the size. **Record whether the PDF has a text layer** (`pdffonts`):
  some carriers return a full-page bitmap, which is rung-2-unreadable and invisible in the
  reported format. See the comments of 2026-09-09
- the tracking number
- `trackingInfo.company` exactly as Shopify returned it
- the `preferredRateSelection` we requested, if any
- **what the Shopify admin says was bought** — the order's own record of the service and
  price. This is the ground truth the inference is checked against, and it exists nowhere
  in the API. Screenshot it.

**Captures go in `.scratch/`, never in the repo.** A real label carries the recipient's name
and address, and this is a public repository. Only the *derived* token belongs in
`resources/data/service-inference/label-tokens.json`; the label it came from stays local, and
`label-tokens.json` names it by description rather than by a committed path to something
that is not committed. Where a fixture is genuinely needed for a test, synthesise one the way
`tests/Fixtures/Labels/dhl-ecommerce-ground.zpl` is synthesised.

## The carriers

Nineteen, per Shopify's own directory. The regional grouping below is a **starting
hypothesis, not a fact** — Shopify gates carrier availability by the merchant's ship-from
country, so the authoritative grouping for our purposes is whatever a given client's Shopify
admin actually offers. Confirm it there rather than from the help centre.

| Carrier | Believed region | State |
|---|---|---|
| USPS | US | **Done, and rung 2 is closed as unnecessary.** 342 service type codes, effective 2026-06-24, resolve USPS domestic on the tracking number alone. No label needed, and no token work wanted either — see 2026-09-10 below |
| UPS | US, CA | **Rung 1 done; rung 2 permanently closed.** The API label is a full-page bitmap — UPS offers ZPL or GIF and no PDF, so Shopify's PDF is a wrapped raster necessarily — and `label-tokens.json` gains nothing from more UPS captures. The 1Z service indicator table **is** built (`11`, 2026-09-09), so these packages now infer on rung 1. Token seen on the printed face: `UPS GROUND SAVER`; that label is a **consolidator** — USPS last mile, dual `1Z` + IMpb |
| FedEx | US | Partial: domestic tokens from sandbox PDFs. International prints `IP`/`XQ` |
| DHL | US, intl | One ZPL token from vendor docs. **Which DHL** — Express or eCommerce — is itself unconfirmed, and they are different carriers with different labels |
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
never sees fourteen of these. The prioritisation input is our own install base, which this
issue does not have.

## Documentation work — the two table-driven rungs

These are the only two items that want a published source rather than a label, and both
should be sourced and generated the way the USPS table was, not transcribed. `11`'s
`app:build-service-inference-ruleset` is the pattern: a committed generator, an upstream
effective date recorded in the file, and codes that do not resolve falling through.

~~**1. UPS 1Z service indicator.**~~ **Done 2026-09-09** in `11`, and the authority bar this
item set turned out to be unreachable rather than merely unmet: **UPS does not publish this
mapping at all.** The table is built from observed tracking-number/service pairs, with that
recorded in its own provenance instead of the evidence being dressed up. Ten indicators, every
row carrying two independent sources except UPS Standard. Contract and regional codes fall
through as this item asked.

The finding worth carrying forward: the indicators are a **fourth vocabulary**, agreeing with
UPS's API service codes on every domestic service and diverging on every international one
(`04`/`66`/`67`/`68` against `65`/`07`/`08`/`11`). Sourcing that table from UPS's published
codes — the obvious move — looks confirmed domestically and is silently wrong abroad.

**2. UPU S10 — worth investigating as a second decodable family.** International postal
items carry a 13-character identifier: a 2-letter service indicator, an 8-digit serial, a
check digit, and a 2-letter ISO country code. The check digit is a weighted modulus 11 over
the serial using weights 8, 6, 4, 2, 3, 5, 9, 7 — so the same validate-before-inferring
discipline rung 1 already applies to IMpb is available here. Source:
[UPU S10-12](https://www.upu.int/UPU/media/upu/files/postalSolutions/programmesAndServices/standards/S10-12.pdf).

If it works it covers several of the national posts above at once — Royal Mail, Correos,
Poste Italiane, Colissimo, Australia Post and Canada Post international — from one table
rather than six sets of labels.

**But confirm the resolution is useful before building it.** The first character indicates
the *type* of product and the second is assigned by the origin operator. That may be a class
("registered", "express", "parcel") rather than a service, and a class written into
`packages.service` is a different and worse thing than a service. Decide that question
against real numbers before writing the table; falling through is the correct outcome if the
answer is only ever a class.

## What to answer

1. **Which carriers does our install base actually use?** Everything else prioritises off
   this, and nobody should gather labels for fourteen carriers nobody ships with.
2. **Is a UPU S10 service indicator specific enough to be a service?** See above. If not,
   S10 is a `wontfix` for rung 1 and those carriers are rung-2-only.
3. **Which DHL does Shopify sell** — Express, eCommerce, or both by region? They are
   different carriers, different labels, and only one of them is the consolidator.
4. ~~**Does any carrier here return PNG through Shopify?**~~ **No — the format enum cannot
   express it** (see question 2 above). The concrete form of `11`'s OCR question is instead:
   **does any carrier's API document come back as a PDF wrapping a full-page bitmap?** That is
   rung-2-unreadable for the same reason a PNG would have been, and it is not visible from the
   reported format, which says `PDF` either way. Check it per carrier with `pdffonts` on the
   captured document — and on the document from `shippingDocuments[].url`, since the admin's
   print render is rasterised for every carrier and answers this question wrongly.

## Acceptance criteria

- [ ] A prioritised carrier list, ordered by our own install base rather than by region
- [ ] For each prioritised carrier: at least one captured label per service, with the
      Shopify admin's record of what was bought alongside it
- [ ] `label-tokens.json` extended from those captures, every token traceable to a label
      we actually hold
- [ ] Every carrier whose label shows a last-mile handoff is covered by `11`'s consolidator
      guard, and a test says so
- [ ] Carriers returning a format rung 2 cannot read are recorded as such rather than left
      looking un-gathered
- [ ] The UPS 1Z question resolved: table generated from a named source, or explicitly
      deferred with the reason
- [ ] The UPU S10 question resolved: table generated, or `wontfix` with the class-versus-
      service reasoning recorded
- [ ] `app:infer-package-services` re-run and its coverage reported after each carrier lands,
      so the tables are judged on measured coverage rather than on row count

## Blocked by

- ~~`01-verify-first-live-label-purchase` — for everything needing a Shopify label.~~
  **Cleared 2026-09-08.** Labels can be bought on a development store for nothing; see the
  comment below. The two documentation items and the install-base question were never
  blocked
- `11-infer-the-service-from-the-label` — the ladder and tables these fill

## Comments

### 2026-09-08 — the US half is now free; capture it as one campaign

`01` cleared the purchase blocker and established that labels bought on a development store
are **test labels**: no postage charged, and both USPS and UPS sell through the API there
even though the store's own admin flow will not. So the two carriers that matter most to
this table are gatherable today at zero cost, which is a much better position than "one
person needs access to nineteen carriers".

**Run `02` first.** It is a prerequisite rather than a parallel track. `auto` returned USPS
on one purchase and UPS on the next from identical inputs, so without a working
`preferredRateSelection` there is no way to ask for a specific carrier and service — and
"one label per service" is the whole capture protocol. If the selection turns out to be
ignored, this issue's scope shrinks to whatever `auto` happens to hand back, and that is
worth knowing before anyone starts.

**Wire `11`'s purchase-time hook in before capturing**, not after. Then each capture is also
a test of the inference path, for free.

**Capture more than this issue asks for, while you are in there.** Two other open issues
want evidence from the same purchases and would otherwise need their own campaign:

- **`05`** wants `Order.events` after every purchase — the label price is in the order
  timeline as prose. Repeated purchases and voids against one order are exactly the
  ambiguous case it needs to characterise, and this campaign produces them anyway.
- **`01`** wants a purchase at each label format setting (to see whether the PDF's page size
  follows it), a purchase made after the 8 PM cutoff, and an international order. The format
  one overlaps with this issue's question 2, though not as originally written: there is no
  ZPL setting to flip, and the captures here are PDF whatever the carrier.

**Two caveats on reading dev-store captures.** A test label is registered with the carrier
and tracks, so the tokens and the number families are real evidence. But UPS test numbers
carry a service indicator we cannot decode, and a test label never moves — so nothing
gathered here says anything about scan-level movement, and a missing token is not
necessarily a gap in the published table.

**The prioritised carrier list is still the open input**, and it is the acceptance criterion
that gates the other fourteen carriers. It is a business question about our install base,
not something this repository can answer. USPS and UPS are safe to gather ahead of it on the
strength of being the two the feature was built for.

### 2026-09-09 — the instrument exists now

`02` was the blocker named here: `auto` returned USPS on one purchase and UPS on the next
from identical inputs, so there was no way to ask for one label per carrier and service.
`preferredRateSelection` is now confirmed honoured, and twelve `carrier:service` pairs are
seeded — four USPS and eight UPS — so the US half of the capture protocol can be run
deliberately rather than by taking what Shopify happens to choose.

Two things from that work bear directly on the capture:

- **The free probe belongs in the protocol.** A pair sent with a ship date in the past
  reports whether Shopify has a rate for it *without buying*, so every capture can be
  confirmed available before a label is spent on it. The rig is in `.scratch/`.
- **Availability is per shipment.** UPS `92` and `93` swap at the 1 lb SurePost boundary,
  so a capture list has to name the parcel it applies to. A pair that finds no rate for
  the parcel to hand is not a pair that does not exist.

### 2026-09-09 — first two labels read; UPS Ground Saver is a consolidator and the capture protocol needs a source rule

Two labels inspected — a UPS Ground Saver bought in the admin, and the USPS document still
reachable on package 177. Against this issue's four questions:

| | UPS Ground Saver | USPS |
|---|---|---|
| **1. Service token** | `UPS GROUND SAVER` | `PRIORITY MAIL EXPRESS®` |
| **2. Format** | PDF, 4×6 | PDF, 4×6 |
| **3. Last-mile handoff** | **Yes** — `US POSTAGE PAID / UPS / eVS`, `USPS PARCEL SELECT` | No |
| **4. Number family** | `1Z000X00YW00000001` **and** a 26-digit IMpb, `9261 0000 0000 0000 0000 0000 01` | 26-digit IMpb, `9270 0000 0000 0000 0000 0000 02` |

**Add a rule to the capture protocol: take the label from the API, not from the admin.** The
same label exists in two forms. The admin's print dialog rasterises — no fonts, zero
extractable text, one full-page bitmap — while the document at `shippingDocuments[].url` has
embedded fonts and yields its service token to `pdftotext`. A capture taken from the print
dialog is unreadable and will look like a carrier that prints nothing. Detail in `11`.

**UPS Ground Saver is a consolidator label, and `auto` selects it unprompted.** The
consolidator guard in `11` was reasoned about on a DHL eCommerce sample and treated as an
edge case; it is on this seller's default path. A naive scan of that one face finds
`UPS GROUND SAVER` and `USPS PARCEL SELECT` and has to prefer the right one.

**Both tracking numbers are 26-digit IMpbs** — `15` confirmed twice more, and on the UPS
label the IMpb sits alongside a `1Z`, so a package whose stored number is the `1Z` has a
second, decodable number printed on its face that nothing reads.

The UPS number carries `YW` in bytes 9–10 again, matching the earlier finding, so the
non-numeric service indicator is consistent rather than a one-off — and the 1Z table's
fall-through requirement stands.

**The USPS document carries a `SAMPLE - DO NOT MAIL` watermark**, so Shopify's USPS test
labels are USPS's own sample labels. Worth knowing before a dev-store capture is filed as
representative artwork: the token is real, the surrounding label is a sample.

### 2026-09-09 — correcting the capture rule: UPS labels are bitmaps wherever you get them

The comment above added a rule — take the capture from `shippingDocuments[].url`, not from
the admin's print dialog — on the reading that Shopify rasterises when it renders for
printing. That reading was wrong, and it named its own confound at the time.

An international UPS label taken from the API is **also** a full-page bitmap: no fonts, no
extractable text, the same 1400×800 grayscale image. The difference is the **carrier**, not
the source. USPS labels carry text; UPS labels do not.

**The rule still stands, for a different reason.** The API document is always 4×6 while the
admin re-renders at whatever page size is chosen at print time, so a capture from the print
dialog can misreport the size. It just does not make a UPS label readable.

**This is question 4's answer arriving early, and it is the bad one.** A carrier can return a
`format: PDF` that is rung-2-unreadable, it is invisible in the reported format, and the first
carrier we checked is one. Recording it in the table below as a distinct state from
"not gathered": UPS is gathered and unreadable, which is a different fact from Canada Post,
which is simply unknown.

Practical consequence for this campaign: capturing more UPS labels yields tokens for a rung
that cannot run on them. The UPS captures are still worth having for the tracking-number and
consolidator questions — 1 and 3 and 4 — but not for `label-tokens.json`. Weight the
prioritised carrier list accordingly when it arrives.

## Comments

### 2026-09-10 — Shopify passes USPS's label through, and USPS rung 2 is not worth gathering

**The open premise is settled.** `11` recorded, on 2026-09-06, that "Shopify is plausibly the
label *producer* rather than a passthrough, so layout and tokens could differ from anything
tested here". It is a passthrough. Measured on a Ground Advantage label bought both ways for
the same package (209), which is the like-for-like comparison an earlier attempt got wrong by
holding a USPS *international* label against a Shopify domestic one:

| | USPS API | Shopify |
|---|---|---|
| Producer | `Apache FOP … PDF Transcoder for Batik` | `Ruby CombinePDF 1.0.31 Library` |
| Page | 288×432 pts | 288×432 pts |
| Fonts | `EAAAAA+ArialMT`, `EAAAAB+Arial-BoldMT`, `EAAAAC+Arial-ItalicMT`, `EAAAAD+Consolas` | **identical, same order** |
| Images | 254×50 + two 40×40 indexed | **identical** |

USPS generates the label with Apache FOP and Shopify re-wraps it through a Ruby library
without touching the content stream. The **font subset tags are the proof**: those tags are
assigned by the generating tool, so identical tags in identical order mean the same source
document rather than a similar one. Every difference in the extracted fields is content —
sender name, address formatting, tracking number, order reference — and `USPS GROUND
ADVANTAGE™` is byte-identical on both.

**So a USPS token can be sourced from our own sandbox labels**, no Shopify order required.

**But it should not be sourced at all, because USPS rung 2 has no work to do.** The STC table
holds 342 codes, 338 of them naming a product, so USPS domestic resolves on the tracking
number — which costs nothing, needs no label, and is the only rung that survives
`PurgePiiCommand`. Rung 2 never even ran on the label above. Its entire marginal value for
USPS is the handful of STCs that are ambiguous or name no product, and for those the correct
outcome is falling through anyway.

Gathering USPS tokens is therefore **descoped**, not deferred. The one token already recorded
(`PRIORITY MAIL EXPRESS®`) can stay as an observation; nothing needs adding to
`label-tokens.json` for this carrier.

**What this leaves.** Both US carriers are now finished as far as this issue can take them —
USPS on rung 1, UPS on rung 1 with rung 2 permanently shut. The remaining seventeen carriers
are still gated on question 1, which is a business input this repository does not have.

**Incidental, and the first live confirmation of `11`'s purchase-time hook:** package 209 came
back from the Shopify purchase with `service = 'USPS Ground Advantage'`, `service_evidence =
inferred`, `service_inference_method = 'usps-impb-stc'`, `service_ruleset_version =
'2026-09-09'` — against a real label rather than a test double.
