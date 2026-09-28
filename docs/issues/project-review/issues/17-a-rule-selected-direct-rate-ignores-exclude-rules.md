# A rule-selected direct rate ignores *Exclude* rules

Status: needs-triage

Repo: `polybag`

Severity: medium. Automation buys the service an *Exclude* rule hides from the packer.
Verified: confirmed (test below fails on `main` at `6d47232`).

## Problem

ADR-0006 decision 7: *Exclude* rules "apply on the Ship page as well as in automation".
`RuleEvaluator::evaluate()` walks the rules in priority order, collecting exclusions until
the first *Use* rule that matches, and returns both. Every consumer but one then drops the
excluded rates:

- `prepareRates()` rejects them before the page sees them;
- the *Use* rule's scope path (`RuleRateScope`, *any priced source* and Amazon) rejects
  them before selecting;
- rate shopping rejects them;
- a pre-selected blind purchase rejects them (`excludesBlindOffer`).

The exception is a *Use* rule naming a direct service. `selectedRateForAutoShip()`
resolves `$ruleResult->preSelectedRate` through the adapter and hands it straight to
`selectForAutomation()` without asking `$ruleResult->excludes()`. So a higher-priority
*Exclude* rule that matches the chosen service is ignored, but only in automation.

A realistic setup: rule 1, *Exclude UPS* when the destination state is AK or HI; rule 2,
*Use Direct, UPS Ground* when the package weighs 5 lb or more. A 6 lb order to Alaska:
the Ship page doesn't show UPS at all, while batch ship and auto-ship buy UPS Ground.

The priority order is by design: an *Exclude* ranked after the matching *Use* rule isn't
collected (`RuleEvaluatorTest`, "collects exclude codes before UseService stops
evaluation"). This finding is about the ones ranked before it.

## Evidence

In `tests/Feature/ShippingRuleSourceTest.php`'s setup:

```php
it('does not buy a pre-selected direct rate an earlier Exclude rule removes', function (): void {
    $direct = sourceRuleDirectRate($this->ground, 9.00);
    sourceRuleAdapter([$direct, sourceRuleDirectRate($this->express, 12.00)], preSelected: $direct);

    ShippingRule::factory()->excludeService()->create([
        'shipping_method_id' => $this->method->id,
        'carrier_service_id' => $this->ground->id,
        'priority' => 0,
    ]);
    ShippingRule::factory()->source(ShippingRuleSource::Direct)->create([
        'shipping_method_id' => $this->method->id,
        'carrier_service_id' => $this->ground->id,
        'priority' => 10,
    ]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($this->package);
    expect(collect($options->rateOptions)->pluck('serviceCode')->all())->not->toContain('GROUND'); // passes

    app(PackageShippingWorkflow::class)->autoShip(
        $this->package,
        new PackageAutoShippingRequest(userId: auth()->id(), cleanupOnFailure: false),
    );

    expect($this->package->fresh()->service)->not->toBe('Ground');                                // fails: 'Ground'
});
```

## What to build

- In `selectedRateForAutoShip()`, treat a pre-selected rate that `$ruleResult->excludes()`
  as absent and rate-shop, as the path already does when the adapter finds no variant.
  Log it beside the existing "rate shopping instead" lines.
- Or have `RuleEvaluator` skip a direct *Use* rule whose service the exclusions collected
  so far already match, so the next rule can apply. That also fixes the Ship page's
  highlight, and it's the more natural reading of priority order. Either way, add the test
  above.

## Comments
