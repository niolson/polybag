# Drop Amazon offers for packaging the Package is not in, and for content it does not carry

Status: done — shipped 2026-09-15 as `fitsThePackaging()` in the Amazon adapter

Repo: `polybag`

## Problem

Amazon's `getRates` does not know what the parcel is enclosed in. It is given dimensions and a
weight and it rates every service whose limits those fit, so a small **box** is offered
*Priority Mail Flat Rate Envelope*, *Priority Mail Medium Flat Rate Box* and *FedEx Express
Saver One Rate* — services that are only valid in the carrier's own branded packaging. It
also rates *Media Mail* and *Bound Printed Matter*, which are only valid for specific contents,
when the delivery promise allows them.

The 2026-09-11 live run (`03`, `10`) is the evidence. The Package was a 4×6×6 box at 1.15 lb.
Of the 35 distinct offers Amazon returned and the Ship page listed, thirteen were for packaging
the parcel was not in:

| Offered | Requires |
|---|---|
| `USPS_PTP_PRI_FRE`, `_LFRE`, `_PFRE` | a Priority Mail flat-rate envelope (plain, legal, padded) |
| `USPS_PTP_EXP_FRE`, `_LFRE`, `_PFRE` | a Priority Mail Express flat-rate envelope |
| `USPS_PTP_PRI_MFRB`, `_LFRB` | a Priority Mail medium or large flat-rate box |
| `FEDEX_PTP_EXPRESS_SAVER_ONE_RATE`, `_SECOND_DAY_ONE_RATE`, `_SECOND_DAY_AM_ONE_RATE`, `_STANDARD_OVERNIGHT_ONE_RATE`, `_PRIORITY_OVERNIGHT_ONE_RATE` | FedEx-branded packaging |

Buying one of these for a parcel in the packer's own box is a label the carrier will surcharge,
return, or refuse at acceptance. The direct USPS adapter already prevents exactly this — it
drops `CARDS`/`LETTERS`/`FLATS` processing categories and `MEDIA_MAIL`/`LIBRARY_MAIL` in
`UspsAdapter::isValidRate()`, and selects flat-rate and cubic rate indicators from the box size's
`BoxSizeType` in `isValidRateIndicator()`. The direct FedEx adapter only requests One Rate when
the box size's `fedex_package_type` is FedEx packaging (`isOneRateEligible()`). The Amazon
adapter has no equivalent, so the Ship page shows through Amazon what it would never show
direct.

Media Mail (`USPS_PTP_MM`, `UPS_PTP_SUREPOST_MEDIA`) and Bound Printed Matter (`USPS_PTP_BPM`,
`UPS_PTP_SUREPOST_BPM`) were in every capture's ineligible list and eligible in none — the
delivery promise has excluded them so far — but nothing stops a slower promise letting one
through, and nothing in the app records whether a Package's contents qualify. Misdeclared
Media Mail is a postal offence, so those are dropped too until the app can say a Package
qualifies.

## Scope

This is the **narrow fix**: a serviceId-pattern classifier inside the Amazon adapter, in the
same place and the same shape as the two filters `isBuyable()` already applies. It is
deliberately a third hard-coded list beside the two in `UspsAdapter`. The general mechanism
— a box size with a physical form and, independently, a carrier-supplied identity, and every
rate saying which packaging it requires — is
[ADR-0005](../../../adr/0005-packaging-form-and-carrier-identity.md), and when that lands
this list becomes the classifier that stamps a `PackagingRequirement` on each Amazon rate rather
than a filter of its own. The Media Mail / Bound Printed Matter drop (rule 3) outlives that
ADR: content classes are deferred to a later one.

Do **not** widen this into that ADR. No schema, no new enum on `BoxSize`, no change to
`RateResponse`.

## What to build

In `AmazonBuyShippingAdapter`, add a third predicate to `isBuyable()`:

```php
return $this->hasPrintableDocument($rate)
    && $this->honoursRequiredServices($rate, $request)
    && $this->answersRequiredGroupsForFree($rate, $request)
    && $this->fitsThePackaging($rate, $request);
```

`fitsThePackaging()` reads the Amazon `serviceId` and the request's first `PackageData` (its
`boxType` and `fedexPackageType`), and returns false when:

1. **The service requires USPS-supplied packaging.** The serviceId contains one of the tokens
   `_FRE`, `_LFRE`, `_PFRE`, `_SFRB`, `_MFRB`, `_LFRB` as a path segment (match `_(L|P)?FRE`
   and `_(S|M|L)FRB` followed by `_` or end-of-string, so the `_INTL` and `_CUSTOMS` variants
   are caught and `USPS_PTP_FC` is not). Always dropped: the app has no way to say a box size
   *is* USPS packaging today. That is the first thing ADR-0005 adds, and this rule is what it
   replaces.
