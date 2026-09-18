# Recover an unresolved direct-carrier purchase from the carrier

Status: done

Repo: `polybag`

## Parent

Split from [`14`](14-quote-direct-carrier-rates-behind-an-opaque-identifier.md) on review,
2026-09-18. ADR-0002 decision 4's fifth property — idempotent recovery — for the carriers
quoted from a `CarrierAccount`.

## Problem

A label purchase goes out; the carrier creates and charges the label; the reply never
arrives — a dropped connection, a proxy giving up, our own client timeout. From this side
that is indistinguishable from "the carrier never received it": one observation, two
truths.

`14` gave direct rates an offer and an atomic claim, and then made a deliberate carve-out
for a direct-carrier offer left consumed with no reply: on the next attempt,
`recoverPurchase()` settles it as failed when the seller does not implement
`RecoversUnresolvedPurchase`, so the package stays buyable. (A plain timeout never even
reaches that state — each direct adapter catches it inside `createShipment()` and answers
with a failed `ShipResponse`, which is settled as a decline; the carve-out is for a worker
killed between the claim and the reply.) The reasoning was that none of USPS, FedEx or UPS
could be asked what happened, so the alternative — the strict "spent, nothing confirmed"
block — would be terminal for the package with no way out. That premise was checked on
review and is wrong for two of the three:

| Carrier | Bills an orphaned label? | Can be asked "did it go through?" | We send what it would need? |
|---|---|---|---|
| USPS | **Yes** — postage leaves the EPS account at label creation; refund on request | **Yes** — reprint by `X-Idempotency-Key` returns the original label and metadata | No |
| UPS | **Yes** unless the account is on scan-based billing; void within 90 days reverses it | **Yes** — Label Recovery by `ReferenceNumber` + `ShipperNumber` returns the label and tracking number | Not usably — both reference slots carry the client's references |
| FedEx | No — billed on tender | No lookup on the Ship API; Track-by-reference would find it, metered | — |

So today a USPS or UPS timeout followed by a retry can buy a second label the tenant pays
for, and the carrier already had the answer.

## What the carriers offer

**USPS Domestic Labels 3.0 / International Labels 3.x.** `X-Idempotency-Key` on the label
request: "a client-created, randomly generated UUID that uniquely identifies a label
request… unique across all label requests for each customer (CRID)". The label endpoint
itself is *not* idempotent — "resubmission of a previously used UUID will generate a new
label" — so the key is a handle, not a guard. The handle is what matters:

- `POST …/label-reprint` with the header "returns the original label image and
  metadata". Only until the mailing date; at most three times; not for a cancelled label.
- `DELETE …/label` with the header "cancels an unused label or initiates a refund".

The reprint *is* the recovery: a 200 carries the label image and the tracking number, so
the package ships on the label already paid for. The spec documents 400/401/403/429/503
and no 404 for the reprint, so an unknown key is presumably a 400 with an error body —
the first probe below.

**UPS Shipping.** Label Recovery (`Shipping.yaml`, `operationId: LabelRecovery`) takes a
`TrackingNumber` *or* `ReferenceValues { ReferenceNumber { Code, Value }, ShipperNumber }`
and returns the label and the tracking number. Our ship request already sends up to two
`ReferenceNumber` values (code `TN`), but `UpsAdapter::buildReferenceNumbers()` fills them
from the client's label references, which print on the label. A recovery key needs a
slot, and the reference goes on the label with it. Whether Label Recovery answers on the
CIE sandbox, and whether it finds a label that was never tendered, is the second probe.

**FedEx.** Nothing on the Ship API retrieves a shipment by reference. Track-by-reference
would, but it is a metered call answering a question with no money behind it. FedEx keeps
the `14` carve-out, deliberately; only its wording changes.

## What to build

Probes first, code after — the probes decide the shape of the UPS half.

