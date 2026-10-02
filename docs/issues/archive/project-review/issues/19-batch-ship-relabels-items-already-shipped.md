# Batch ship buys a label for items a partly shipped order has already sent

Status: done — 2026-09-29

Repo: `polybag`

Severity: high. A second label is paid for, and the pack slip lists the whole order again,
so the goods already sent can be sent twice.
Verified: confirmed (test below fails on `main` at `6d47232`).

## Problem

A Shipment can go out in several Packages. `Shipment::updateShippedStatus()` keeps it
`Open` until every item is in a shipped Package, so a partly shipped order stays `Open`
and still appears in the Shipments list that Batch Ship runs from.

`BatchLabelService::getIneligibilityReason()` checks the Shipment's status and refuses
existing *unshipped* Packages. It doesn't look at shipped ones. A partly shipped order
therefore passes. `EloquentPackageDraftWorkflow::batchDraftInput()` then builds the batch
Package from **every** shipment item at its **full** quantity, not what is left.
`hasCompletePackedItems()` agrees, since it compares against the full quantities too.
The weight is summed the same way, so the label is rated and bought for the whole order.

After the purchase the Shipment becomes `Shipped`, and the batch Package's items include
the ones the first Package already carried.

## Evidence

In `tests/Feature/Services/BatchLabelServiceTest.php`:

```php
it('never batches a package holding items an earlier package already shipped', function (): void {
    Bus::fake();
    $user = User::factory()->admin()->create();
    $shipment = Shipment::factory()->create();
    $shipped = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 1]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 1]);

    $first = Package::factory()->shipped()->create(['shipment_id' => $shipment->id]);
    PackageItem::create(['package_id' => $first->id, 'shipment_item_id' => $shipped->id,
        'product_id' => $shipped->product_id, 'quantity' => 1]);
    $shipment->refresh()->updateShippedStatus();                     // stays open

    $result = $this->service->validateShipmentsForBatch(collect([$shipment->fresh()]));

    if ($result->eligible->isNotEmpty()) {
        $this->service->createBatch($result->eligible, BoxSize::factory()->create(), $user, 'pdf', null);
    }

    $batched = Package::where('shipment_id', $shipment->id)->where('status', PackageStatus::Unshipped)->first();
    expect($batched?->packageItems()->where('shipment_item_id', $shipped->id)->exists() ?? false)
        ->toBeFalse();                                               // fails: the shipped item is packed again
});
```

## What to build

Decide which of two behaviours you want, and test it:

- **Skip it.** Add `Partly shipped` to `getIneligibilityReason()` when any Package of the
  Shipment is shipped. Batch ship assumes one box per order, and a partly shipped order
  is a person's to finish on the Pack page. This is the smaller change, and matches the
  existing *Has existing unshipped packages* skip.
- **Ship the remainder.** Have `batchDraftInput()` pack only the quantity not yet in a
  shipped Package, and have `hasCompletePackedItems()` compare against that for batch
  drafts.

Either way, `createBatchReadyDraft()` should also re-check inside its shipment lock. The
validation and the draft run in one request today, but the lock is where the decision is
safe.

## Comments

- 2026-09-29 — Built **Skip it**. `BatchLabelService::getIneligibilityReason()` returns
  `Partly shipped` when any Package of the Shipment is shipped, checked right after the
  existing *Has existing unshipped packages* skip. `createBatchReadyDraft()` re-checks
  inside its shipment lock and throws `PackageDraftInvalidException` ("Shipment is partly
  shipped; finish it on the Pack page.") before creating a draft. `batchDraftInput()` and
  `hasCompletePackedItems()` are unchanged, since batch drafts are now only built for
  orders with nothing shipped. Regression tests: the Evidence test and a
  `Partly shipped` reason test in `BatchLabelServiceTest`, and the lock re-check in
  `PackageDraftWorkflowTest`. All three fail without the fix. *Ship the remainder* is
  still possible later if partly shipped orders should batch.
