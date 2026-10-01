# Rule-selected purchases have no Offer, so a timeout can buy twice

Status: done — 2026-09-28

Repo: `polybag`

Severity: high — every unattended purchase through a *Use service* rule is exposed.
Verified: confirmed (test below fails on `main` at `9630409`).

## Problem

Double-purchase protection is built on the Offer row. `redeem()` claims it,
`settleEarlierPurchases()` finds it unresolved after a timeout, and `recoverPurchase()`
asks the carrier about it by the handle stored on it. USPS stores its
`X-Idempotency-Key` in the Offer's `purchase_context` before the request leaves
(`UspsAdapter.php:531`).

A shipping rule's pre-selected rate never has an Offer. `selectedRateForAutoShip()`
resolves it through the adapter (`EloquentPackageShippingWorkflow.php:1330`), and
`autoShip()` sends it to `purchase()` with no offer identifier (`:568`). The comment
there, and the existing test *auto ships through a rule preselected rate*
(`ShippingOffer::count()` is `0`), treat that as intended: the rate is server-built, so
nothing needs protecting from the browser. That's true for tampering, but the Offer is
also the recovery record, and a purchase without one has none:

- `redeem()` is skipped, so no claim is made.
- A timeout, connection drop or 5xx leaves nothing unresolved, and `settleEarlierPurchases()`
  finds nothing.
- USPS's idempotency key is minted and thrown away (`$request->offer?->update(...)`
  does nothing when `offer` is null). Its own docblock names the case: "spent by a path
  with nowhere to store it".

So the next unattended attempt on the same Package buys again: a batch re-run, a retried
auto-ship, or a Pack-page auto-ship after an error. USPS, UPS and Amazon deliberately let
transport errors through because "the label may exist". On this path nothing then asks
about it.

A *Use service* rule is the ordinary way to make batch ship buy a fixed service, so this
is the common unattended path, not an edge case.

## Evidence

```php
it('does not buy again after a rule-selected purchase timed out', function (): void {
    $package = createWorkflowPackage();            // has a Use-service rule for MockCarrier GROUND

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('resolvePreSelectedRate')->andReturnUsing(fn (RateResponse $rate): RateResponse => $rate);
    $calls = 0;
    $adapter->shouldReceive('createShipment')->andReturnUsing(function () use (&$calls): never {
        $calls++;
        throw new RequestTimeOutException(Mockery::mock(Response::class), 'timed out');
    });
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $workflow = app(PackageShippingWorkflow::class);
    $workflow->autoShip($package, new PackageAutoShippingRequest(cleanupOnFailure: false));
    $workflow->autoShip($package->fresh(), new PackageAutoShippingRequest(cleanupOnFailure: false));

    expect($calls)->toBe(1);   // fails: 2
});
```

## What to build

Issue an Offer for the resolved pre-selected rate before it is bought, server-side,
through `OfferStore::issue()`, with the same quote fingerprint and carrier-account
fingerprint `ShippingRateService::offer()` stamps. The purchase then goes through the
same `inspect()`/`redeem()`/recovery path as a rate-shopped one. That also gives the
quote log and the Label a pointer for this path, which `markSelected()` currently
skips (`postage-source-split/17`).

The alternative, a separate "attempt" record for offer-less purchases, would be a
second recovery mechanism to keep in step with the first. Every purchase having an
Offer is simpler, and matches `CONTEXT.md`: "Every rate a packer can choose is one."

The existing test *auto ships through a rule preselected rate* asserts
`ShippingOffer::count()` is `0` and changes with the fix.

## Comments

- 2026-09-28 — Fixed as proposed. `autoShip()` passes the selected rate through
  `ShippingRateService::offerForUnquotedRate()`, which issues a direct Offer the same way
  rate shopping does (shared `issueDirectOffer()`): quote fingerprint from the Package as
  stored, carrier-account fingerprint, and an expiry at the end of the carrier's ship
  day. A rate that already names an Offer is left alone. The purchase then goes through
  `inspect()`, `redeem()` and recovery like any other. The Evidence test above used a
  carrier that cannot be asked about an earlier purchase. For that kind of carrier, which
  is FedEx today, the next attempt settles the Offer and buys again, by the existing
  design in `recoverPurchase()`. So the regression test uses a carrier that can be asked
  (`RecoversUnresolvedPurchase`, answering "can't say") and asserts the second attempt is
  refused with `Earlier Purchase Unresolved`. It fails without the fix. The old test
  asserting a pre-selected purchase issues no Offer now asserts it issues one and records
  the purchase on it.

- 2026-09-28 — Two gaps in the first fix, found by an independent review before merge.
  First, the package-level fingerprint applies every declared-value code on the method,
  unscoped, and can throw `MissingDeclaredValueException` before the purchase. That turned
  "Declared Value Required", and a purchase that previously succeeded because the code
  was scoped to another service, into a generic "Auto Ship Error". The Offer is now
  issued without a fingerprint in that case. Second, a rule's rate names no account, so
  the Offer recorded none: the account check was skipped, and UPS recovery would have
  asked whichever account scopes preferred at retry. The Offer now records the account
  `CarrierAccount::resolveForShipment()` gives, the same resolution the purchase re-checks.
  Both have regression tests that fail without the change.

- 2026-09-30 — `offerForUnquotedRate()` is removed by `18`. A *Direct* *Use* rule now
  selects among rate-shopped rates, and rate shopping issues every rate's Offer, so there
  is no unquoted rate left to issue one for. The protection holds by construction: every
  rate `autoShip()` buys names the Offer its quote issued, with the quote fingerprint and
  the account the adapter quoted on, and goes through `inspect()`, `redeem()` and recovery.
  The three regression tests still pass with the same assertions, their mocks now quoting
  the rule's service: a timed-out rule-selected purchase is not bought again; the Offer
  records the carrier account (now proved against the real `UpsAdapter`, so the account is
  the one UPS quoting resolved); and a missing declared value still reports "Declared
  Value Required". That last one is now raised by rate shopping, before any Offer, and
  `autoShip()` catches it by name. The case this note's second comment kept working, a
  declared-value code scoped to another service, is refused the same way the Ship page
  refuses it: rating the package applies every declared-value code on the method.
