# A mapping onto another carrier's service rewrites the carrier of record

Status: done — 2026-10-01

Repo: `polybag`

Severity: medium-low. It takes an Admin's mistake, but nothing on the page prevents it or
flags it. The result is a Label that names the wrong physical carrier, dated by the wrong
carrier's cutoff, and bought unattended under the wrong service.
Verified: confirmed. The existing test `names a rate by the carrier service somebody
mapped it to` in `tests/Feature/AmazonBuyShippingTest.php` maps Amazon's
`ONTRAC / ONTRAC_MFN_GROUND` onto USPS Ground Advantage and asserts that the rate's carrier
becomes `USPS`.

## Problem

ADR-0006 decision 2 defines a mapping as naming the **same** service: "each real service
exists once, and one table maps source codes to it". ADR-0002 decision 1 says the carrier
of record "always names the physical carrier".

`ObservedServiceMapper::map()` accepts any `CarrierService`.
`UnmappedObservedServices::carrierServiceOptions()` offers every carrier's services in one
list. It doesn't filter by the carrier the observation came from and doesn't warn when the
two carriers differ. Once an OnTrac identity is mapped onto a USPS service:

- `AmazonBuyShippingAdapter::ratesFrom()` names the offer's carrier and `carrier_id` from
  the mapped service (`$mapped->carrier->name`, `$mapped->carrier_id`). Amazon's own
  `carrierName` is dropped.
- `shipResponse()` writes `$offer->carrier` as the Label's carrier, and `markShipped()`
  normalizes that to USPS. The parcel is OnTrac's, but PolyBag records it as USPS.
- `ShipRequest` dates the purchase by the offer's `carrier_id`: USPS's cutoff and pickup
  days, not OnTrac's (ADR-0006 decision 9).
- End of Day counts it under USPS, and service inference and reports read USPS.
- A method listing USPS Ground Advantage whose `amazon` row allows only *services on this
  method* now buys OnTrac unattended. The mapping authorizes by design (ADR-0006
  trade-off), but here it authorizes a carrier the method never listed.

Tracking still works, because it goes through Amazon by the stored Amazon carrier ID. So the
mismatch never surfaces operationally, which is why nobody would notice it.

## What to build

- Take the carrier of record for an Amazon offer from Amazon's `carrierName`, normalized
  through `CarrierNormalizer`, whether the service is mapped or not. The mapping names the
  **service**; it should never override who carries the parcel.
- In `ObservedServiceMapper::map()`, refuse (or require explicit confirmation for) a
  `CarrierService` whose carrier differs from the one the observation's carrier name
  normalizes to. When the carrier name normalizes to nothing, allow the mapping, but show
  the carrier on the page.
- In `carrierServiceOptions()`, list the probable carrier's services first. The promote
  form already guesses the carrier with `matchingCarrierId()`. That guess matches carrier
  names only; it should go through `CarrierNormalizer` so aliases count too.
- Change the existing test to map onto a service of the same carrier. As written, it
  asserts the behaviour this issue removes.

## Comments

- 2026-10-01 — Fixed as proposed, with refusal rather than confirmation.
  `AmazonBuyShippingAdapter::ratesFrom()` takes the offer's carrier and `carrier_id` from
  Amazon's `carrierName` through `CarrierNormalizer`, mapped or not. Amazon's own name
  stands when nothing resolves. The mapping supplies only the service: code, name and
  `carrier_service_id`. `mappedService()` also ignores a mapping whose service belongs to
  another carrier than the one Amazon's name resolves to, so a mapping written before
  this change neither renames the offer nor authorizes automation. When the name resolves
  to nothing, the mapping still applies, but the carrier of record stays Amazon's name
  with no `carrier_id`.
  `ObservedServiceMapper::map()` and `promote()` throw the new
  `CrossCarrierMappingException` when the observation's carrier name (else its carrier
  id) resolves to a different carrier. Aliases count. `carrierFor()` exposes that
  resolution. On the page, *Assign* lists only that carrier's services once the carrier
  is known, and every service otherwise. Its helper text names the carrier the source
  reported either way. *Author service* defaults its carrier through the same
  resolution, and both actions turn a refusal into a danger notification.
  Tests: the existing mapped-name test now maps OnTrac onto an OnTrac service. New
  `AmazonBuyShippingTest` cases cover a cross-carrier mapping that is ignored, and the
  unresolvable-carrier case. `UnmappedObservedServicesTest` covers the filtered options,
  an *Assign* onto another carrier's service rejected by the form, a refused *Author
  service*, and an alias-resolved refusal in the mapper.
