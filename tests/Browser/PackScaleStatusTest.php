<?php

use App\Enums\Role;
use App\Models\User;

/**
 * Scale statuses share one reserved line under the measurements, so one
 * appearing or clearing never moves the page.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('keeps the measurements in place as the too-light message comes and goes', function (): void {
    $page = visit('/pack');

    // Alpine has started once its own status shows.
    $page->assertSee('No scale connected');

    $layout = <<<'JS'
        (() => {
            const status = document.querySelector('[data-testid="scale-status"]');
            return [status.getBoundingClientRect().height, status.innerText.trim()];
        })()
    JS;

    $page->script(<<<'JS'
        (async () => {
            const pack = Alpine.$data(document.querySelector('[x-data*="scaleConnected"]'));
            pack.scaleConnected = true;
            pack.scaleStable = true;
            pack.scaleUnderZero = false;
            pack.scaleReadingIsZero = true;
            pack.lastScaleWeight = '0.00';
            pack.weight = '0.00';
            await Alpine.nextTick();
        })()
    JS);

    $page->assertSee('Too light for the scale to read. Ships as 0.05 lbs.');
    [$tooLightHeight, $tooLightText] = $page->script($layout);

    $page->script(<<<'JS'
        (async () => {
            const pack = Alpine.$data(document.querySelector('[x-data*="scaleConnected"]'));
            pack.scaleReadingIsZero = false;
            pack.lastScaleWeight = '1.25';
            pack.weight = '1.25';
            await Alpine.nextTick();
        })()
    JS);

    $page->assertDontSee('Too light for the scale to read.');
    [$weighedHeight, $weighedText] = $page->script($layout);

    expect($tooLightText)->toStartWith('Too light for the scale to read.')
        ->and($weighedText)->toBe('')
        ->and($weighedHeight)->toBe($tooLightHeight)
        ->and($weighedHeight)->toBeGreaterThan(0);

    $page->assertNoJavaScriptErrors();
});
