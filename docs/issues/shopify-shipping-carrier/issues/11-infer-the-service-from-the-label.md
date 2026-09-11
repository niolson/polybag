# Infer the service Shopify bought, and record how it was inferred

Status: ready-for-human — the ladder, both tables and the purchase-time hook are built; the coverage measurement needs real Shopify packages

Repo: `polybag`

## Problem

Shopify never reports the service it bought. `ShippingLabel` exposes no service, no service
code, no rate and no price, before or after purchase. `10` therefore leaves
`packages.service` null, and `service` is what operators filter and sort by and what billing
groups on.

`postage-source-split/11` landed the model that makes the absence legible: `service_evidence`
of `confirmed` / `inferred` / `unknown`, with an inference method and ruleset version
recorded whenever it is `inferred`. This slice carries ADR-0003's **To revisit** entry into
work; it does not re-decide it. Where the two disagree, the ADR wins.

Inference is for our own reporting. It never reaches a marketplace: `confirmedService()`
withholds anything that is not `confirmed`, and that stays true here.

## The ladder

Cheapest and most reliable first, stopping at the first conclusive answer. Each rung names
itself in `service_inference_method` and stamps `service_ruleset_version`, so a value can be
re-derived and compared when the tables change. The carrier is already known from
`trackingInfo.company`, so every rung chooses a service *within a known carrier*.

**1. Decode the tracking number.** USPS IMpb carries a 3-digit Service Type Code; UPS 1Z
carries a service-level indicator in bytes 9–10. **Validate format and check digit before
inferring anything** — a malformed number with plausible digits in the service position is
the one way this produces a confident wrong answer. This rung costs nothing, needs no label,
and is the only one that can be re-run over historical packages, because
`packages.tracking_number` survives `PurgePiiCommand`.

**2. Read the label's plaintext.** ZPL is plain text; PDF needs extraction. This rung has a
shelf life — `PurgePiiCommand` nulls `label_data` after `pii_retention_days` — so inference
runs **at purchase time**, not lazily.

**3. Fingerprinting or OCR.** A decided non-goal, not vague future work. See question 2.

## What is built

| | |
|---|---|
| `smalot/pdfparser` | PDF text extraction. One package, no `Dockerfile` change |
| `ServiceInferrer` | the ladder; `inferFrom(carrier, trackingNumber, labelData)` for purchase time, `infer(Package)` for a saved row |
| `ImpbTrackingNumber` | IMpb parse, `420`-prefix strip, mod-10 check digit |
| `LabelTextExtractor` | ZPL and PDF to text, **dispatching on magic bytes, not `label_format`** |
| `ServiceRuleset` | loads the versioned tables as one unit, memoized per instance |
| `Package::recordInferredService()` | the write path |
| `app:infer-package-services` | coverage measurement; reports by default, writes under `--apply` |
| `app:build-service-inference-ruleset` | generates the USPS table from USPS's published appendix |

**The hook runs inside the purchase.** `ShopifyAdapter::createShipment()` runs the ladder
over the label it just bought and folds the result into the `ShipResponse`, so it goes in
through `markShipped()` and therefore through `assertServiceEvidenceIsConsistent()` — one
validated transition rather than a second write after the fact. **It is best-effort**: by
the time it runs Shopify has already bought and billed the label, so an exception escaping
it would leave the package unshipped against postage already paid for. It degrades to
`unknown` and logs.

- [x] An unambiguous Service Type Code yields the service with evidence `inferred`, method
      and ruleset version recorded
- [x] An ambiguous code, or a carrier that encodes nothing, falls through rather than picking
- [x] A ZPL label yields the service where the tracking number was inconclusive
- [x] Exhausting every rung leaves the package `unknown` with its requested preference intact
- [x] An inferred service never overwrites a confirmed one and never downgrades
- [x] Re-running under a newer ruleset replaces the value and its version stamp together
- [x] Nothing inferred reaches a channel export
- [x] The tables are versioned data under `resources/data/service-inference/`
- [x] A tracking number failing validation infers nothing
- [ ] **Coverage against real Shopify packages is measured and reported**

## The answered questions

