# Failure cleanup deletes the record of an unresolved purchase

Status: needs-triage

Repo: `polybag`

Severity: high — can buy a second label for one parcel, with no record of the first.
Verified: confirmed (tests below fail on `main` at `9630409`).

## Problem

When a carrier call times out, the Offer is left consumed and unresolved on purpose: the
label may exist, and `settleEarlierPurchases()` asks the carrier before anything else is
bought (`CONTEXT.md`: "it remains awaiting confirmation and must be recovered or
resolved before another purchase is attempted"). That Offer row is the *only* record
that a label may exist.

Two unattended callers then delete the Package, and `shipping_offers.package_id` is
`cascadeOnDelete()`
(`database/migrations/2026_09_04_120100_create_shipping_offers_table.php:33`), so the
Offer goes with it:

- **Manual Ship with Auto Ship on.** `ManualShip::autoShip()`
  (`app/Filament/Pages/ManualShip.php:253`) leaves `cleanupOnFailure` at its default of
  `true`. A timeout comes back from `buyPostage()` as `PackageShippingResult::failed('Carrier Timeout', …)`
  (`EloquentPackageShippingWorkflow.php:502`), which does not set `leavePackageIntact`, so
  `cleanupPackage()` (`:1652`) deletes the Package.
- **Batch ship.** `GenerateLabelJob` passes `cleanupOnFailure: false` to the workflow
  (`app/Jobs/GenerateLabelJob.php:54`) and then deletes the unshipped Package itself in
  `handleFailure()` (`:78`) on *every* failure. It ignores `leavePackageIntact`, so it
  also deletes after `Earlier Purchase Unresolved` and `Purchase In Progress`, the two
  results whose whole purpose is to leave the Package alone. ADR-0004 decision 7 says
  "Pack and the batch job opt out of cleanup"; the job's own cleanup contradicts it.

After the delete, the Shipment has no unshipped Package, so it is eligible for the next
batch again (`BatchLabelService.php:78`), and the operator re-entering a manual shipment
starts fresh. Either buys a new label with nothing to ask about the old one.

The batch path has a second way in: `GenerateLabelJob` has `$tries = 2`. If the worker is
killed mid-purchase (a queue timeout during a slow carrier call), the retry finds the
180-second `package-purchase:{id}` lock still held, gets `Purchase In Progress`, and
`handleFailure()` deletes the Package and the unresolved Offer. That path is plausible
rather than tested.

## Evidence

Rate-shopped purchase, carrier times out, default cleanup: the Offer and Package are
gone. The same run with `cleanupOnFailure: false` keeps both, with the Offer awaiting
confirmation, so the cleanup is what deletes them.

```php
it('keeps the package and its unresolved offer when Manual Ship auto-ship times out', function (): void {
    $package = createWorkflowPackage();            // tests/Feature/PackageShippingWorkflowTest.php
    ShippingRule::query()->delete();               // rate-shop, so the purchase is made against an offer

    $service = CarrierService::where('service_code', 'GROUND')->first();
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect([
        new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days', carrierServiceId: $service->id),
    ]));
    $adapter->shouldReceive('createShipment')->andThrow(new RequestTimeOutException(Mockery::mock(Response::class), 'timed out'));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip($package, new PackageAutoShippingRequest);

    expect($result->title)->toBe('Carrier Timeout')
        ->and(ShippingOffer::whereNotNull('consumed_at')->count())->toBe(1)   // fails: 0
        ->and(Package::find($package->id))->not->toBeNull();
});
```

The batch variant runs `GenerateLabelJob::handle()` on `createBatchContext()`
(`tests/Feature/Jobs/GenerateLabelJobTest.php`) with the same adapter and fails the same
assertion.

## What to build

Never delete a Package that has a consumed Offer awaiting confirmation, and never delete
one the workflow asked to leave intact.

- `PackageShippingResult::failed()` for a carrier timeout, and the `RequestException`
  case, should set `leavePackageIntact: true`. An unanswered purchase is not a clean
  failure.
- `GenerateLabelJob::handleFailure()` should honour `$result->leavePackageIntact`, and
  should also refuse to delete when `OfferStore::awaitingPurchaseConfirmation()` is
  non-empty, as a backstop for the `Throwable` branch, which has no result to read.
- `cleanupPackage()` gets the same backstop.
- Consider `restrictOnDelete()` on `shipping_offers.package_id` for rows awaiting
  confirmation. A cascade silently deletes the one row that says money may have
  moved. A database-level guard would stop every present and future delete path, at
  the cost of the delete actions having to handle the refusal.

A Package kept this way stays `Unshipped` with its batch item marked failed. The
operator's next Ship attempt asks the carrier through `recoverPurchase()` before buying.
That is the existing, tested recovery path.

## Comments
