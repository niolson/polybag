# Filter Amazon offers to the ShippingMethod's service class

Status: ready-for-agent

Repo: `polybag`

## Problem

A ShippingMethod is a service class: "Ground" lists `USPS_GROUND_ADVANTAGE`, `UPS_GROUND`
and so on, and each direct adapter is asked only for those codes. Amazon is exempt. A
method reaches Amazon by listing the seeded `AMAZON_BUY_SHIPPING` row, the adapter ignores
`$serviceCodes` because `getRates` takes no service filter, and nothing after the quote
checks an Amazon offer against the method. Every offer Amazon returns is accepted under
every method that lists the row.

`RateSelector::selectForAutomation()` then filters only by approval, and picks the
cheapest rate, or the cheapest on-time one when there is a deadline. So:

- A "Ground" method can offer, and on the Ship page sell, Amazon's Next Day Air.
- An "Overnight" batch can buy an approved Amazon Ground service if it is cheaper and no
  deadline stops it.
- The `AMAZON_BUY_SHIPPING` row says it reaches PO Boxes and military addresses, so an
  offer mapped to a service that cannot, such as `UPS_GROUND`, is never checked against
  the destination.

This was found by reading the code and needs a failing test before anything else. It
matters most for off-Amazon orders
(`amazon-shipping-external-orders/07`): there is no Amazon delivery promise, often no
`deliver_by`, and the method's service class is the only thing that stops batch ship from
buying the wrong speed.

## Decision

**An Amazon offer is dropped when it is mapped to a `CarrierService` that is not among
the ShippingMethod's active services for this destination.**

| Offer | Result |
|---|---|
| Mapped, and in the method | kept |
| Mapped, not in the method | dropped, on the Ship page and in automation |
| Unmapped | kept, for a person to choose; ADR-0003 decision 2 keeps it human-selectable, and it cannot reach automation because a service must be mapped before it can be approved |
| Shipment has no ShippingMethod | no filter, as with today's fallback |

**A method that lists only the `AMAZON_BUY_SHIPPING` row is unconstrained.** The filter
applies once the method lists at least one other service. The Amazon row decides *whether*
Amazon is asked; the method's other services decide *which* Amazon offers count. This
keeps existing installs working with no migration.

The rejected alternative was to filter strictly and migrate every Amazon-only method by
adding each service already mapped. That rewrites configuration people authored, and a
service mapped later would silently drop out of the method until someone added it.

A known limitation, accepted: listing `USPS_GROUND_ADVANTAGE` so that Amazon's USPS
offers pass also asks a configured direct USPS account for a rate. There is no way to say
"Amazon's USPS but not ours" without a payload on `carrier_service_shipping_method`,
which ADR-0003 option C already ruled out.

## What to build

- `RateResponse` gains `?int $carrierServiceId`. `AmazonBuyShippingAdapter` sets it from
  the mapping it already loads (`$mapped?->id`). Direct adapters leave it null, because
  they already filter by `$serviceCodes`. Do not match on the `serviceCode` and carrier
  name strings.
- `App\Services\Shipping\ServiceClassFilter::keepInClass(Collection $rates, ?Collection
  $allowedServiceIds)`, next to `PackagingFilter`. When given null, it returns the rates
  unchanged.
- In `ShippingRateService::getShippingRates()`, apply it alongside
  `PackagingFilter::keepCompatible()`, before Offers are issued and the quote is logged
  (ADR-0005 decision 4: a dropped rate was never offered). `buildCarrierTasks()` already
  loads `getActiveCarrierServices()`, so pass those IDs through instead of querying
  again. Because that set is already restricted for PO Box and military destinations, the
  destination check comes for free.
- Add a line to ADR-0003's consequences recording that Amazon offers are filtered to the
  method's service class after the quote.

## Acceptance criteria

- [ ] A "Ground" method given Amazon offers mapped to Ground Advantage and to Next Day
      Air returns only the Ground Advantage offer
- [ ] An unmapped Amazon offer is still returned for the Ship page, and `selectBest`
      still refuses it
- [ ] A method that lists only `AMAZON_BUY_SHIPPING` returns every Amazon offer
- [ ] For a PO Box destination, an offer mapped to `UPS_GROUND` is dropped
- [ ] A dropped offer creates no `ShippingOffer` row and no quote-log row
- [ ] An "Overnight" batch never buys an approved Ground service, even when it is cheapest
- [ ] Direct-carrier rating is unchanged (existing suite)

## Blocked by

None.
