# Any user can void any label from the Packages table

Status: done — 2026-09-29

Repo: `polybag`

Severity: medium. Any signed-in user can un-ship and refund someone else's parcel, a
stricter action than the reprint that is gated.
Verified: confirmed (test below fails at `a099283`).

## Problem

Three places void a Label, and only one checks who is asking:

- **Pack** (`Pack::cancelLastLabel()`) lets a user void only a Package they shipped,
  unless they are a Manager or above (`canAccessPackage()`).
- **Packages table** (`PackageResource.php:516`) and **View Package**
  (`ViewPackage.php:50`) call `PackageLabelWorkflow::voidLabel()` for anyone who can see
  the row. `PackagePolicy::view()` returns `true` for every role, and neither action has
  `->authorize()`.
- **`EloquentPackageLabelWorkflow::voidLabel()`** checks nothing. By contrast, its sibling
  `labelForReprint()` asks `printLabel`, which is exactly Pack's rule, and
  `LabelPrintController` asks it again.

So the policy gates reprinting a label but not voiding one. A void refunds the postage,
returns the Package to `Unshipped`, and for a parcel already handed to the carrier,
leaves a live parcel on a dead label.

## Evidence

```php
it('does not let a user void a label someone else shipped from the packages table', function (): void {
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('cancelShipment')->andReturn(CancelResponse::success('voided'));
    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);

    $shipper = User::factory()->create(['role' => Role::User]);
    $packer = User::factory()->create(['role' => Role::User]);
    $package = Package::factory()->shipped()->for(Shipment::factory())->create([
        'carrier' => 'USPS',
        'shipped_by_user_id' => $shipper->id,
    ]);

    $this->actingAs($packer);

    try {
        Livewire::test(ListPackages::class)->callAction(TestAction::make('void')->table($package));
    } catch (\Throwable) {
        // A refusal by exception is also a pass.
    }

    expect($package->fresh()->status)->toBe(PackageStatus::Shipped);   // fails: Unshipped
});
```

## What to build

- Add `PackagePolicy::voidLabel()`. Pack's rule is the obvious default (Manager and above,
  or the user who shipped it), but the choice is a product one, so triage should confirm
  it.
- Check it inside `EloquentPackageLabelWorkflow::voidLabel()`, as `labelForReprint()`
  checks `printLabel`, so every present and future caller is covered. The contract takes
  no user today. Either add one, or read `auth()->user()` as the workflow already does for
  `voidedByUserId`.
- Put `->authorize('voidLabel')` on both Filament actions so the button is hidden, not
  just refused. Replace Pack's private `canAccessPackage()` with the policy.

This is also area D (authorization). It is filed here because the void workflow is where
the missing check belongs.

## Comments

- 2026-09-29 — Fixed with Pack's rule, Manager and above or the user who shipped the
  Package. Changed from the proposal: no new `voidLabel` ability. `PackagePolicy::printLabel`
  already is that rule, so voiding reuses it and its docblock now says it governs printing
  and voiding a bought label; two abilities with one rule would drift apart. The rule
  itself is still the product choice the proposal flagged, and changing it later means
  splitting the ability. `PackageLabelWorkflow::voidLabel()` now takes the `User`, as
  `labelForReprint()` does, and refuses with `Access Denied` before any other check or
  asking the postage source; the user it is given is also the one recorded as
  `voided_by_user_id`, replacing `auth()->id()`. The Packages table and View Package void actions have
  `->authorize('printLabel')`, so the button is hidden from anyone refused, and Pack's
  private `canAccessPackage()` is gone: Pack relies on the workflow's refusal. Callers in existing tests now
  pass a Manager. Regression tests are in `AuthorizationTest` (`voiding a label`: a User
  who didn't ship it cannot void from the table or View Package and doesn't see the
  button; the shipper, a Manager and an Admin can) and `PackageLabelWorkflowTest` (the
  workflow refuses such a User without calling the carrier) and `PackTest` (Pack's cancel
  refuses such a User and lets the shipper through). The three refusal cases in
  `AuthorizationTest` and the workflow one fail without the fix; the Pack refusal fails if
  the workflow check is removed.