**Probe 1 — USPS sandbox.** Buy a label with a fresh `X-Idempotency-Key`; call reprint
with the same key (expect 200, same tracking number); call reprint with a key never used
(record the status and body — this is what "nothing was bought" looks like); call cancel
by key on the bought label. Capture to `.scratch/usps-idempotency/`.

**Probe 2 — UPS CIE.** Ship with a third-style `ReferenceNumber` carrying a UUID; call
Label Recovery by that reference and the shipper number (expect the label and tracking
number); call it with a reference never used (record the error); void the label by
tracking number. Note whether CIE answers Label Recovery at all — some CIE operations
return canned data.

### Probe results — 2026-09-18

Scripts and captures: `.scratch/usps-idempotency/` (`probe-18-reprint-by-key.php`,
`probe-18-retry-handles.php`, one JSON per case plus the bought PDFs) and
`.scratch/ups-label-recovery/` (`probe-18-label-recovery.php`, one JSON per run). Both
drive the real adapters and splice the key in through Saloon global middleware, so the
label bodies are exactly production's.

**Probe 1 — USPS TEM.** Bought a domestic Ground Advantage label (package 209,
`9200190380793700250012`) and a Priority Mail International label (package 215,
`CA005067273US`), each with a fresh UUID sent as `X-Idempotency-Key`. Both label calls
succeeded (200 / 201) with the header present on the wire.

| Call | Domestic | International |
|---|---|---|
| `POST …/label-reprint`, same key | 400 `160412` | 400 `160412` |
| `POST …/label-reprint`, key never used | 400 `160412` | 400 `160412` |
| `POST …/label-reprint/{tracking}`, the label just bought | 400 `160378` | 400 `160378` |
| `DELETE …/label` by key (no tracking number) | **405** | **405** |
| `DELETE …/label/{tracking}` — the void the adapter already uses | 503 `Not found` | 503 `Not found` |

Retried at +40 s and +5 min: identical. The unknown-key answer is therefore

```
HTTP 400
{"apiVersion":"/labels/v3/","error":{"code":"400","message":"Bad Request","errors":[{
  "title":"Bad Request",
  "detail":"Idempotency-Key not found for a mailing date within the last 14 days",
  "code":"160412",
  "source":{"parameter":"Header: X-Idempotency-Key"}}]}}
```

(same shape under `/international-labels/v3/`). The tracking-number variant is `160378`
with `source.parameter: "trackingNumber"`.

What TEM did and did not prove:

- The reprint-by-key endpoints exist on both deployed specs: an empty body is rejected
  by TEM's OAS validator against `labels-v3.yaml` / `international-labels-v3.yaml`
  (`Instance type (array) does not match … (allowed: [object])`), so `160412` is the
  backend's answer, not a routing miss.
- TEM has **no label store behind reprint or cancel**. Reprint-by-tracking and the
  existing cancel-by-tracking both fail for a label TEM issued seconds earlier, so a
  used key and an unused key are indistinguishable there. The positive case — 200 with
  the original label and tracking number — is unproven and can only be proven in
  production.
- USPS did **not** echo `X-Idempotency-Key` on the label response, which the spec says
  a 200 carries. Either TEM ignores the header or the echo is production-only; the
  production probe should check for it, since it is the cheapest proof the key was
  bound.
- `DELETE …/label` by key is not routed on TEM (405, `Access-Control-Allow-Methods:
  POST, DELETE` on the response suggests DELETE is only registered with a path
  parameter). Item 2 below depends on it; treat it as unverified.

**Probe 2 — UPS CIE.** Shipped package 209 on UPS Ground with a 26-character ULID in
the first package-level `ReferenceNumber` slot (`Code: TN`) and the client's `#1241`
in the second. UPS accepted the body, so the slot arrangement in item 3 is
schema-valid. But CIE's ship response is canned: `ShipmentIdentificationNumber` and
the package tracking number both come back as `1ZXXXXXXXXXXXXXXXX`, so nothing that
looks a label up can succeed there — void by that number is `190102 No shipment found
within the allowed void period`.

