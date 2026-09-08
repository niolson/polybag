# The IMpb parser rejects 26-digit tracking numbers, so rung 1 declines valid ones

Status: done — 2026-09-08

Repo: `polybag`

## Problem

`ImpbTrackingNumber::tryParse()` accepts a 22-digit IMpb and nothing else. USPS also
issues a **26-digit** one, and the first Shopify Shipping label PolyBag bought came back
with one:

```
92346902673388000000273428    26 digits
92 | 346 | …                  application identifier | service type code
```

That number's check digit is valid — the same mod-10 the parser already implements
agrees with it — and `ServiceRuleset::uspsProductForServiceTypeCode('346')` already
returns `USPS Ground Advantage`, which is what the label says. Rung 1 of `11`'s ladder
had the answer in hand and declined it on a length check:

```
service: null
reason: "tracking number: not a valid IMpb; label: no label tokens for carrier USPS"
```

So `packages.service` is null on a package whose service was sitting in its tracking
number, and the second rung was asked a question it cannot answer — `label-tokens.json`
holds no USPS tokens, deliberately, because `14` records USPS as rung-1-complete.

**Why it survived until now.** Every USPS label PolyBag has bought through its own
carrier account is 22 digits — 125 of them in a development database, against this one
26. The gap is invisible until postage comes from somewhere else, which is exactly what
`postage-source-split` made possible.

## What to change

The service type code sits at the same offset in both forms — two digits of application
identifier, then three of service type code — so this is a length check and a strip, not
a second parser.

1. Accept 26 digits alongside 22.
2. The GS1 `420` strip needs care. Today it handles one case: `420` + 5-digit ZIP + 22
   digits = 30. With 26-digit numbers in scope, **34 digits is ambiguous** — it reads as
   `420` + ZIP5 + 26, and equally as `420` + ZIP9 + 22. Validate both candidates and
   accept only if exactly one passes the check digit; if both do, or neither, decline.
   Declining is the correct outcome and the whole point of the class docblock: a
   confident wrong answer in the service position is worse than no answer.
3. Leave every other length declining. 20-digit legacy Delivery Confirmation numbers are
   not IMpb and must not be coerced into one; add a length only when a real number
   demands it.
4. The class docblock describes the 22-digit form as the format. Correct it.

## Acceptance criteria

- [x] The 26-digit number above parses, and its service type code reads `346`
- [x] A 26-digit number with one digit altered is declined
- [x] Both 34-digit readings are covered: one that resolves unambiguously is parsed, one
      where both readings validate is declined
- [x] The existing 22-digit and 30-digit tests still pass unchanged
- [ ] `app:infer-package-services` re-run, and the package from `01` now resolves to
      `USPS Ground Advantage` by tracking number rather than staying null —
      **not possible**, see below

## Comments

### 2026-09-08 — done

`tryParse()` now builds every reading of the digit string that is a valid barcode and
parses only when there is exactly one. That covers all three cases in one rule rather
than three length checks: 22 and 26 digits bare, the `420` prefix at either ZIP width,
and the 34-digit string that reads both ways and is therefore declined.

Verified against the real number from `01`: `92346902673388000000273428` parses, its
service type code reads `346`, and the ruleset names it `USPS Ground Advantage` — which
is what the label says.

**The last acceptance criterion cannot be met and is not being left open.** The package
it names had its label voided in Shopify between the report and the fix, and
`ShopifyFulfillmentSynchronizer::applyVoid()` clears the tracking number along with the
rest of the shipping data. There is no tracking number left on that package to infer
from. The number itself is checked directly instead, which is the same evidence without
the row.

Ambiguity fixture worth keeping in mind: `92011999999999000000000011` is constructed so
its own trailing 22 digits also carry a valid check digit, which is what makes a
`420`-prefixed 34-digit string genuinely readable two ways. It is not a real barcode.
