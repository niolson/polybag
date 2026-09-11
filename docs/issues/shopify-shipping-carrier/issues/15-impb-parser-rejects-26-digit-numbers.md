# The IMpb parser rejects 26-digit tracking numbers, so rung 1 declines valid ones

Status: done — 2026-09-08

Repo: `polybag`

## Problem

`ImpbTrackingNumber::tryParse()` accepted a 22-digit IMpb and nothing else. USPS also
issues a **26-digit** one, and the first Shopify Shipping label PolyBag bought came back
with one — `92346902673388000000273428`, whose check digit is valid and whose service type
code `346` already resolved to `USPS Ground Advantage` in the ruleset, exactly what the
label said.

So rung 1 of `11`'s ladder had the answer in hand and declined it on a length check, and
`packages.service` was null on a package whose service was sitting in its tracking number.

**Why it survived until now.** Every USPS label PolyBag bought through its own carrier
account is 22 digits — 125 of them in a development database, against this one 26. The gap
is invisible until postage comes from somewhere else.

## What shipped

`tryParse()` now builds every reading of the digit string that is a valid barcode and
parses only when there is **exactly one**. That covers all the cases in one rule rather
than a list of lengths: 22 and 26 digits bare, a GS1 `420` prefix at either ZIP width, and
the 34-digit string that reads as `420`+ZIP5+26 *and* as `420`+ZIP9+22 — which is declined,
because a confident wrong answer in the service position is worse than no answer.

- [x] The 26-digit number above parses, its service type code reads `346`
- [x] A 26-digit number with one digit altered is declined
- [x] Both 34-digit readings covered: unambiguous parses, ambiguous declines
- [x] Existing 22-digit and 30-digit tests pass unchanged
- [ ] Re-run `app:infer-package-services` over the package from `01` — **not possible**:
      its label was voided between the report and the fix, and `applyVoid()` clears the
      tracking number. The number itself is asserted directly instead, which is the same
      evidence without the row

Fixture worth remembering: `92011999999999000000000011` is constructed so its own trailing
22 digits also carry a valid check digit, which is what makes a `420`-prefixed 34-digit
string genuinely readable two ways. It is not a real barcode.

## Related

- `11` — the inference ladder whose rung 1 this unblocks
- `01` — the purchase that turned it up