Label Recovery (`POST /api/labels/v1/recovery`) itself is live on CIE: it validates the
body (`9801050 Label Stock Size not allowed for specified Label Image Type` when
`LabelStockSize` is sent with GIF — send it only for ZPL) and does a real lookup. By
reference + `ShipperNumber`, by a reference never used, and by the canned tracking
number, all three answer the same:

```
HTTP 400
{"errors":[{"code":"9801031","message":"The shipment for the requested tracking number
or the combination of reference number plus shipper number could not be found. Please
check the submitted data or wait until the shipment is processed."}]}
```

That is the unknown-reference answer. Two corrections to the shape described above:
Label Recovery's `ReferenceValues.ReferenceNumber` has only `Value` (no `Code`) in the
vendored `upsShipping.json`, and `Value` is capped at 35 characters, which a hyphenated
UUID (36) exceeds — the offer's 26-character `public_id` fits.

**Go / no-go.** Both negative cases are characterized (USPS `160412`, UPS `9801031`)
and both are what `recoverPurchase()` would map to a failed `ShipResponse`. Neither
sandbox can show the positive case. Proving it costs one production label per carrier:
a USPS Ground Advantage label (~$8, `DELETE …/label/{tracking}` before the SSF is
created reverses the EPS charge) and a UPS Ground label (~$17, void within the day
reverses it if the account is not scan-based). The recovery code can be built against
the spec without that — the failure being guarded against is exactly a mismatch
between spec and behaviour, so the production probe is worth its two labels — but
spending them is the maintainer's call. Note that `9801031` says "or wait until the
shipment is processed", which is the lag question: if Label Recovery lags the ship
call by more than the seconds between a timeout and a retry, a "not found" would let
the retry buy a second label, and only a production probe can time that.

### Production probe — 2026-09-18, same day

Sandbox mode off, same scripts (`probe-18-prod-*` captures beside the sandbox ones).

**USPS — first attempt blocked before the label.** `POST
/payments/v3/payment-authorization` answered `401 "We are having trouble validating
your credit card, please try again later."` for EPS account 1000253214 (CRID 41512721)
— the EPS account's funding method, not the API. The account was switched to CRID
57258161 / EPS 1000413179 and the probe re-run the same hour.

**USPS domestic — proven.** Bought Ground Advantage `9200190414219000000011` ($8.40)
with key `3a2befe8-4475-48c7-a327-fe53439b355b`.

| Call | Result |
|---|---|
| `POST /labels/v3/label-reprint` by the key, 1.7 s after the label call | **200**, three parts — `labelMetadata` with `trackingNumber 9200190414219000000011`, `postage 8.4`, zone and commitment; `labelImage` (same 102 480-byte PDF size as the original; bytes differ, the image is re-rendered); `reprintInfo {"reprintNumber":1,"reprintLimit":3}`. Response header `x-idempotency-key` echoes the key |
| Reprint by a key never used | 400 `160412` — production wording is "…for a mailing date within the last **7** days" (TEM said 14) |
| `POST /labels/v3/label-reprint/{tracking}` | 200, same parts, `reprintNumber 2` — the reprint budget is shared between the two handles |
| `DELETE /labels/v3/label` by the key, no tracking number | **200** `{"trackingNumber":"9200190414219000000011","status":"CANCELED"}` — the TEM 405 was TEM only |
| Reprint by the key after the cancel | 400 **`160979`** "Canceled labels are unavailable for reprint" (`source.parameter: trackingNumber` even on the key route) |

USPS does not echo `X-Idempotency-Key` on the label response in production either;
the reprint response does. No lag: the first reprint found it.

**USPS international — not bought.** `POST /international-labels/v3/international-label`
answered 400 *"An indicia company name must be configured by USPS for manifestMID
904142190 in order to create a shipping label. Please contact your postal
representative."* — a setting on the new account's manifest MID, not the API. Nothing
was charged. The key, reprint and cancel routes are the same shape on the international
spec (TEM validated them against `international-labels-v3.yaml`), so this is treated as
proven by the domestic run; re-run the probe once the MID is configured to close it
properly. The script now skips flat-rate rates for a box.

