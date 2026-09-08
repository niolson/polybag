# Find out whether preferredRateSelection works, and catalogue the codes that do

Status: ready-for-human

Repo: `polybag`

Unblocked 2026-09-08. The terms of service gate is cleared and two labels have been
bought through the API — see `01`.

## Problem

`ShippingLabelPurchaseInput.preferredRateSelection { carrierCode, serviceCode }` is how
a specific carrier and service would be requested. Two things are unknown:

1. **Whether it is honoured at all.** A community report holds that Shopify ignores it
   outright. Plausible — the error enum contains `CARRIER_NOT_SUPPORTED` and
   `PACKAGE_CARRIER_MISMATCH`, which implies it is read, but that is inference, not
   evidence.
2. **What service codes are valid.** Shopify publishes no list and the schema cannot
   enumerate them. Carrier codes are known (`usps`, `ups_shipping`, `dhl_express`,
   `canada_post`); service codes are carrier-defined strings.

## Why it is not urgent

PolyBag does not depend on it. `auto` is the only seeded service and sends **no**
selection, letting Shopify choose the way its admin would. The adapter records what
Shopify actually did rather than what was asked for, so an ignored selection cannot
corrupt the data:

```php
service: $label->trackingCompany ?? $request->selectedRate->serviceName,
metadata: ['shopify_requested_service_code' => …, 'shopify_tracking_company' => …]
```

Both sides are kept precisely so a silent override is visible. A query for packages
where the two disagree answers question 1 from production data, without spending
anything on experiments.

## What to do

- Attempt one purchase with an explicit selection, e.g.
  `{carrierCode: "usps", serviceCode: "usps_ground_advantage"}`, and compare
  `trackingInfo.company` and the resulting service against what was asked for.
- A deliberately invalid service code is a **free** probe: it fails validation before
  anything is charged. Useful for telling "ignored" apart from "rejected" — an ignored
  selection buys a label anyway, a read one errors with `RATES_NOT_FOUND`.
- Each confirmed pair becomes a `CarrierService` row under the `Shopify` carrier, with
  service code `carrier:service` (e.g. `usps:usps_ground_advantage`). `ShopifyAdapter`
  splits on the colon; anything without one leaves the choice to Shopify. Add them under
  Carrier Services — no migration needed.
- If the selection turns out to be ignored, say so in the seeder comment and leave
  `auto` as the only catalogued service, so nobody re-litigates it later.

## Comments

### 2026-09-08 — unblocked, free, and now the gating experiment for the capture work

The terms of service gate is gone and `01` bought two labels through the API. On a
development store these are **test labels**: no postage is charged. So the "not urgent"
framing above was written against a cost that no longer applies here.

It moves to the front of the queue for a second reason. `auto` returned USPS on the first
purchase and UPS on the second from identical inputs, so `auto` is not a usable instrument
for gathering one label per carrier and service — which is exactly what `14` needs. Until
we know whether `preferredRateSelection` is honoured, the capture work has no way to ask
for a specific label.

Two things to fold in while running it:

- **The free probe first.** A deliberately invalid service code fails validation before
  anything is bought, and tells "ignored" apart from "read". Do that before spending a
  purchase on the real one.
- **The CeC question `01` reopened.** `auto` sold USPS through the API on a store whose
  admin refuses to sell USPS. An explicit `{carrierCode: "usps"}` purchase with an origin
  address matching the Shopify location exactly is the cheap test — it changes one variable
  where `01`'s comparison had two.