2. **The service is FedEx One Rate** (serviceId contains `_ONE_RATE`) **and the package is not
   in FedEx packaging** — `fedexPackageType` is null or `FedexPackageType::YOUR_PACKAGING`.
   This mirrors `FedexAdapter::isOneRateEligible()` exactly; keep One Rate when the box size
   says FedEx packaging.
3. **The service is content-restricted.** serviceId is exactly `USPS_PTP_MM`, `USPS_PTP_BPM`,
   `UPS_PTP_SUREPOST_MEDIA` or `UPS_PTP_SUREPOST_BPM`. Always dropped.

A rate that matches none of these is kept. Unknown serviceIds are kept — this is a denylist,
because a discovered catalog cannot be allowlisted (ADR-0003).

The filter runs **after** `record()` has written observations for every rate, eligible and
ineligible, so the observed-service catalog still learns these services exist. That ordering
is already documented on `ratesFrom()` and must not change: the aliasing page (`05`) is where
an operator would one day map `USPS_PTP_PRI_FRE` to a flat-rate-envelope service, and it needs
to have seen it.

Do not touch `UspsAdapter`, `FedexAdapter`, or `ShippingRateService`.

## Tests

`tests/Feature/AmazonBuyShippingTest.php`, next to *drops an offer it could not print*. Use the
existing `amazonEligibleRates()` / `amazonRatesResponse()` helpers; the fixture values there
are synthetic and must stay so.

- **drops a flat-rate envelope offer for a parcel in a box** — add `USPS_PTP_PRI_FRE` and
  `USPS_PTP_EXP_PFRE_INTL` rates beside the OnTrac and UPS ones; expect only OnTrac and UPS
  back. `amazonBuyShippingPackage()` creates the Package without a box size, so attach a
  `BoxSize::factory()` of type `BOX` for the packaging tests (or add a parameter to the helper).
- **drops a flat-rate box offer even when the parcel is a box** — `USPS_PTP_PRI_MFRB` against a
  `BOX` package is dropped; a box is not *that* box.
- **drops FedEx One Rate for the packer's own box and keeps it for FedEx packaging** — one
  `FEDEX_PTP_EXPRESS_SAVER_ONE_RATE` rate; assert dropped with `fedex_package_type =
  YOUR_PACKAGING` and kept with `FEDEX_PAK`.
- **drops Media Mail and Bound Printed Matter offers** — all four content-restricted IDs;
  none survive.
- **keeps Ground Advantage, Priority Mail and Priority Mail Cubic** — `USPS_PTP_GAH`,
  `USPS_PTP_PRI`, `USPS_PTP_PRI_CUBIC`, `USPS_PTP_FC` survive, so the `_FRE` pattern is proven
  not to over-match.
- **still records an observation for a dropped offer** — after the flat-rate test, assert an
  `ObservedService` row exists for `USPS_PTP_PRI_FRE`. This is the ordering guarantee.
- **a manual-ship package with no box size** drops the carrier-packaging services (rule 1 and
  rule 2 both fire on null).

Run `php artisan test --compact tests/Feature/AmazonBuyShippingTest.php`, then
`vendor/bin/pint --dirty --format agent`.

## Acceptance criteria

- [x] `isBuyable()` gains `fitsThePackaging()` with the three rules above and a docblock that
      names ADR-0005 as what replaces it
- [x] The seven tests above pass; the existing 36 in the file still pass
- [x] Observations are still recorded for dropped offers
- [x] No change outside `AmazonBuyShippingAdapter` and its test file

## Blocked by

None.

## Comments

### 2026-09-15 — done

`AmazonBuyShippingAdapter::isBuyable()` gained `fitsThePackaging()` as its fourth
predicate, after the observations are recorded. Rule 1 is
`preg_match('/_(?:[LP]?FRE|[SML]FRB)(?:_|$)/')` on the serviceId; rule 2 reads
`fedexPackageType` off the request's first `PackageData` with the same null-or-`YOUR_PACKAGING`
test as `FedexAdapter::isOneRateEligible()`; rule 3 is a `CONTENT_RESTRICTED_SERVICES`
constant of the four exact ids. The docblock names ADR-0005 as what replaces rules 1 and 2.

Seven tests added beside *drops an offer it could not print*; 43 pass in the file. One thing
the tests had to allow for: `PackageFactory` assigns `box_size_id` at random, so the
no-box-size case sets it null explicitly rather than trusting the helper's default.
