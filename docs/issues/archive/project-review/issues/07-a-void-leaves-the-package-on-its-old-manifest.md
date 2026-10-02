# A void leaves the Package on its old manifest, so the re-shipped label is never manifested

Status: done — 2026-10-01

Repo: `polybag`

Severity: medium. A re-shipped USPS parcel silently misses every later SCAN form, and the
old form's record now lists a tracking number it never covered.
Verified: confirmed (test below fails at `a099283`).

## Problem

`manifest_id` lives on `packages` and describes the Label that was manifested, but neither
Label writer touches it:

- `Package::clearShipping()` nulls every shipping column and returns the Package to
  `Unshipped`. `manifest_id` is not in its update list.
- `Package::markShipped()` doesn't reset it either.

Both manifest queries select on `whereNull('manifest_id')`
(`ManifestService::getUnmanifestedPackages()`, `EndOfDay.php:221`). So a Package that
was manifested, then voided and re-shipped, keeps pointing at the old manifest. Its new
Label is never offered for End of Day, and the Packages table's "manifested" filter
reports it as manifested under a form that covered its voided tracking number.

The usual trigger is the case ADR-0004 calls common: a label jams or is wrong after the
day's SCAN form, so it is voided and re-bought for the same Package.

There is a second way in. `createUspsScanForm()` marks packages by id
(`Package::whereIn('id', …)->update(['manifest_id' => …])`) after the USPS call, with no
check that each one is still shipped with the tracking number it sent. A Package voided
during End of Day is stamped with a manifest its Label is no longer on.

## Evidence

```php
it('puts a label re-shipped after a void on the next manifest', function (): void {
    $package = Package::factory()->shipped()->for(Shipment::factory())->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400100000000000000001',
        'postage_source' => PostageSource::CarrierAccount,
    ]);
    $manifest = Manifest::create([
        'carrier' => 'USPS', 'manifest_number' => 'M1', 'manifest_date' => now()->toDateString(), 'package_count' => 1,
    ]);
    $package->forceFill(['manifest_id' => $manifest->id])->save();

    $package->clearShipping(VoidReason::Operator);
    $package->markShipped(
        new ShipResponse(success: true, trackingNumber: '9400100000000000000002', cost: 5.0, carrier: 'USPS', service: 'Ground Advantage', labelData: 'x'),
        PostageSource::CarrierAccount,
    );

    expect($package->fresh()->manifest_id)->toBeNull()   // fails: 1
        ->and(app(ManifestService::class)->getUnmanifestedPackages()->flatten()->pluck('id')->all())
        ->toContain($package->id);
});
```

## What to build

- `clearShipping()` nulls `manifest_id` along with the other shipping columns. The
  manifest keeps its own `package_count` and number. Losing the link from a voided Label
  to its form is the same history loss ADR-0004 fixed for the other facts, so consider
  recording `manifest_id` on the `package_labels` row before nulling it on the Package.
- The SCAN form's marking update adds `status = shipped` and
  `tracking_number IN (sent numbers)` to its `WHERE`, so it can't stamp a Package whose
  Label changed while USPS was answering.
- Question for triage: should a Label that is already on a SCAN form be voidable at all?
  USPS has accepted the form's tracking numbers for induction. If a manifested label has
  to be refused or warned about, that belongs in the void workflow, not here.

## Comments

- 2026-10-01 — Triage question answered: don't refuse the void. USPS's cancel endpoint
  cancels a label until its Shipping Services File is created, and after that it submits a
  refund request for the unused label. Either way the reply reads `CANCELED`, which
  `UspsAdapter::readCancelReply()` already records as a void. So a manifested label can be
  voided, and the re-shipped label has to reach the next SCAN form, which is this fix.
  It is not yet known when USPS creates the file: creating the SCAN form and the first
  acceptance scan are the likely triggers, and that needs a sandbox test. A refund
  request returns a `disputeId`; the void message could say "refund requested, pull the
  old label" for a label that was on a SCAN form. That is optional and separate from the
  fix.
- 2026-10-01 — Built. `manifest_id` is now a projected column (`PackageLabel::PROJECTED_COLUMNS`)
  with a new `package_labels.manifest_id`, backfilled for active labels. `markShipped()`
  writes it as null on both rows. `clearShipping()` nulls it on the Package only, so the
  voided Label keeps the form it was on. `ManifestService::markManifested()` stamps both
  rows from the tracking numbers USPS was sent, only where the Package is still shipped
  with that number. That covers the success path and the "already manifested" path.
  Regression tests in `ManifestAfterVoidTest` (the Evidence test, both rows stamped
  together, and a void during the USPS call) all fail without the fix. The optional
  "refund requested" void message was not built.
