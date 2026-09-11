# The purchase body the adapter sends has never been valid

Status: done — fixed 2026-09-11 and confirmed against the oracle; the shape of the joined document Amazon returns is still unobserved and `labelFrom()` says so

Repo: `polybag`

## Problem

`03` shipped `AmazonBuyShippingService::buildPurchasePayload()` against captured production
`getRates` shapes, with the honest caveat that nothing had run live. On 2026-09-11 the first
live `purchaseShipment` was attempted (`09`), and Amazon refused the adapter's body twice
before ever looking at the order. Both refusals are in the two fields `03` and the schema
README named as "what the schema cannot catch": the document specification against the rate,
and the required value-added service groups. Both are wrong for **every carrier, in both label
formats**, so no label could ever have been bought.

`tests/Feature/AmazonBuyShippingTest.php` has 22 passing cases because its fixtures were
simplified to `supportedDocumentDetails: [LABEL]` and a Confirmation group that offers
`NO_CONFIRMATION` — shapes Amazon has returned for no USPS rate and, for the document details,
no rate at all. Same defect class as `shopify-shipping-carrier/22`: a mocked vendor validates
nothing.

## What Amazon actually requires

Established with a **validation oracle**: `purchaseShipment` checks the document specification,
then the value-added services, and only then looks the order up in its open-order pool
("Rigel"). An order that has already shipped fails at that last step, so every body sent at
one either fails on a field with Amazon's own message or "passes validation (refused at the
order)" — and nothing can be bought. Ten bodies were sent at a shipped Guam order; the log is
`.scratch/amazon-shipping-v2/probe-09-oracle-*.json` and the script is
`probe-09-buy-and-void.php` with `$oracle = true`.

| Body | Result |
|---|---|
| PDF 4×6, `LABEL` only, `needFileJoining: false` — **the adapter today** | rejected: invalid documentation specification |
| PDF 4×6, `LABEL` only, `needFileJoining: true` | rejected |
| PDF 4×6, `PACKSLIP`+`LABEL`, `needFileJoining: false` | rejected |
| PDF 4×6, `PACKSLIP`+`LABEL`, `needFileJoining: true` | **passes** |
| ZPL 4×6 @300, `LABEL` only, `needFileJoining: false` — **the adapter today** | rejected |
| ZPL 4×6 @300, `LABEL` only, `needFileJoining: true` | **passes** |
| ZPL 4×6 @203 (rate offers 300 only) | rejected — the adapter already handles this one |
| valid docs, no `requestedValueAddedServices` — **the adapter today, for USPS** | rejected: invalid value added services |
| valid docs, `NO_CONFIRMATION` (not offered by USPS) | rejected |
| valid docs, `DELIVERY_CONFIRMATION` ($0) or `SIGNATURE_CONFIRMATION` ($4.15) | **passes** |

So, per `supportedDocumentSpecifications[n].printOptions[0]`:

1. **`needFileJoining` must be one of `supportedFileJoiningOptions`.** Every spec seen — three
   carriers in `01`'s production capture, USPS across four territories in `09` — publishes
   `[true]` only. The adapter hard-codes `false` and never reads the field.
2. **`requestedDocumentTypes` must include every `supportedDocumentDetails[].name` with
   `isMandatory: true`.** PDF 4×6 is `PACKSLIP`+`LABEL` on every rate seen; PDF 8.5×11 is
   `PACKSLIP`+`LABEL` (OnTrac, USPS) or `RECEIPT`+`LABEL` (UPS); PNG and ZPL are `LABEL`
   alone. The adapter sends `['LABEL']` and never reads the field.
3. **A required `availableValueAddedServiceGroups` entry must be answered with an option that
   group offers.** UPS's Confirmation group offers `NO_CONFIRMATION`; USPS's offers
   `DELIVERY_CONFIRMATION` at $0 and `SIGNATURE_CONFIRMATION` at $4.15 and nothing called
   `NO_CONFIRMATION`. The adapter answers only with `NO_CONFIRMATION` when it is offered, so
   for USPS it leaves the required group unanswered.

## What to build

- `documentSpecification()` reads `supportedFileJoiningOptions` and
  `supportedDocumentDetails` off the chosen spec's print option: `needFileJoining` is `false`
  when `false` is offered, else the first option offered; `requestedDocumentTypes` is every
  mandatory name, with `LABEL` always among them.
- `valueAddedServicesFor()` answers a required group, when nothing requested matches, with
  the **cheapest option the group offers** — `NO_CONFIRMATION` for UPS, `DELIVERY_CONFIRMATION`
  for USPS — rather than a hard-coded ID. A required group with no free option is a
  surcharge the packer did not ask for: drop the rate at quote time, the way a rate with no
  printable format is dropped, and name it.
- **Fixtures from the real captures**, not the simplified ones: a USPS rate whose
  Confirmation group has no `NO_CONFIRMATION`, and document specs carrying `PACKSLIP` as
  mandatory with `supportedFileJoiningOptions: [true]`. The two existing purchase tests
  (`buys the offer that was chosen…`, `buys the confirmation a shipment requires…`) should
  fail before the fix and pass after.

