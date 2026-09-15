# Direct UPS rates a declared UPS packaging

Status: needs-triage

Repo: `polybag`

## Parent

[ADR-0005](../../../adr/0005-packaging-form-and-carrier-identity.md), the UPS half of
decision 3 ("UPS from the `PackagingType` it sent"). Not scheduled by the ADR's
implementation order; optional, until a customer stocks UPS packaging.

## What to build

`UpsAdapter` hard-codes `PackagingType` / `Packaging` code `02` ("Customer Supplied
Package") on both the rate body (`buildRateApiRequest()`, ~line 527) and the ship body
(~line 613), so it never offers a UPS Letter, Pak, Tube or Express Box even to a
warehouse that has them. This is the UPS twin of `05`, and simpler: UPS puts packaging on
the *request*, so there is no discarded response to reinstate — the adapter sends what
the Box Size says and stamps what it sent.

### Mapping at the request boundary

One private method, `CarrierPackaging` → UPS packaging code, used by both bodies and
nowhere else — the same shape as `03`'s FedEx mapping:

| `CarrierPackaging` | Code | Description |
|---|---|---|
| `UpsLetter` | `01` | UPS Letter |
| `UpsTube` | `03` | Tube |
| `UpsPak` | `04` | PAK |
| `UpsExpressBox` | `21` | UPS Express Box |
| `UpsExpressBoxSmall` / `Medium` / `Large` | `2a` / `2b` / `2c` | Small / Medium / Large Express Box |
| null, or any non-UPS case | `02` | Customer Supplied Package |

Confirm the codes against the vendored `upsRating.json` / `upsShipping.json` enums before
relying on this table. UPS's 10 kg and 25 kg boxes (`25` / `24`) are additions to the enum
when someone stocks them, not part of this slice.

A non-UPS carrier packaging (a USPS flat-rate box) sends `02`: UPS rates it as customer
packaging and the shared filter drops every UPS rate anyway, because they say
`shipperPackaging()`. Same comment `03` asks for on the FedEx side.

### The rate says what was sent

Every rate parsed from a response to a request that sent a UPS code carries
`exactly($package->carrierPackaging)`; a request that sent `02` yields
`shipperPackaging()` rates. `resolvePreSelectedRate()` already passes the rule's rate
through the filter (`02`) and is otherwise untouched.

### Schemas

`upsRating.json` and `upsShipping.json` are vendored UPS specs; the packaging code enum in
them is the check that a mapped value is one UPS accepts. Rate and ship tests already run
through them.

### Sandbox

UPS's sandbox rates Letter and Pak; one rate-and-buy for a declared `UpsPak`, recorded in
a comment, is the proof the adapter's body is accepted rather than merely well-formed.

## Acceptance criteria

- [ ] `UpsAdapterTest`: the rate body and ship body carry `01` for `UpsLetter`, `04` for
      `UpsPak`, `2b` for a medium Express Box, and `02` for null and for
      `UspsMediumFlatRateBox`; each validates against the vendored schema
- [ ] Rates from a `UpsPak` request carry `exactly(UpsPak)`; from a plain request,
      `shipperPackaging()`
- [ ] `ShippingRateService` test: a Package in a `UpsPak` box size gets UPS rates and no
      USPS/FedEx/Amazon shipper-packaging ones
- [ ] One sandbox rate-and-buy for a declared UPS packaging, recorded here
- [ ] `vendor/bin/pint --dirty --format agent` clean

## Blocked by

- [`02`](02-pre-selection-filters-before-it-chooses.md)
- [`03`](03-box-size-carrier-packaging-replaces-fedex-package-type.md) — the column
- A customer who stocks UPS packaging; until then this is not scheduled
