# Infer the service Shopify bought, and record how it was inferred

Status: ready-for-human

Repo: `polybag`

## Problem

Shopify never reports the service it bought. `ShippingLabel` exposes `id`, `location`,
`printed`, `cancellable`, `shippingDocuments` and `trackingInfo` — no service, no service
code, no rate, no price, before or after purchase. `10` therefore leaves `packages.service`
null, and `postage-source-split/11` landed the model that makes the absence legible:
`service_evidence` of `confirmed` / `inferred` / `unknown`, with an inference method and
ruleset version recorded whenever it is `inferred`.

Nothing produces `inferred`. Every Shopify package's service is null for its lifetime, and
`service` is what operators filter and sort by and what billing groups on.

ADR-0003 settles both halves of this already — decision 5 ("the service may later be
*inferred* from the tracking number — that is a different provenance, not a contradiction")
and a **To revisit** entry that lays out the method in full. This slice carries that entry
into work; it does not re-decide it. Read that entry before starting: everything below
elaborates it, and where the two disagree the ADR wins.

Inference is for our own reporting. It never reaches a marketplace: `confirmedService()`
already withholds anything that is not `confirmed`, and that stays true here.

## What to build

A ladder, cheapest and most reliable first, stopping at the first conclusive answer. Each
rung names itself in `service_inference_method` and stamps `service_ruleset_version`, so a
value can be re-derived and compared when the tables change.

The carrier is already known — `trackingInfo.company` is recorded at purchase — so every
rung only has to choose a service *within a known carrier*, which narrows every lookup table
below and makes an unrecognised carrier a clean stop rather than a guess.

**1. Decode the tracking number.** The carrier encodes the service in its own barcode:

- **USPS IMpb** carries a 3-digit Service Type Code identifying mail class, product and
  extra services. Source it from a current Pub 199 and the separately maintained STC list
  rather than transcribing from memory, and expect some STCs to cover several
  mail-class/extra-service combinations — those are inconclusive, not a coin flip.
- **UPS 1Z** documents a service-level indicator in bytes 9–10. Expect contract and regional
  codes that the published table does not cover.
- **DHL eCommerce, OnTrac, Canada Post** encode nothing usable. Stop, stay `unknown`.

**Validate the tracking number's format and check digit before inferring anything from it.**
A malformed or mistyped number that happens to have plausible digits in the service position
is the one way this rung produces a confident wrong answer rather than no answer.

This rung costs nothing, needs no label, and — importantly — `packages.tracking_number` is
not touched by `PurgePiiCommand`, so it is the only rung that can be re-run over historical
packages after a ruleset improves.

**2. Read the label's plaintext.** `packages.label_data` holds the bytes and
`packages.label_format` says which kind:

- **ZPL is plain text.** The human-readable service sits in a `^FD` field. No dependency,
  no parsing beyond a scan.
- **PDF needs text extraction.** No PDF-parsing library is in `composer.json` today, so
  this is a dependency decision rather than an implementation detail — see below. Shopify
  picks the format from the shop's own admin setting and the API offers no way to request
  one, so we do not get to choose the easy case.

This rung has a shelf life. `PurgePiiCommand` nulls `label_data` after `pii_retention_days`
(default 90, per-channel override, `0` keeps forever) because labels carry embedded
recipient PII. Inference therefore runs **at purchase time**, not lazily on demand — a
package inferred months later may have no label left to read.

**3. Fingerprinting or OCR.** The ADR calls OCR "disproportionate unless real data says
otherwise". Treat it as a decided non-goal rather than vague future work: do not build it
until measured coverage from rungs 1 and 2 says otherwise.

**Measure coverage against real Shopify packages**, and report it. The ADR asks for this
explicitly, and it is the evidence base for two later decisions: whether rung 3 is ever
worth building, and whether an inferred service should ever be published (see below).

**Cost stays unknown regardless.** Nothing here recovers a price — Shopify exposes none, and
no amount of service inference changes that.

**A write path, which does not exist yet.** `Package::markShipped()` is a one-shot
transition out of `Unshipped` guarded by optimistic locking, so it cannot be reused to
upgrade a package after the fact. This needs a narrow method of its own that only ever
moves `unknown` → `inferred`, never overwrites `confirmed`, never downgrades, and — when
re-run under a newer ruleset — replaces an inferred value along with its version stamp.
`Package::assertServiceEvidenceIsConsistent()` already refuses an inferred service that
cannot name both its method and its ruleset version; the new path must satisfy it rather
than route around it.

## Not in scope: publishing what we infer

`confirmedService()` withholds anything that is not `confirmed`, and that stays true no
matter how good inference gets. ADR-0003 decision 7 is categorical: channel exports publish
`confirmed` only, because a guess sent to a marketplace becomes a buyer-facing fact we
cannot retract, while omitting an optional field costs nothing.

This will come under pressure once coverage is high — a deterministic STC decode is a poor
example of "a guess". Two notes for whoever argues it:

- The evidence to argue it with is the coverage measurement above, not intuition. ADR-0003
  was itself accepted only once its premise was measured rather than argued.
- If it is ever granted, the change is to the **export rule** — publish `confirmed` plus a
  named allowlist of inference methods — and never to relabel a decoded value as
  `confirmed`. `confirmed` has to keep meaning "the postage source reported it", or the
  distinction the whole model rests on quietly disappears.

## What to answer

1. **Do we take a PDF text-extraction dependency, or restrict rung 2 to ZPL?** Restricting
   it means shops whose admin is set to PDF get no inference beyond the tracking number.
2. **Is OCR ever in scope, or explicitly `wontfix`?**
3. **How are ruleset versions numbered**, and where do the STC and 1Z tables live —
   `resources/data/` holds only carrier test cases today, and carrier reference data is
   otherwise seeded from source.

## Acceptance criteria

- [x] A USPS tracking number with an unambiguous Service Type Code yields the service with
      evidence `inferred`, and both the method and the ruleset version recorded
- [x] An ambiguous Service Type Code, or a carrier that encodes nothing, falls through
      rather than picking one
- [x] A ZPL label yields the service where the tracking number was inconclusive
- [x] Exhausting every rung leaves the package `unknown` with its requested preference
      intact — never a guess written as the service value
- [x] An inferred service is never written over a confirmed one, and never downgrades
- [x] Re-running under a newer ruleset replaces the value and its version stamp together
- [x] Nothing inferred reaches a channel export
- [x] The STC and service-indicator tables are versioned data, not literals inline in a
      service class — both tables now exist under `resources/data/service-inference/`
- [x] A tracking number failing format or check-digit validation infers nothing
- [ ] Coverage against real Shopify packages is measured and reported — what fraction each
      rung resolved, and what was left `unknown`

## Blocked by

- `01-verify-first-live-label-purchase` — there is no real Shopify label to validate any of
  this against until one has been bought
- `postage-source-split/11-service-provenance-and-evidence` — done; the model this writes into

## Comments

### 2026-09-06 — questions 1 and 2 answered: take the PDF dependency, OCR is `wontfix`

Measured rather than argued, against the label PDFs already sitting in `storage/app` — 17
FedEx sandbox and USPS labels, direct from the carrier APIs. Not Shopify labels; see the
caveat at the bottom.

**Q1 — do we take a PDF text-extraction dependency? Yes: `smalot/pdfparser`.**

The dependency is close to free:

- Its only Composer dependency is `symfony/polyfill-mbstring`, already in `composer.lock`.
  Everything else it needs is `ext-iconv` and `ext-zlib`, both default in `php:8.4-fpm`.
  **No `Dockerfile` change** — which matters, because the alternative does need one.
- 728K of vendor, 46 files, ~9k LOC, pure PHP, no temp files, no `proc_open`.
- `composer audit` clean at v2.12.5.
- 5–45ms per label, ~19ms average.

`spatie/pdf-to-text` was the alternative and is strictly worse here: it needs
`poppler-utils` added to the image and shells a binary out over label bytes, for the same
result.

It handles the case that actually decides this. Two font regimes appear in the sample, and
the second is the one that kills a hand-rolled `Tj`/`TJ` scanner:

| Label | Fonts | Extracted |
|---|---|---|
| FedEx sandbox domestic | base-14 Type1, MacRomanEncoding | `EXPRESS SAVER`, `GROUND`, `PRIORITY OVERNIGHT` |
| USPS international | Type0 / CIDFontType2 / **Identity-H**, subset | `FIRST-CLASS PKG INT'L SERVICE` |

Under Identity-H the text bytes are glyph IDs, not characters, so extraction requires
following the font's ToUnicode CMap. `smalot` does; a scan for `^FD`-style plaintext, or
anything we would write ourselves in an afternoon, does not. That is the whole argument for
taking a library rather than rolling one.

All 17 PDFs parsed, none empty. The **`SAMPLE` watermark on sandbox labels is a non-issue** —
it extracts as one more text run beside everything else rather than overlaying the text
layer, so sandbox labels are fine for proving the extraction path (they are not, of course,
evidence about production *layout*).

Restricting rung 2 to ZPL was the other option in the question and should be rejected: the
format is chosen in the shop's own admin, we cannot request one, and restricting would blank
out rung 2 entirely for every shop set to PDF.

**Q2 — is OCR ever in scope? Explicitly `wontfix`, and for a sharper reason than
"disproportionate".**

The carriers OCR would buy us are DHL eCommerce and OnTrac, which can return PNG. Those are
also the two that encode nothing usable in the tracking number. So OCR's entire yield is
exactly the set of carriers this issue already accepts as terminal `unknown` — it does not
rescue a case that rungs 1 and 2 half-solve, it opens a case they do not touch at all.
Canada Post returns PDF or ZPL and so is covered by rung 2.

That reframes the coverage measurement in the acceptance criteria: it is not "does OCR pay
for itself", it is "how much Shopify volume routes through DHL eCommerce and OnTrac". If
that is small, OCR stays closed on volume grounds and nothing further needs deciding.

**Three implementation notes that fell out of the measurement:**

1. **Sniff the magic bytes; do not trust `packages.label_format`.** Two files in the FedEx
   test-run fixtures are named `label.pdf` and contain ZPL. `smalot` rejected them loudly
   (`Invalid PDF data: Missing '%PDF-' header`) rather than returning garbage, which is the
   behaviour we want — but rung 2 should dispatch on content, and a format mismatch should
   fall through to `unknown` rather than raise.
2. **Not every label spells the service out.** The FedEx international sample prints `IP`
   and `XQ` where the domestic one prints `EXPRESS SAVER`. Rung 2 therefore needs the same
   per-carrier versioned lookup table treatment as rung 1 — it is not "grep for a service
   name" — which folds it into question 3 rather than leaving it a free implementation
   detail.
3. **Rung 2 stays a purchase-time step regardless.** Nothing here changes the
   `PurgePiiCommand` retention argument in the body.

**The caveat, unchanged.** Every PDF measured above came from a carrier API directly. For
Shopify Shipping, Shopify is plausibly the label *producer* rather than a passthrough, so
layout and tokens could differ from anything tested here. This does not change the
dependency answer — a Shopify-rendered label is still overwhelmingly likely to carry a text
layer — but it does mean the rung 2 lookup tables should not be written out in full before
`01-verify-first-live-label-purchase` produces a real label to read.

### 2026-09-06 — a DHL eCommerce sample corrects the body, and exposes a wrong-answer path

Worked through the sample ZPL response in DHL eCommerce's published v4 API documentation —
sandbox, synthetic addresses, `V4-TEST-` package ID. It contradicts one line of **What to
build** and turns rung 1 from "not applicable" into "actively hazardous" for consolidators.

**Correction to the body.** The bullet at *What to build* rung 1 reads "**DHL eCommerce,
OnTrac, Canada Post** encode nothing usable. Stop, stay `unknown`." For DHL eCommerce US
that is wrong. DHL eCommerce hands off to USPS for final delivery, and the label carries a
genuine **USPS IMpb** — the sample's tracking ID is a GS1-128 `420` + destination ZIP
followed by a 22-digit IMpb, and the label prints `US Postage Paid / Global Mail / eVS` and
`USPS TRACKING # eVS` in plaintext.

It is not merely present, it **passes validation**:

```
AI(2) = 93   STC(3) = 748   MID(9) = 6……000   serial(7) = 1……9
check digit: stated 9, computed 9  => VALID
```

So the format-and-check-digit gate the issue relies on as its safety mechanism does not stop
this. Rung 1 would proceed to look up STC 748.

**Why that is the hazard.** Whatever that STC resolves to, it names the **USPS last-mile
product**, not the service the customer bought. The sample's DHL service is `GRD`
(`orderedProductId: GND`), while the label's own USPS-facing banner is `PS LIGHTWEIGHT` —
Parcel Select Lightweight. Decoding the STC and writing the result into `packages.service`
for a package whose carrier of record is DHL eCommerce produces a **validated, confident,
wrong answer** — the exact failure mode the acceptance criteria are written to prevent, and
one that check-digit validation cannot catch because the number is genuine.

**Proposed rule: carrier/number-family disagreement is a stop, not a decode.** The carrier
is already known from `trackingInfo.company` before rung 1 runs. If the company is not USPS
but the tracking number is a structurally valid IMpb, we are looking at a consolidator
hand-off and rung 1 must stay `unknown` rather than decode. This generalises past DHL
eCommerce to any USPS-workshare consolidator, and it is cheap — it is a comparison we can
already make with data we already hold.

**Rung 2 on the same label: also a trap for a naive scan.** The ZPL has 30 `^FD` fields. The
DHL service `GRD` is field **30**. `PS LIGHTWEIGHT` is field **4**. A scan that harvests
`^FD` values and matches the first service-looking token records Parcel Select Lightweight
and is wrong in the same direction as rung 1. Three further structural requirements fall out
of the same field list:

1. **`^FD` is not always text.** Fields 13, 26 and 28 are barcode payloads — field 26 is the
   raw IMpb complete with Code 128 subset-switch escapes (`>;>8…!>;`). Rung 2 must track the
   preceding format command (`^A0` text vs `^BC`/`^B2`) rather than harvesting every `^FD`.
   Read deliberately, the barcode fields are useful: field 26 cross-checks the tracking
   number against rung 1.
2. **Label tokens are a third vocabulary.** The label prints `GRD`; DHL's own API calls the
   same product `GND`; neither is a `CarrierService.service_code`. The rung 2 table maps
   *label tokens*, and cannot be derived from a carrier's API product list.
3. **Tolerate empty and escaped fields.** Field 6 is `^FD^FS` with no content, and the
   sample arrives with literal `\n` escapes interleaved with real newlines — normalise
   before scanning.

**Net effect on the Q2 answer above.** The earlier comment said OCR's entire yield is DHL
eCommerce and OnTrac. Narrow that: DHL eCommerce can return **ZPL as well as PNG**, and its
ZPL is rung-2 readable — so DHL is only an OCR case for shops that get PNG. That further
shrinks the population OCR would serve, and strengthens `wontfix`.

**What this does not change.** All of it is DHL's own API surface, which we never see through
Shopify — `shippingDocuments` gives us the file, not the JSON, so `labelDetail.serviceLevel`
is not available to us on this path. The finding is about the label bytes and the tracking
number, both of which we do get.

### 2026-09-06 — the ladder is built and tested; everything that needs a real label is not

Implemented against what is knowable without a Shopify purchase. Both rungs run, the write
path enforces its invariants, and the coverage command exists — but the coverage *number*
this issue asks for does not, because there are no Shopify packages to measure. Question 3
is answered by the shape of what landed; questions 1 and 2 were answered above.

**Answer to question 3 — ruleset versioning and where the tables live.**
`resources/data/service-inference/`, committed as JSON rather than seeded. A package stamped
with a ruleset version has to stay comparable against the tables that produced it, and a
database row that has since been reseeded cannot offer that.

- `ruleset.json` carries a single `version` — an ISO date, bumped when any table changes,
  and what lands in `packages.service_ruleset_version`. ISO dates compare as strings, which
  is what "newer ruleset" needs and what `varchar(32)` affords.
- Each table carries its own upstream provenance and effective date, separate from our
  version. `usps-impb-stc.json` records USPS's own effective date, so an inference can be
  traced to a specific published appendix and not merely to a date we chose.

**The USPS table is generated, never transcribed.** `php artisan
app:build-service-inference-ruleset <appendix.xlsx>` reads USPS's published *Service Type
Codes Appendix I* and writes the JSON; the raw spreadsheet is in `.scratch/service-inference/`.
It reads the workbook with `ZipArchive` and `simplexml`, so no spreadsheet dependency. The
appendix current at time of writing is effective 2026-06-24 and yields **342 codes, 338 with
a product**. The remaining four — Periodical and Saturation variants, and a return-receipt
row that is an extra service rather than a parcel product — record a null product and are
treated as inconclusive. Guessing a product for a row USPS writes irregularly is the
confident-wrong-answer this ladder exists to avoid.

**What landed:**

| | |
|---|---|
| `smalot/pdfparser` | the dependency argued for above. One new package; no `Dockerfile` change |
| `ServiceInferrer` | the ladder. Stops at the first conclusive rung, names the rung, stamps the version |
| `ImpbTrackingNumber` | IMpb parse, `420`-prefix strip, mod-10 check digit. Nothing reads the STC before the check digit passes |
| `LabelTextExtractor` | ZPL and PDF to text, **dispatching on magic bytes, not `label_format`** |
| `ServiceRuleset` | loads and caches the versioned tables |
| `Package::recordInferredService()` | the write path |
| `app:infer-package-services` | coverage measurement; reports by default, writes under `--apply` |

**The consolidator guard from the previous comment is in.** Rung 1 refuses to decode a valid
IMpb carried by a non-USPS carrier. This turned out to matter more than the DHL sample
suggested: `CarrierSeeder` already documents FedEx Ground Economy (`SMART_POST`) and UPS
Ground Saver (`92`/`93`) as USPS-last-mile services, so **our own direct FedEx and UPS
packages carry IMpbs today**. Without the guard, a re-run over historical packages — which
this issue explicitly wants, since tracking numbers survive `PurgePiiCommand` — would write a
USPS mail class onto every Ground Economy and Ground Saver parcel. Those are `confirmed`, so
the write path would have refused them anyway; the guard is what stops the same thing
happening to an `unknown` one, and stops the coverage measurement being quietly wrong.

Note the DHL sample's own STC, `748`, is absent from the June 2026 appendix, so that
particular label would have fallen through regardless. That is luck, not a rule, and not
what the guard rests on.

**Rung 2 matches a per-carrier token table, not a scan.** `label-tokens.json` maps tokens as
*printed*, which is a third vocabulary — the DHL sample prints `GRD` for the product its own
API calls `GND`, and neither is a `CarrierService.service_code`. The ZPL reader tracks
whether a `^FD` field follows `^A` or `^B`, so barcode payloads never enter the text stream;
a test asserts the raw IMpb and its Code 128 subset escapes stay out. A label naming two
known services reports the collision rather than picking one.

**Measured coverage, such as it is.** Over the 14 real FedEx PDFs in `storage/app`, rung 2
resolved **4** — Express Saver, Ground and Priority Overnight. The other ten are
international labels printing `IP` and `XQ` rather than a service name, plus the two ZPL
files misnamed `label.pdf`, which fall through cleanly rather than raising. Read this as
evidence the mechanism works end to end on real bytes, **not** as a coverage figure: these
are direct-carrier sandbox labels, not Shopify ones, and the token table only covers what
those labels happen to print.

## Remaining work

~~Blocked on `01-verify-first-live-label-purchase` unless noted.~~ **Stale as of
2026-09-08** — the terms of service gate is cleared and labels can be bought freely on a
development store. All five items below are reachable; see the comment at the foot of this
file for the order they should be done in.

1. ~~**Wire inference into the Shopify purchase path.**~~ **Done 2026-09-09** — see the
   comment at the foot of this file.
2. **Populate `label-tokens.json` for Shopify's carriers.** Every entry there was
   transcribed from a label we hold: FedEx sandbox PDFs, and DHL eCommerce's documentation
   sample. Do not add tokens from carrier documentation; what a carrier calls a service and
   what it prints routinely differ.

   **The open premise is resolved and both US carriers are out of scope** (2026-09-10, below).
   Shopify passes USPS's label through rather than rendering its own — so a USPS token could be
   sourced from our own labels, but should not be, because rung 1 already resolves USPS
   domestic and rung 2 has nothing left to add there. UPS is unreadable by rung 2 whatever we
   do. What remains is the other seventeen carriers, gated on `14`'s install-base question.
3. ~~**The UPS 1Z service-indicator rung is not built.**~~ **Done 2026-09-09** — with the
   authority bar deliberately lowered, and why recorded in the table's own provenance. See
   the comment at the foot of this file.
4. **The coverage measurement itself**, which is the last unticked acceptance criterion and
   the evidence base ADR-0003 asks for. `app:infer-package-services` produces it; it needs
   Shopify packages to run over.
5. **International FedEx labels print `IP`/`XQ` rather than service names.** Ten of the
   fourteen real labels fall through on this. Worth extending the token table for, but the
   abbreviations need confirming against FedEx's own documentation rather than inferred
   from one sandbox label each.

### 2026-09-06 — code review: four defects fixed, two of them in the guard itself

All four confirmed against the code and fixed, each with a regression test.

**1. A shorter token matched inside a longer service name.** Token matching used a
word-boundary search, so `GROUND` matched the field `FedEx Ground Economy`. Ground Economy is
`SMART_POST` — FedEx's *USPS-last-mile* service, and a different service from FedEx Ground.
So the bug named the wrong service **and** hid a consolidator, which is the same failure
rung 1's guard was built to prevent, arriving through rung 2 instead.

Fixed by matching a token against the **whole field**, never within one. A field the table
has no exact entry for now falls through to `unknown`, which is the right direction to fail
in. This also removed the need for longest-first ordering, so that went too.

The fix exposed a second problem in the data: three of the six FedEx tokens
(`STANDARD OVERNIGHT`, `FIRST OVERNIGHT`, `HOME DELIVERY`) were never observed on any label
we hold — they were added by analogy, against `label-tokens.json`'s own stated rule. `GROUND`
was worse: it only ever "worked" through the substring bug, because the label actually prints
`FedEx Ground`. The table is now the four tokens we have really seen printed. Fewer rows, and
every one of them traceable to a label.

**2. Carrier aliases were not resolved.** Both rungs compared `CarrierAlias::lookupKey()`
against the raw `packages.carrier`, and that helper only normalises text — it does not
resolve aliases. Shopify reports `US Postal Service` in `trackingInfo.company`, which the
consolidator guard would have read as a non-USPS carrier and refused to decode: a genuine
USPS package inferring nothing, for the whole life of the package. The label token table
missed the same way.

Both rungs now work from a canonical carrier resolved through `carrierOfRecordName()` and
`CarrierNormalizer`, falling back to the raw value so an unmapped carrier stays the valid
terminal state ADR-0003 decision 8 requires.

**3. The ruleset could serve a version that did not describe its own tables.** Each table was
cached under its own key with its own hour-long TTL, so a deploy could leave the old lookup
table live beside the newly deployed version number. A value derived from old rules would
then be stamped with the new version — and because the re-run path only replaces a value
whose stamp is *older*, that package would never be re-derived. Silent, and permanent.

Now loaded as one unit and memoized per instance. The files total 124KB and a batch run
resolves one `ServiceRuleset`, so per-request caching bought little and cost the one
invariant the version stamp exists to provide.

**4. The write path checked and wrote non-atomically.** `recordInferredService()` read
`service_evidence` off the loaded model, then saved. A service confirmed between those two
points was overwritten by a guess. That is not hypothetical here: this runs over packages in
bulk while shipping continues.

All the guards are now conditions on a single `UPDATE` — mirroring the optimistic locking
`markShipped()` already uses — so a concurrent confirm makes this lose the race rather than
win it. The in-memory model is synced only when the update actually applied.

**Coverage is unchanged at 4 of 14** real FedEx labels after the stricter matcher, so nothing
that resolved by accident was propping the number up.

### 2026-09-06 — code review, second pass: the ruleset path is injectable

`ServiceRulesetTest` rewrote the committed `ruleset.json` to prove a change on disk was
picked up rather than served from a stale cache. Correct thing to assert, wrong way to
assert it: the suite runs under Paratest at up to sixteen processes, so every other worker
reading that file during the write gets a version nobody committed. The `finally` that
restored it also only covers exceptions — a killed run leaves the working tree dirty, in a
file whose whole purpose is to be the authority on which rules produced a value.

`ServiceRuleset` now takes an optional directory, defaulting to the committed tables. The
tests build a throwaway copy under the system temp directory and point a ruleset at it, so
nothing under `resources/` is ever written during a test.

Two notes on the fix:

- The temp-directory cleanup registers the directories it created and removes only those. A
  `glob()` over the temp directory would have let one worker delete another's ruleset
  mid-test — the same defect one level up, which is worth naming because it is the obvious
  way to write that helper.
- The replacement test is also a better test. Rather than asserting a file change is seen,
  it points a ruleset at a directory whose version *and* lookup table both differ and
  asserts both come back changed together, which is the atomicity invariant itself rather
  than a proxy for it.

**The first cleanup attempt leaked, intermittently.** A registry in `$GLOBALS` drained in an
`afterEach` cleaned up correctly on a serial run and on some parallel ones, and left empty
directories behind on others — the worst shape for this, because a verification run can pass
while the defect is present. It did, here: an earlier "no temp directories left behind"
check happened to run against a clean state.

Cleanup is now registered at creation, with the directory captured in the closure. It cannot
depend on a registry surviving the test lifecycle, it runs even when a test aborts before
teardown, and it is scoped to one directory, so no worker can remove another's. Verified by
running the focused file five times and the full parallel suite five times — zero directories
left after every one — and by confirming a process that throws mid-test still cleans up.

Full suite stable across all five parallel runs: 2042 passed, 2 skipped, 5617 assertions,
identical each time, with `resources/data/service-inference/` unmodified.

### 2026-09-08 — unblocked, and item 1 moves ahead of the rest

The blocker this issue was parked on is gone: `01` bought two labels through the API, and
on a development store they are test labels that cost nothing. `15` and `16` — both closed
the same day — are what make the ladder actually run against them. Rung 1 now reads the
26-digit IMpb it was declining, and `ups_shipping` normalizes to UPS instead of leaving a
package with no carrier of record, which the consolidator guard and the token table both
depend on.

**Reorder the remaining work.** Item 1 was written as "deliberately not done… it should not
go in unvalidated". The way to validate it is now available, and the cheapest way to get it
is to wire the hook in **before** `14`'s capture campaign rather than after. Then every
capture purchase is also a test of the hook, at no extra cost, and it runs in the position
it has to run in anyway — at purchase time, before `PurgePiiCommand` nulls `label_data`.
Wiring it in with empty token tables is fine and is the point: it should stamp
`ServiceEvidence::Unknown` honestly, and that is worth seeing on a real purchase.

So: item 1, then `14`'s captures, then item 2 (tokens, from those captures), then item 3
(the UPS 1Z rung), then item 4 (the coverage measurement, which is the last unticked
acceptance criterion and needs the other three to mean anything).

**Item 3 has a new constraint from `01`, and it sharpens rather than softens.** Both UPS
labels from that store carried a non-numeric service indicator in bytes 9–10 — and UPS's own
tracking recognised the number and followed it through a void. So those are values *we*
cannot decode, not values UPS rejects, and the table has to fall through to rung 2 on an
unrecognised indicator instead of treating it as a coverage gap. Same discipline the STC
table already applies.

**Item 5 (FedEx `IP`/`XQ`) is independent of all of this.** It needs FedEx documentation
and our own existing labels, not a Shopify purchase, so it can be picked up at any point.

### 2026-09-09 — an explicit selection is now better evidence than the ladder assumes

`02` established that `preferredRateSelection` is honoured: a purchase asking for USPS
Priority Mail Express came back priced as Priority Mail Express, an order of magnitude
above what `auto` chose from the same inputs. `ShopifyAdapter` nonetheless records
`service: null` and `ServiceEvidence::Unknown` for every Shopify purchase, `auto` and
explicit alike, because Shopify still reports no purchased service on the label.

That is the right default and probably not the right rule any more. A package bought with
`usps:PriorityExpress` carries a *requested* service the seller is now observed to obey,
which is a materially stronger claim than `auto` — where Shopify picked USPS once and UPS
the next time from identical inputs — and the two are presently recorded identically.

Not changed unilaterally, for two reasons. Four honoured purchases is not a rule, and the
ladder's rung ordering is this issue's decision rather than the adapter's: whether an
honoured selection outranks a decoded tracking number, or only fills in where the number
declines, is exactly the sort of thing the coverage measurement here exists to settle.
Worth deciding with the `14` captures in hand, since those are bought with explicit
selections and so produce the paired evidence — requested service, decoded number, and
label text — that the question needs.

### 2026-09-09 — read the label from the API, never from the admin's print dialog

A trap worth naming before anyone tests rung 2 against a real Shopify label. The same label
exists in two forms and only one of them is readable.

| Source | Fonts | `pdftotext` | Images |
|---|---|---|---|
| Shopify admin's print dialog | **none** | **0 bytes** | one full-page grayscale bitmap |
| `shippingDocuments[].url` — what `ShopifyShippingLabelService` downloads | four embedded Arial/Consolas subsets | 308 bytes, service name included | three tiny indexed images, the barcodes |

Shopify **rasterises when it renders for printing**. The document the API hands us is
text-bearing, and `pdftotext` lifts `PRIORITY MAIL EXPRESS®` out of it directly.

**This nearly produced a wrong architectural finding.** Measured on admin renders alone — the
obvious thing to grab, since that is what a person has open when they print a label — rung 2
is dead for every Shopify carrier and OCR is the only route, which would have reopened
question 2 after it was settled `wontfix`. It is not dead. It works on the bytes that reach
`packages.label_data`, which is the only file this rung ever sees.

So question 1's answer holds and is now observed rather than reasoned: taking the PDF
text-extraction dependency was right, and for Shopify labels it is the *only* rung-2 path —
there is no ZPL alternative to fall back to, because Shopify's format setting selects a paper
size and never reaches the `ZPL` value the API's enum can carry (`01`, same date).

**One confound not cleared.** The text-bearing sample is USPS; the rasterised ones are UPS
Ground Saver. "API is text, admin render is raster" fits the evidence and so does "USPS is
text, UPS is raster". Clearing it needs a UPS label bought through PolyBag so its API
document is captured — on `14`'s list, and it should be captured before anyone sizes the
extraction work, because the second reading would halve rung 2's reach on this carrier.

**A consolidator case arrives by default, not as an edge case.** The UPS Ground Saver label
prints `UPS GROUND SAVER`, `USPS PARCEL SELECT` and `US POSTAGE PAID / UPS / eVS` on one
face, and carries both a `1Z` and a 26-digit IMpb. That is exactly the wrong-answer path the
DHL eCommerce sample exposed above, and Shopify's `auto` selection hands it over without
being asked — so the consolidator guard is on the default path for this seller, not a corner
of it.

### 2026-09-09 — correcting the comment above: the split is by carrier, not by source

The comment above says Shopify rasterises when it renders for printing and that the API's
document is text-bearing. It states its own confound — text-bearing sample USPS, rasterised
samples UPS — and the confound has since resolved **against** that reading.

An international UPS label fetched from `shippingDocuments[].url`, the API and not the print
dialog, has **zero fonts, zero extractable text, and the same 1400×800 grayscale bitmap** the
admin renders had.

So the axis is the carrier:

| Carrier | API document | Rung 2 |
|---|---|---|
| USPS | embedded fonts, service name extractable | works |
| UPS | one full-page bitmap, no text at all | **unreadable, from any source** |

This is the branch the earlier comment named as the worse one. **Rung 2 cannot read a Shopify
UPS label**, and no change of source fixes it — the admin-versus-API distinction was a wrong
inference from comparing a UPS render against a USPS document.

What survives of that comment: take captures from the API anyway, because the admin lets a
person choose the page size at print time and the API document is always 4×6. That is a
reason about size, not about text.

**What this costs.** For Shopify UPS packages the ladder is rung 1 only — and rung 1 needs
the UPS 1Z service-indicator table, which is not built, on numbers whose indicator bytes have
so far been values no published table lists (`YW`, `YN`). So a Shopify UPS package currently
infers nothing by either rung. That is the concrete coverage answer this issue exists to
measure, arriving before the measurement.

Two things follow, neither decided here:

- The OCR question closed `wontfix` on the reasoning that a PNG-only carrier with real volume
  would be the only thing to reopen it. A **bitmap-only carrier** is that thing, and UPS is
  not a marginal carrier. Worth re-reading that decision with this in hand rather than
  treating it as settled.
- The honoured-`preferredRateSelection` idea in the comment above gains weight: where the
  label cannot be read and the number cannot be decoded, an explicit selection Shopify is
  observed to obey may be the only evidence available for a UPS purchase.

### 2026-09-09 — the hook is in, and UPS's own API settles why its labels are unreadable

**Remaining work item 1 is done.** `ShopifyAdapter::createShipment()` runs the ladder over
the label it just bought and folds the result into the `ShipResponse`, so evidence is
`inferred` with a method and a ruleset version where a rung concluded, and `unknown` where
none did. It goes in through `markShipped()` and therefore through
`assertServiceEvidenceIsConsistent()` — one transition, already validated — rather than a
second write after the fact.

The ladder previously read a saved `Package`, which at purchase time does not exist yet: the
tracking number and the label bytes are still only in the response. So `ServiceInferrer` gains
`inferFrom(?string $carrier, ?string $trackingNumber, ?string $labelData)` and `infer(Package)`
delegates to it. No rung changed.

**What it actually yields today, per carrier.** Worth stating plainly, because the answer is
"less than the ladder is capable of" and the reasons are different on each side:

| Carrier | Rung 1 | Rung 2 | Result |
|---|---|---|---|
| USPS | decodes the STC | no USPS tokens in `label-tokens.json` yet | inferred, rung 1 |
| UPS | consolidator guard stops it | nothing to read | **`unknown`** |

USPS rung 2 is idle only because the token table has no USPS entries — the table's own rule
is that a token is transcribed from a label we hold, and that transcription is item 2 below,
which belongs to `14`. The real Shopify USPS label *is* readable; nothing is being read off
it yet.

**The UPS half is not a gap, it is a ceiling, and UPS's API says so.** The open question in
the comment above was whether a Shopify UPS label is a raster by rendering accident.
It is not. `UpsAdapter::buildShipmentRequest()` sends `LabelImageFormat` as `ZPL` or `GIF`
([`app/Services/Carriers/UpsAdapter.php:824`](../../../../app/Services/Carriers/UpsAdapter.php)) —
those are the only two UPS offers, and **PDF is not among them**. A PDF from Shopify for a UPS
label is therefore a wrapper around the GIF necessarily, not by choice, so no change of
source, setting or capture method will ever produce a text layer.

The two captured documents confirm the wrapping directly: the 4×6 and the 8.5×11 versions of
the same label embed a **byte-identical** 1400×800 grayscale image (same md5, 34.6K), and at
the recorded 233×200 ppi that image is exactly 6.0″×4.0″. The page-size setting changes the
wrapper and nothing else.

So for Shopify UPS packages the ladder is rung 1 or nothing, permanently — and rung 1 is the
one that must decline. That is a real coverage answer, not a defect to fix, and it is the
input the reopen-OCR question in the comment above was waiting on.

**The consolidator guard turns out to be load-bearing on the default path.** The real Ground
Saver label's 26-digit IMpb is genuine and parses clean, and its service type code `612`
resolves to **Parcel Select** in the June 2026 appendix. Without the guard the purchase hook
would have written "Parcel Select" onto a UPS Ground Saver package — a validated, confident,
wrong answer, on the selection Shopify's `auto` makes by default. The regression test uses
that real tracking number rather than a synthetic one, so the case cannot quietly stop being
covered.

**Tests.** Four in `ShopifyAdapterTest`: rung 1 inferring at purchase and surviving
`markShipped()` with `confirmedService()` still null; rung 2 reading the label bytes during
the purchase; the UPS label inferring nothing; and a confirmed service refusing to be
overwritten, so the new hook cannot become a second way past that invariant. The existing
"leaves the service unknown" test was passing for the wrong reason — its fake tracking number
failed the check digit — and now uses a number that parses and names no product, so both rungs
run and neither concludes.

**No ruleset bump.** No table changed, and the version stamp has to keep meaning "the tables
that produced this value".

**Still unticked:** the coverage measurement. It needs Shopify packages in a database to run
over, and this hook is what will start producing them.

### 2026-09-09 — the UPS 1Z rung, and the authority bar it could not meet

**Remaining work item 3 is done.** Rung 1 now dispatches on the barcode family: a valid 1Z
goes to the UPS service level indicator table, anything else to the IMpb path as before. A
Shopify UPS package went from inferring nothing by either rung to inferring on rung 1.

**The bar this issue set could not be met, so it was lowered on purpose.** The text said the
1Z rung "wants a source at least as authoritative as the USPS appendix". There is no such
source: **UPS does not publish this table at all**, and the appendix bar is unreachable rather
than merely unmet. The honest options were to leave the rung unbuilt indefinitely or to build
it from observed pairs and say so, and the second is what `service_ruleset_version` exists to
make revisable. `ups-1z-service-indicator.json` records `"source": "observed
tracking-number/service pairs, not published by UPS"` rather than dressing the evidence up.

**The evidence.** A 3PL's production shipment history, grouped by service against bytes 9–10:
roughly 20 years of shipments, so the long tail carries hand-typed tracking numbers and
hand-picked service labels and is *not* clean. That is why the table takes only the high-count
pairs. `YW` was additionally checked in the other direction — the direction inference actually
needs — and **10,495 shipments carry `YW`, every one of them SurePost**.

**The finding worth more than the YW row: this is a fourth vocabulary.** The observed
indicators agree with UPS's own API service codes on every domestic service and disagree on
every international one:

| Service | Tracking indicator | UPS API service code | |
|---|---|---|---|
| Ground | `03` | `03` | ✅ |
| 2nd Day Air | `02` | `02` | ✅ |
| 3 Day Select | `12` | `12` | ✅ |
| Next Day Air Saver | `13` | `13` | ✅ |
| Next Day Air | `01` | `01` | ✅ |
| Worldwide Saver | `04` | `65` | ❌ |
| Worldwide Express | `66` | `07` | ❌ |
| Worldwide Expedited | `67` | `08` | ❌ |

That partial overlap is the hazard, and it is worse than no overlap. A table built from UPS's
published API codes — the obvious thing to reach for — looks confirmed on the five domestic
services and is then silently wrong on international. **The international rows are therefore
deliberately absent** rather than filled in: they rest on one noisy dataset and nothing
corroborates them. The five domestic rows carry two independent sources precisely *because*
the two vocabularies coincide there.

This is the fourth vocabulary this campaign has hit, after Shopify's PascalCase USPS codes,
DHL printing `GRD` for the product its API calls `GND`, and the label token table itself.

**`YN` is not mapped**, and a `Y` prefix is not assumed to be a Ground Saver family. It was
seen on a dev-store label whose service is not known, and guessing a family is the confident
wrong answer this ladder exists to refuse.

**One dev-store number is a placeholder, and it is the one bought by hand.**
`01` recorded UPS's tracking page accepting `1Z000X00YW00000002` and following it through a
void, which read as evidence the number is real. It tracks, but **its check digit does not
compute**, so this rung declines it — correctly, since reading a service out of a number that
fails validation is the confident wrong answer the ladder exists to refuse. That number came
from a label bought by hand in the Shopify admin. Every label bought *through PolyBag* against
the same store carries a well-formed number, which is where the check digit algorithm was
verified.

**The guard now runs in both directions.** An IMpb under a non-USPS carrier was already a
stop; a 1Z under a non-UPS carrier is now one too, for the same reason — the number and the
carrier of record cannot both be right, and picking one is not this rung's job.

**Which number Shopify reports decides the answer**, and on a Ground Saver label the two
disagree: the 1Z decodes to UPS Ground Saver, the IMpb printed beside it decodes to Parcel
Select, the USPS product carrying the last mile. `01`'s record says Shopify reports the 1Z, so
the rung that can answer is the one that gets the number — but both paths are pinned by test,
because that is a fact about Shopify's behaviour rather than a guarantee.

**Ruleset version bumped to `2026-09-09`.** A table changed, unlike the purchase-path hook
earlier the same day.

**One duplication removed on the way.** `ServiceRulesetTest`'s temporary-ruleset helper
restated the table list that `ServiceRuleset` loads, so adding a table broke two unrelated
tests instead of failing where the change was. It now reads the list off the committed
directory.

### 2026-09-09 — an international row earns its place, and the dev-store caveat was too broad

Two corrections to the comment above, both from one Shopify test purchase.

**`67` is confirmed twice, independently, so Worldwide Expedited is now mapped.** A
Shopify-bought UPS Worldwide Expedited label came back as `1Z28X87G6713238443` — indicator
`67`, the same value the production shipment history carries against the same service. Those
are two unrelated sources: a 3PL's twenty-year history and a label bought this afternoon. The
reason the international rows were held back was that each rested on one noisy source; that is
no longer true of `67`.

`68` is mapped too, on Shopify-bought **UPS Standard** labels to Canada. One source rather than
two, but a controlled purchase whose service was known at the time — a different quality of
evidence from the production history's hand-entered tail, and the distinction the table's
provenance now records.

**`04` and `66` stay out.** Each still rests on that hand-entered tail alone. Sitting next to a
confirmed `67` is not evidence, and the apparent `66`/`67`/`68` contiguity is noted in the
table precisely so nobody extrapolates from it.

The divergence the previous comment described gets wider, not narrower: Worldwide Expedited is
`67` against API code `08`, and Standard is `68` against `11`. Neither could have been guessed
from UPS's documentation.

**Correcting "the dev store's 1Z numbers are synthetic".** That was drawn from one number and
generalised too far. Checked against the implementation:

| Number | Origin | |
|---|---|---|
| `1Z28X87GYW27798425` | Ground Saver, bought through PolyBag | valid |
| `1Z28X87G6713238443` | Worldwide Expedited, bought through PolyBag | valid |
| `1Z000X00YW00000002` | bought by hand in the Shopify admin | **rejected** |

The placeholder is the hand-bought one. Labels bought through the API carry well-formed
numbers with a real shipper prefix, so **a development store can exercise this rung end to
end** — the opposite of what the previous comment told `14` to expect, and better news for it.
The narrower true statement is the one worth keeping: a number UPS agrees to track is not
thereby a number that validates.

### 2026-09-09 — the international block is complete, and it was never guessable

Two more Shopify test purchases — Worldwide Express `1Z28X87G6604926058` and Worldwide Saver
`1Z28X87G0411692869` — returned `66` and `04`, the two rows held back an hour earlier for
resting on a hand-entered production tail with nothing corroborating. They are corroborated
now, by the same method that settled `67`: read off the production history first, then
returned independently by a controlled purchase whose service was known.

**All four international indicators are confirmed twice, by unrelated systems decades apart:**

| Service | Indicator | UPS API service code | Sources |
|---|---|---|---|
| Worldwide Saver | `04` | `65` | production history + Shopify label |
| Worldwide Express | `66` | `07` | production history + Shopify label |
| Worldwide Expedited | `67` | `08` | production history + Shopify label |
| Standard | `68` | `11` | Shopify labels only |

Not one of these could have been derived from UPS's documentation — every value differs from
the API service code for the same service, and they are not a transposition or an offset of
it. That is the strongest form the "fourth vocabulary" finding has taken: the two vocabularies
are genuinely unrelated wherever they disagree, so the domestic coincidence is a coincidence
and not a rule with exceptions.

**A test now pins the mistake rather than only the behaviour.** `65`, `07`, `08` and `11` — the
API codes for exactly these four services — must resolve to *nothing* as indicators. A table
built from UPS's published codes would resolve them to the right service name from the wrong
vocabulary, which is the failure this whole table exists to avoid, and it is now a red test
rather than a paragraph.

**`04` breaks the contiguity, which is useful.** `66`, `67` and `68` run consecutively and it
was tempting to read the international block as a range; `04` sits nowhere near them. The
fall-through cases are now `69` and `05` — the immediate neighbours on both sides of the
observed block — so extrapolating along the sequence would turn a test red.

`YN` is still unmapped, and `68` is still the only row resting on one source.

**The table stands at ten indicators.** No ruleset version bump: `2026-09-09` was already
today's stamp and no package has been inferred under the intermediate states.

### 2026-09-10 — code review: inference must not be able to fail a purchase

Two findings, both correct, both fixed.

**1. The purchase-time hook was not best-effort, and that is a money bug.** By the time the
ladder runs, Shopify has already bought the label and billed the merchant for it. Everything
after that point is PolyBag's own bookkeeping about a purchase that succeeded — but an
exception escaping the inference call would have lost the `ShipResponse` entirely, leaving the
package **unshipped against postage already paid for**, with a live label nobody is holding.

The ladder is not throw-free, which is easy to miss because its most obviously risky part is.
`LabelTextExtractor` documents "never throws" and delivers it, so rung 2 reading a corrupt PDF
is safe. What is not safe sits behind it: `ServiceRuleset::table()` reads files from disk and
decodes JSON with `JSON_THROW_ON_ERROR`, so a table missing from a deploy or truncated in
transit throws, and carrier resolution goes to the database. A bad deploy would have taken
every Shopify purchase down with it, one paid label at a time.

Inference now degrades to `unknown` on failure exactly as it does on concluding nothing, and
logs. A regression test throws from a stubbed `ServiceInferrer` and asserts the label still
comes back and the package still ships; removing the guard turns it red, which was checked
rather than assumed.

**2. A comment said the UPS 1Z rung did not exist**, written when that was true and left
behind by the rung landing hours later in the same working session. Corrected to describe
what actually happens: rung 2 cannot read a raster, rung 1 answers where the indicator is one
the ruleset has evidence for, and a consolidator handoff stops rung 1 by design.

Worth naming the pattern rather than just the line: this is the second stale-comment defect in
two days on this path, after `20` found `ShopifySource::exportPackage()` swallowing an error
message Shopify had stopped sending. Comments here describe a vendor's behaviour and our
coverage of it, and both move.

**Provenance confirmed for `68`.** The UPS Standard labels carrying it were all Shopify test
purchases; the production history holds no Standard examples at all. So `68` stays the single
source row and the table already said so — no change, but it is now confirmed rather than
assumed.

### 2026-09-10 — Shopify is a passthrough for USPS, which closes rung 2 for that carrier

The premise this issue carried from its first comment — "Shopify is plausibly the label
*producer* rather than a passthrough, so layout and tokens could differ from anything tested
here" — is settled. It is a passthrough.

A first attempt at this compared a USPS *international* label against a Shopify domestic one
and read the presence of Consolas in Shopify's font set as a structural difference. That was
wrong twice over: USPS international labels are rotated, carry a CP72 customs declaration and
run to three pages, so nothing about them is comparable; and Consolas turns out to be present
on USPS's own domestic label too. The correct test is one service, both sources — a Ground
Advantage label bought both ways for package 209:

| | USPS API | Shopify |
|---|---|---|
| Producer | `Apache FOP … PDF Transcoder for Batik` | `Ruby CombinePDF 1.0.31 Library` |
| Page | 288×432 pts | 288×432 pts |
| Fonts | `EAAAAA+ArialMT`, `EAAAAB+Arial-BoldMT`, `EAAAAC+Arial-ItalicMT`, `EAAAAD+Consolas` | **identical, same order** |
| Images | 254×50 + two 40×40 indexed | **identical** |
| Service token | `USPS GROUND ADVANTAGE™` | **identical** |

USPS generates with Apache FOP; Shopify re-wraps through a Ruby library without touching the
content stream. The font **subset tags** are what make this conclusive rather than suggestive:
those tags are assigned by the generating tool, so identical tags in identical order mean the
same source document. Every difference in the extracted fields is content — sender name,
address formatting, tracking number, order reference.

**The consequence is that USPS rung 2 should not be populated.** Not "later" — at all. The STC
table resolves USPS domestic from the tracking number, which costs nothing, needs no label,
and is the only rung that survives `PurgePiiCommand`; rung 2 never ran on the label above
because rung 1 had already answered. Rung 2's entire remaining value for USPS is the handful
of STCs that are ambiguous or name no product, and falling through is the correct outcome for
those. A token table for a carrier whose numbers already decode is work that buys nothing and
adds a second thing to keep current.

That is worth stating as a general rule rather than a USPS fact: **where rung 1 covers a
carrier, rung 2 is redundant for it, and the ladder's ordering already says so.** Rung 2 earns
its place on carriers rung 1 cannot reach — which, of the two we have evidence for, is neither.
UPS is the opposite case and equally closed: its labels are bitmaps, so rung 2 cannot read
them however much evidence we gather.

**First live confirmation of the purchase-time hook.** The same purchase came back with
`service = 'USPS Ground Advantage'`, `service_evidence = inferred`, `service_inference_method
= 'usps-impb-stc'`, `service_ruleset_version = '2026-09-09'` — a real Shopify label rather
than a test double, inferred inside the purchase and stamped.
