# Any user can void any label from the Packages table

Status: needs-triage

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
