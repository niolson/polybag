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
2. **What format does Shopify hand back for this carrier?** PDF, ZPL, PNG. A PNG carrier is
   rung-2-unreachable and belongs in the OCR argument in `11`, not in the token table.
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

- the label bytes, unmodified, with the format Shopify reported
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
| USPS | US | **Rung 1 done.** 342 service type codes, effective 2026-06-24. No label needed |
| UPS | US, CA | 1Z table not built — see documentation work below. No label tokens |
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

**1. UPS 1Z service indicator.** Bytes 9–10 of a 1Z number. Already named as remaining work
in `11`. Needs a source at least as authoritative as USPS's appendix; expect contract and
regional codes the published table does not cover, which must fall through rather than be
guessed at.

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
4. **Does any carrier here return PNG through Shopify?** That is the OCR question from `11`
   in its concrete form. A PNG-only carrier with meaningful volume is the only evidence that
   would reopen OCR.

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
- **`01`** wants a ZPL purchase (flip the shop's label format setting), a purchase made
  after the 8 PM cutoff, and an international order. The ZPL one overlaps directly with this
  issue's question 2 — one capture answers both.

**Two caveats on reading dev-store captures.** A test label is registered with the carrier
and tracks, so the tokens and the number families are real evidence. But UPS test numbers
carry a service indicator we cannot decode, and a test label never moves — so nothing
gathered here says anything about scan-level movement, and a missing token is not
necessarily a gap in the published table.

**The prioritised carrier list is still the open input**, and it is the acceptance criterion
that gates the other fourteen carriers. It is a business question about our install base,
not something this repository can answer. USPS and UPS are safe to gather ahead of it on the
strength of being the two the feature was built for.