**1. Take the PDF dependency — `smalot/pdfparser`.** Measured against 17 real label PDFs.
Its only Composer dependency is already in `composer.lock`, it needs `ext-iconv` and
`ext-zlib` (both default in the image), it is 728K of pure PHP with no temp files or
`proc_open`, and it runs at ~19ms per label. `spatie/pdf-to-text` needs `poppler-utils` added
to the image and shells a binary out for the same result. The case that decides it: USPS
labels use Type0/**Identity-H** fonts, where the text bytes are glyph IDs rather than
characters, so extraction requires following the font's ToUnicode CMap. `smalot` does; a
hand-rolled `Tj`/`TJ` scanner does not. Restricting rung 2 to ZPL was the alternative and
would blank it out entirely for every PDF shop.

**2. OCR is explicitly `wontfix`**, for a sharper reason than "disproportionate": the
carriers it would buy are DHL eCommerce and OnTrac, which are also the two that encode
nothing usable in the tracking number. OCR's entire yield is the set this ladder already
accepts as terminal `unknown`. **But see the UPS finding below** — a bitmap-only carrier with
real volume is exactly the thing that would reopen this, and UPS is one.

**3. Ruleset versioning:** `resources/data/service-inference/`, committed as JSON rather than
seeded, because a package stamped with a version has to stay comparable against the tables
that produced it and a reseeded database row cannot offer that. `ruleset.json` carries one
`version`, an ISO date (which compares as a string, which is what "newer ruleset" needs).
Each table records its own upstream provenance and effective date separately, so an inference
traces to a specific published appendix rather than to a date we chose.

## The findings that shape the tables

**The USPS table is generated, never transcribed.** 342 service type codes from USPS's
published *Service Type Codes Appendix I*, effective 2026-06-24, 338 naming a product. The
other four record a null product and are treated as inconclusive — guessing a product for a
row USPS writes irregularly is the confident wrong answer this ladder exists to avoid.

**The consolidator guard is load-bearing, and on the default path.** A DHL eCommerce label
carries a genuine USPS IMpb that *passes check-digit validation* — so the safety mechanism
this issue relies on does not stop it — and decoding it names the USPS **last-mile product**,
not the service the customer bought. The rule: **if the carrier of record is not USPS and the
number is a valid IMpb, stop.** This turned out to matter more than the DHL sample suggested:
`CarrierSeeder` already documents FedEx Ground Economy and UPS Ground Saver as USPS-last-mile
services, so our own direct packages carry IMpbs today — and Shopify's `auto` selects UPS
Ground Saver unprompted, whose real 26-digit IMpb decodes to **Parcel Select**. Without the
guard the purchase hook would have written that onto a UPS Ground Saver package. The
regression test uses the real tracking number rather than a synthetic one. The guard now runs
both ways: a 1Z under a non-UPS carrier is a stop too.

**The UPS 1Z table could not meet the authority bar, so the bar was lowered on purpose.**
**UPS does not publish this mapping at all** — the bar was unreachable rather than unmet. The
table is built from observed pairs and says so in its own provenance: a 3PL's ~20-year
production history (whose long tail carries hand-typed numbers and is *not* clean, so only
high-count pairs were taken), corroborated by controlled Shopify purchases whose service was
known.

**The indicators are a fourth vocabulary**, and this is the hazard worth carrying forward.
They agree with UPS's API service codes on every domestic service and disagree on every
international one:

| Service | Tracking indicator | UPS API code |
|---|---|---|
| Ground / 2nd Day Air / 3 Day Select / NDA Saver / NDA | `03` `02` `12` `13` `01` | identical |
| Worldwide Saver | `04` | `65` |
| Worldwide Express | `66` | `07` |
| Worldwide Expedited | `67` | `08` |
| Standard | `68` | `11` |

A table built from UPS's published API codes — the obvious move — **looks confirmed on the
five domestic services and is silently wrong abroad.** A test pins the mistake rather than
only the behaviour: `65`, `07`, `08` and `11` must resolve to *nothing* as indicators. `04`
breaking the `66`/`67`/`68` contiguity is useful — the fall-through cases are `69` and `05`,
so extrapolating along the sequence turns a test red. Ten indicators; `YN` is unmapped and a
`Y` prefix is not assumed to be a family.

**A number UPS agrees to track is not thereby a number that validates.** `1Z000X00YW00000002`
— bought by hand in the Shopify admin — tracks on UPS's own site and its check digit does not
compute, so the rung declines it, correctly. Labels bought *through* the API carry
well-formed numbers with a real shipper prefix, so a development store can exercise this rung
end to end.

**Rung 2 matches a per-carrier token table, not a scan.** Label tokens are a third vocabulary
— DHL prints `GRD` for the product its API calls `GND`, and neither is a
`CarrierService.service_code`. The ZPL reader tracks whether a `^FD` field follows `^A` or
`^B`, so barcode payloads never enter the text stream. A token matches against the **whole
field**, never within it: word-boundary matching let `GROUND` match `FedEx Ground Economy`,
which is a *different* service and a USPS-last-mile one, so the bug named the wrong service
**and** hid a consolidator. A label naming two known services reports the collision rather
than picking one.

**Shopify is a passthrough for USPS, and USPS rung 2 is therefore descoped — not deferred.**
Measured on one Ground Advantage label bought both ways for the same package: same page size,
**identical font subset tags in identical order** (those tags are assigned by the generating
tool, so identical tags mean the same source document), identical images, identical service
token. USPS generates with Apache FOP and Shopify re-wraps through a Ruby library without
touching the content stream. So a USPS token *could* be sourced from our own labels — and
should not be, because rung 1 already resolves USPS domestic from the tracking number, which
costs nothing and survives `PurgePiiCommand`. The general rule: **where rung 1 covers a
carrier, rung 2 is redundant for it.**

**Rung 2 cannot read a Shopify UPS label, from any source — and that is a ceiling, not a
gap.** `UpsAdapter::buildShipmentRequest()` sends `LabelImageFormat` as ZPL or GIF; **PDF is
not among UPS's offers**, so a PDF from Shopify for a UPS label is a wrapper around the GIF
necessarily. The 4×6 and 8.5×11 versions of one label embed a byte-identical 1400×800 image.
No change of source, setting or capture method will produce a text layer.

## Not in scope: publishing what we infer

`confirmedService()` withholds anything not `confirmed`, and ADR-0003 decision 7 is
categorical: channel exports publish `confirmed` only, because a guess sent to a marketplace
becomes a buyer-facing fact we cannot retract. This will come under pressure once coverage is
high — a deterministic STC decode is a poor example of "a guess". Two notes for whoever
argues it: the evidence to argue with is the **coverage measurement**, not intuition; and if
it is ever granted, the change is to the **export rule** — `confirmed` plus a named allowlist
of inference methods — never to relabel a decoded value as `confirmed`.

## Remaining work

1. **The coverage measurement**, the last unticked criterion and the evidence base ADR-0003
   asks for. `app:infer-package-services` produces it; it needs Shopify packages to run over,
   which the purchase-time hook is now producing.
2. **`label-tokens.json` for Shopify's other carriers.** Both US carriers are finished as far
   as this can take them — USPS descoped above, UPS permanently unreadable. What remains is
   the other seventeen, gated on `14`'s install-base question. Do not add tokens from carrier
   documentation; what a carrier calls a service and what it prints routinely differ.
3. **International FedEx labels print `IP`/`XQ` rather than service names.** Ten of fourteen
   real labels fall through on this. Independent of everything else — it needs FedEx
   documentation and our own existing labels, not a Shopify purchase.
4. **Decide whether an honoured `preferredRateSelection` is evidence.** `02` established
   Shopify obeys it, so a package bought with `usps:PriorityExpress` carries a materially
   stronger claim than one bought with `auto` — where Shopify picked USPS once and UPS the
   next time from identical inputs — and the two are presently recorded identically. Whether
   an honoured selection outranks a decoded tracking number, or only fills in where the
   number declines, is a rung-ordering question and belongs here. It gains weight from the
   UPS finding: where the label cannot be read and the number cannot be decoded, an explicit
   selection may be the only evidence available.

## Comments

- **2026-09-06** — questions 1 and 2 answered by measurement; the ladder, both rungs, the
  write path and the coverage command built.
- **2026-09-06, review** — four defects, each with a regression test. **Token substring
  matching** (above). **Carrier aliases were not resolved**: both rungs compared
  `CarrierAlias::lookupKey()` against the raw `packages.carrier`, and that helper normalises
  text without resolving aliases — so Shopify reporting `US Postal Service` would have read
  as a non-USPS carrier and the consolidator guard would have refused to decode a genuine
  USPS package, for the life of the package. **The ruleset could serve a version that did not
  describe its own tables**: each table cached under its own key with its own TTL, so a
  deploy could leave the old table live beside the new version number — a value derived from
  old rules stamped with the new version would then never be re-derived, silently and
  permanently. **The write path checked and wrote non-atomically**, so a service confirmed
  between the read and the save was overwritten by a guess; all the guards are now conditions
  on a single `UPDATE`, mirroring `markShipped()`'s optimistic locking.
- **2026-09-06, second review pass** — `ServiceRulesetTest` rewrote the committed
  `ruleset.json` to prove a change on disk was picked up. Right assertion, wrong mechanism:
  under Paratest every other worker reading that file during the write gets a version nobody
  committed, and a killed run leaves the working tree dirty in the one file that is the
  authority on which rules produced a value. `ServiceRuleset` now takes an optional
  directory. Cleanup is registered at creation with the directory captured in the closure —
  an earlier `$GLOBALS` registry drained in `afterEach` leaked intermittently, which is the
  worst shape for this because a verification run can pass while the defect is present.
- **2026-09-09** — the purchase-time hook landed (item 1), then the UPS 1Z rung (item 3),
  then the international indicators as corroborating purchases came in.
- **2026-09-10, review** — **inference must not be able to fail a purchase** (above), and a
  comment claiming the UPS 1Z rung did not exist, left behind by the rung landing hours
  later. Worth naming the pattern rather than the line: that is the second stale-comment
  defect in two days on this path, after `20`. Comments here describe a vendor's behaviour
  and our coverage of it, and both move.
- **2026-09-10** — first live confirmation of the hook on a real Shopify label:
  `service = 'USPS Ground Advantage'`, `service_evidence = inferred`,
  `service_inference_method = 'usps-impb-stc'`, `service_ruleset_version = '2026-09-09'`.

## Related

- `10` — which left the service null for this to fill
- `14` — the label evidence the token tables need
- `15`, `16` — the two defects that had to be fixed before the ladder could run at all