## What is still unobserved

A valid PDF 4×6 purchase returns a **joined** document containing a pack slip and a label. No
purchase has succeeded, so nothing is known about it: one `packageDocuments` entry or two, page
order, whether the pack slip page is also 4×6, whether ZPL (`LABEL` only, joined) is one
`^XA` block or several. `labelFrom()` takes the first `LABEL`-typed document and treats its
bytes as the label; if the pack slip is a page inside that document, the label printer gets
a pack slip. The fix should make that assumption explicit at the point it reads the document
— and the first successful purchase (`09`, or any unshipped order) has to look at the bytes
before the adapter is trusted to print them. Choosing ZPL avoids the pack slip entirely, and
for PDF workstations the 8.5×11 spec does not avoid it either.

Amazon refused both a shipped Guam order (2025-07) and a shipped Northern Marianas order
(2025-11) as "doesn't exist in Rigel", so the purchase path cannot be exercised on history:
it needs an order that has not shipped yet.

## Acceptance criteria

- [x] `buildPurchasePayload()` produces a body the oracle passes — confirmed live for USPS
      (Ground Advantage and Priority Mail Express) and UPS Next Day Air Saver, in PDF 4×6,
      ZPL at 300, and ZPL asked for at 203 where only 300 is offered. OnTrac — no VAS group
      at all, so no `requestedValueAddedServices` key — was not on either quote and is
      covered by fixture only
- [x] Fixtures carry `PACKSLIP` mandatory and a `NO_CONFIRMATION`-less required group, and
      the two purchase tests that assert the body fail against the old code
- [x] A required group with no free option drops the rate at quote time — silently, like the
      no-printable-format drop beside it; the offers beside it are unaffected
- [x] `labelFrom()` documents the assumption it makes about a joined document
- [ ] The first real purchase checks that assumption — waits on an unshipped order (`09`)

## Related

- [`03`](03-amazon-buy-shipping-adapter.md) — the adapter, whose live-run criterion this is
  part of
- [`09`](09-international-purchase-and-customs.md) — where the attempt was made, and why it
  stopped at Rigel
- `tests/Fixtures/Schemas/README.md` — records these two fields as what the schema cannot
  validate, which is exactly where both defects were
- `shopify-shipping-carrier/22` — the same class of defect on the Shopify side

## Comments

### 2026-09-11 — opened

Found by trying to buy a $10 label to Guam and being refused twice before the order was
even checked. The oracle table is the second and third attempts turned into an experiment;
the fourth attempt, on the newest order in the account, was the one Rigel refused.

### 2026-09-11 — fixed

`AmazonBuyShippingService::documentSpecification()` now reads `supportedFileJoiningOptions`
and `supportedDocumentDetails` off the chosen print option: `needFileJoining` is `false`
only where the rate offers it, and `requestedDocumentTypes` is every mandatory document plus
`LABEL`. `valueAddedServicesFor()` answers a required group with its cheapest option through
a new public `cheapestOption()`, which `AmazonBuyShippingAdapter::answersRequiredGroupsForFree()`
also uses to drop a rate at quote time when that option would cost money the shipment did
not ask for. `labelFrom()` carries the joined-document assumption as a comment and takes only a
document Amazon typed `LABEL` — a lone `PACKSLIP` or `CUSTOM_FORM` fails the purchase loudly,
with the shipment ID, rather than being recorded and printed as the label (review caught a
fallback that would have done the latter).

Fixtures in `tests/Feature/AmazonBuyShippingTest.php` are the captured shapes now, plus a
USPS rate whose Confirmation group has no refusal; 30 tests, four new. The oracle was then
run again with the adapter's own output as the first three variants — PDF @203, ZPL @203
against a 300-only rate, ZPL @300 — and all three reached the order check, while every
pre-fix body was still refused with the same messages as before.

Still unobserved: what Amazon returns for a joined `PACKSLIP`+`LABEL` PDF. That is the last
box above, and it belongs to whoever makes the first real purchase.

### 2026-09-11 — confirmed on a continental order too, and one refinement

A shipped continental order quoted two offers, USPS Priority Mail Express and UPS Next Day
Air Saver (old order, promise expired, so express only). The adapter's PDF and ZPL bodies
passed validation for both; every pre-fix body was refused with the same messages. UPS's
4×6 PDF wants `PACKSLIP`+`LABEL` like everyone else's — the `RECEIPT` is on its letter size,
which the adapter never picks.

Priority Mail Express lists `SIGNATURE_CONFIRMATION` at **$0**, first, so "cheapest" chose a
signature nobody asked for. Free is not harmless — it is a parcel that comes back when nobody
is home — so `cheapestOption()` now breaks a price tie toward the least demanding option:
`NO_CONFIRMATION`, then `DELIVERY_CONFIRMATION`, then anything else. 31 tests.

`getAdditionalInputs` on a rate with `requiresAdditionalInputs: false` answers **200** with
an empty schema — `{"title": "Additional Inputs", "properties": {}, "type": "object"}` —
rather than an error. The request class `09` will need can be called unconditionally;
"required" means the schema has properties.
