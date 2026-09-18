# Recover an unresolved direct-carrier purchase from the carrier

Status: ready-for-human — two sandbox probes first (below); the code after them is agent work

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

- [ ] Probe 1 captured, with the unknown-key status recorded in this issue.
- [ ] Probe 2 captured, with a go/no-go for the UPS half recorded here.
- [ ] A USPS timeout followed by a retry never buys a second label when the first
      succeeded, proved by the reprint fixture.
- [ ] A USPS timeout where nothing was bought still lets the retry buy, without
      operator intervention.
- [ ] `purchase_context` for a direct USPS offer holds only the idempotency key; nothing
      that reaches the browser.
- [ ] FedEx timeout wording changed.
- [ ] `PurgeData`'s "kept N unresolved offers" warning now names, for direct carriers,
      only offers whose recovery answered `null` — a real unknown — not every timeout.

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