**Mapping for `UspsAdapter::recoverPurchase()`.** 200 → a successful `ShipResponse` from
`labelMetadata` + `labelImage` (`postage` is the cost; the service is whatever the offer
said). 400 `160412` → a failed `ShipResponse`: nothing was bought. 400 `160979` → the
label exists but was cancelled — also a failed `ShipResponse`, with that message. Any
other status, or a transport error → `null`. Each recovery spends one of the three
reprints, so a package whose label was recovered has two reprints left for the
existing reprint path.

**UPS — proven.** Shipped package 209 on UPS Ground (`1Z14A6G90303889622`, $39.13
billed) with ULID `01M2V5387TF4P0NY4YVBHYCXMD` in the first package-level `TN` slot
and `#1241` in the second.

| Call | Result |
|---|---|
| Label Recovery by reference + `ShipperNumber`, 656 ms after the ship call | **200**, `ShipmentIdentificationNumber` and `LabelResults[0].TrackingNumber` both `1Z14A6G90303889622`, GIF label |
| Label Recovery by a reference never used | 400 `9801031` (as on CIE) |
| Label Recovery by tracking number | 200, same |
| Void by tracking number | 200 `Voided` |
| Label Recovery by reference after the void | 400 **`9801040`** "The shipment for which you are trying to recover a label or Receipt has been voided" |

The recovered `GraphicImage` is byte-identical to the one the ship call returned
(md5 `7d82aae5…` for all three files). No lag: the first attempt found it, so the
"wait until the shipment is processed" clause in `9801031` did not apply within the
second between purchase and retry.

**Go for the UPS half (item 3).** The mapping for `UpsAdapter::recoverPurchase()`:
200 with `LabelResults` → a successful `ShipResponse` built from it; `9801031` → a
failed `ShipResponse` (nothing was bought); `9801040` → the label exists but was
voided, which is also "not buyable on this offer" — a failed `ShipResponse` with that
message; any other status or a transport error → `null`. Send `LabelSpecification`
without `LabelStockSize` unless the format is ZPL.

Then:

