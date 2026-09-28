# A paid label that fails to record is forgotten, and can be bought again

Status: needs-triage

Repo: `polybag`

Severity: medium — the trigger is rare, but when it happens money is spent twice and nothing
records the first purchase.
Verified: confirmed (test below fails on `main` at `9630409`; the trigger is contrived,
the gap it exposes is not).

## Problem

After the carrier confirms a purchase, `buyPostage()` does two things in order
(`EloquentPackageShippingWorkflow.php:478` and `:483`):

1. `recordPurchaseAgainstOffer()` stamps the tracking number into the Offer's
   `purchase_reference`, which moves it out of "awaiting confirmation".
2. `$package->markShipped()` writes the Package and the Label row.

If step 2 throws, the label is paid for and recorded nowhere except as a
`purchase_reference` on an Offer that now reads as *resolved*. `settleEarlierPurchases()`
only looks at unresolved Offers, so the next attempt buys a second label. The Package is
`Unshipped`, has no Label row, and shows no sign of the first purchase.

`markShipped()` can throw after the carrier has been paid:

- its two pre-transaction assertions (`Package.php:620`), which catch an adapter that
  returns inconsistent provenance or service evidence as `InvalidArgumentException`. That
  is an adapter bug, and a paid label is the worst moment to discover one;
- any database error inside the transaction: a MySQL deadlock or lock-wait timeout
  against a concurrent tracking or print update, or a dropped connection;
- the optimistic-lock `RuntimeException` (`:694`) when the row was changed or deleted
  meanwhile.

`recoverPurchase()` has the same order (`:896`–`:897`).

The operator sees "An unexpected error occurred" or "Package State Changed" and is
invited to try again.

## Evidence

The test forces the failure with an inconsistent provenance. Any exception from
`markShipped()` takes the same path.

```php
it('does not buy a second label when recording the first one fails', function (): void {
    $package = createWorkflowPackage();
    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days');

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $calls = 0;
    $adapter->shouldReceive('createShipment')->andReturnUsing(function () use (&$calls): ShipResponse {
        $calls++;

        return new ShipResponse(
            success: true, trackingNumber: 'PAID-1', cost: 7.25, carrier: 'MockCarrier', service: 'Ground',
            labelData: base64_encode('label'), postageDataSourceId: 999,   // makes markShipped() throw
        );
    });
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $workflow = app(PackageShippingWorkflow::class);
    $first = $workflow->ship($package, new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate)));
    $workflow->ship($package->fresh(), new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate)));

    expect($first->success)->toBeFalse()
        ->and($calls)->toBe(1);   // fails: 2
});
```

## What to build

Don't let "the source confirmed" and "we recorded it" separate without a trace that
blocks the next purchase.

- Record the purchase on the Offer only after `markShipped()` succeeds. If it fails, the
  Offer stays unresolved and `settleEarlierPurchases()` blocks and recovers as it would
  after a timeout. The existing adapters' recovery (USPS reprint by key, UPS label
  recovery, Amazon's idempotent repeat) returns the same label, so the retry ships on it.
  This is the smallest change, but it relies on every adapter recovering, and FedEx
  doesn't.
- Or, more robustly, catch a failure from `markShipped()` specifically: mark the Offer
  with a new *purchased, not recorded* state that `awaitingPurchaseConfirmation()`
  includes, keep the `ShipResponse` facts (tracking number, cost, label reference) on the
  Offer, and log at error level. A person, or a retry of `markShipped()` from those facts,
  then settles it.

Separately, the provenance and evidence assertions belong before `createShipment()` where
they can be checked (the seller and postage source are known then), so an adapter
contract violation is found before money moves rather than after.

## Comments