1. **USPS.** `UspsAdapter::createShipment()` generates a UUID per purchase, stores it on
   the offer's encrypted `purchase_context` (the column that already exists for "what the
   source needs to identify this purchase") *before* the request goes out, and sends it as
   `X-Idempotency-Key` on both the domestic and international label requests. The adapter
   implements `RecoversUnresolvedPurchase`: `recoverPurchase()` calls reprint by the key,
   builds a `ShipResponse` from the reply on 200, returns a failed `ShipResponse` on the
   status probe 1 established for an unknown key, and `null` on anything else. Once it
   implements the contract, `recoverPurchase()` asks it instead of settling — no
   workflow change. The adapter must also stop swallowing transport errors on the
   purchase: today a USPS timeout is caught inside `createShipment()` and returned as a
   failure, which settles the offer as a decline; with a key to ask by, the exception
   must propagate (as Amazon's does) so the offer stays unresolved until reprint answers.
2. **USPS void of an orphan.** `recoverPurchase()` answering with a label is the normal
   path (ship on it). Cancel-by-key is for `16`'s "nothing should have been bought"
   outcome, and for a purchase that timed out on a package the operator has since
   shipped another way; not needed for the retry path itself.
3. **UPS, if probe 2 succeeds.** One `ReferenceNumber` slot carries the offer's
   `public_id` (26 characters, within UPS's 35) when the purchase is made from an offer;
   the client's references fill the remaining slot. Which slot, and whether a client with
   two references loses the second on the label or the key is withheld for that client,
   is the decision the probe informs — the ADR-0002 property matters more than the second
   reference for the tenants paying for orphans. `UpsAdapter` implements
   `RecoversUnresolvedPurchase` through Label Recovery by that reference.
4. **UPS, if probe 2 fails.** Record why in this issue, keep the carve-out for UPS, and
   change nothing.
5. **FedEx wording.** FedEx keeps swallowing the timeout into a failed `ShipResponse`, and
   the message the packer sees for it says *Label purchase failed — try again*, not that
   a label may exist.
   A generic "a label may already exist" for a carrier that will never bill one sends
   the packer to a portal for nothing.
6. **`RecoversUnresolvedPurchase` docblock.** It says a source without an idempotent
   purchase "must not implement this contract, because the question and the second
   purchase would be the same call." That was written for Amazon's key, where the
   purchase itself is idempotent. USPS's is not, but its *question* is a separate,
   side-effect-free call; the docblock should say the property required is a
   side-effect-free way to ask, of which an idempotent purchase is one.

## Tests

- Unit: the USPS label request carries `X-Idempotency-Key` equal to the UUID stored on
  the offer's `purchase_context`, on both label bodies; the schema guard from
  `carrier-request-schema-validation/01` does not reject the header.
- Feature: a USPS purchase that throws `RequestTimeOutException` leaves the offer
  unresolved (no `recordFailure`); the next `ship()` on the package calls reprint with
  the stored key; a 200 fixture ships the package on the reprinted label with the
  original tracking number and no second label request; the unknown-key fixture resolves
  the offer as failed and proceeds to the new purchase; a transport error leaves it
  blocked.
- Feature: the same three for UPS through Label Recovery, if built.
- Feature: a FedEx timeout still resolves the offer as failed, and the result title is
  the retry wording.
- Existing `OfferRedemptionOnShipTest` timeout pair keeps passing — the mock seller that
  cannot recover is the FedEx-shaped case.

## Acceptance criteria

- [x] Probe 1 captured: unknown key is 400 `160412`, cancelled label is 400 `160979`.
      Proven in production 2026-09-18 on the domestic API — reprint by key returns the
      original tracking number and label on the first try, and cancel by key works.
      International label creation is blocked by an account-side manifestMID setting;
      re-run the probe when it is configured.
- [x] Probe 2 captured: **go** for the UPS half. Proven in production 2026-09-18 —
      Label Recovery by our reference returns the label and tracking number on the
      first try; unknown reference is 400 `9801031`, voided label is 400 `9801040`.
- [x] A USPS timeout followed by a retry never buys a second label when the first
      succeeded, proved by the reprint fixture
      (`DirectCarrierPurchaseRecoveryTest`, and the same for UPS through Label Recovery).
- [x] A USPS timeout where nothing was bought still lets the retry buy, without
      operator intervention (`160412` fixture; `9801031` for UPS).
- [x] `purchase_context` for a direct USPS offer holds only the idempotency key; nothing
      that reaches the browser (asserted to equal `['idempotency_key' => <uuid>]`).
- [x] FedEx timeout wording changed — `FedexAdapter::NO_ANSWER_MESSAGE`.
- [x] `PurgeData`'s "kept N unresolved offers" warning now names, for direct carriers,
      only offers whose recovery answered `null` — a real unknown — not every timeout.
      Needed a column: `shipping_offers.recovery_unanswered_at`, stamped by the workflow
      when recovery returns `null` or throws; the not-yet-asked direct offers get their
      own `info` line.

## Not this issue

- The by-hand resolution UI — `16`. This issue makes `16` rarer for USPS and UPS and
  leaves it the only path for a recovery that keeps answering `null`.
- Sending `X-Idempotency-Key` for its manifest side effect (it lands in the Shipping
  Services File as the External Reference ID). Useful, but a reporting question.
- FedEx track-by-reference recovery. Not worth a metered call for a label FedEx will
  not bill.

## Blocked by

`14` (branch `postage-source-split-14`), which introduces the offer row a direct
purchase is spent against and the `recoverPurchase()` carve-out this narrows.

## Comments

**2026-09-18** — Probed, sandbox then production, same day; results under *Probe
results* and *Production probe*. `ready-for-agent`.

**2026-09-18** — Shipped, items 1 and 3–6 as written, with the decisions the probes
left open resolved as follows.

*USPS.* `UspsAdapter::createShipment()` mints a UUID per purchase, writes it to the
offer's `purchase_context` as `idempotency_key` before the request leaves, and sends it as
`X-Idempotency-Key` on both label requests (`LabelReprint` and
`InternationalLabelReprint` are the two new Saloon requests; `LabelResponse` now reads
the reprint's third part). `recoverPurchase()` maps a 200 to a successful `ShipResponse`
(cost from `postage`, service from the offer), `160412` and `160979` to a failed one, and
everything else — a different 4xx, a 5xx, no answer — to `null`. An offer with no key
recorded (spent before this shipped) is settled as not bought, on the same reasoning as
`14`'s carve-out: nobody can be asked.

*UPS.* Item 3's open decision: the offer's `public_id` always takes the **first**
package- or shipment-level `TN` slot and the client keeps one reference; a client
printing two loses the second. `LabelRecovery` is the new request; `recoverPurchase()`
maps a 200 to a successful `ShipResponse` (cost is the offer's price — Label Recovery
returns no charges), `9801031` and `9801040` to a failed one, the rest to `null`.
`LabelStockSize` is sent only for ZPL. Recovery on an international lane, where the
reference sits at shipment level, is built the same way but was not probed.

*Transport errors and 5xx.* Both adapters now let `FatalRequestException`,
`RequestTimeOutException` and every `ServerException` out of `createShipment()` — a
4xx is still a failed response, a 5xx is not, since the carrier may have created the
label before failing — and the workflow catches all three beside the timeout so they
read as *Carrier Timeout* rather than *Shipping Error*. FedEx keeps catching them, now
with its own wording.

*The account that is asked.* Recovery asks the account the offer recorded
(`carrier_account_id`), never the one scopes prefer now: USPS keys are per CRID and
UPS looks up by reference *and* shipper number, so another account's "not found" would
be read as "nothing was bought" while the original owns a label. An offer whose
account is gone (the FK nulls the id and leaves the fingerprint) or whose billing
fingerprint has changed is left unresolved without a call —
`ResolvesCarrierAccount::purchasingAccountChanged()` / `purchasingAccount()`.

*What a recovery carries.* A recovered USPS international label keeps the landscape
orientation the purchase path records; a recovered UPS label on a cross-border lane
carries `LabelRecoveryResponse.Form.Image.GraphicImage` as its customs form, and is
left unresolved if UPS returns the label without the form the lane needs.

*Retries.* Not in the ticket, found on the way: the connectors' `tries = 3` re-sent a
label POST after a connection failure — three labels under one key in the worst case,
and exactly the double purchase this issue exists to prevent. `Label`,
`InternationalLabel`, both reprints, UPS `CreateShipment` and `LabelRecovery` are now
`tries = 1`, each proved sent once by a counting fake. With one try Saloon no longer
throws on a 4xx, so `UpsAdapter::sendCreateShipment()` calls `throw()` itself to keep
the existing catch path.

*Item 2 (cancel by key)* was not built: the retry path does not need it, and `16` is
where "nothing should have been bought" will be decided. The production probe proved the
endpoint (`DELETE /labels/v3/label` by key → `CANCELED`), so it is a small request class
when `16` wants it.

*Contract docblock* reworded per item 6; the workflow's and `OfferStore`'s "only Amazon
implements" prose updated to match.

Still open, not this issue: the USPS international label endpoint has not been run in
production (account-side manifestMID setting) — re-run `probe-18-reprint-by-key.php`
when it is; and the production UPS label billed $39.13 against a $16.96 quote, which
is `ups-rate-accuracy/01`.
