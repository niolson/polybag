<?php

use App\Models\ObservedService;
use App\Models\Setting;
use App\Models\ShippingOffer;

it('purges spent and abandoned offers past the retention window', function (): void {
    ShippingOffer::factory()->create(['created_at' => now()->subDays(30)]);
    ShippingOffer::factory()->consumed()->create(['created_at' => now()->subDays(30)]);
    ShippingOffer::factory()->create(['created_at' => now()->subDay()]);

    $this->artisan('data:purge')->assertSuccessful();

    expect(ShippingOffer::count())->toBe(1);
});

it('never purges an offer spent with no confirmed purchase', function (): void {
    ShippingOffer::factory()->awaitingConfirmation()->create(['created_at' => now()->subYear()]);

    // The row is the only evidence that a label may exist at the source which
    // we never recorded. Age is not a reason to destroy that answer.
    $this->artisan('data:purge')
        ->expectsOutputToContain('Kept 1 consumed shipping offer(s) with no confirmed purchase.')
        ->assertSuccessful();

    expect(ShippingOffer::count())->toBe(1);
});

it('warns only about the unresolved offers nobody can still ask about', function (): void {
    // A channel purchase with no reply: nothing here can ask, so it is a
    // real unknown.
    ShippingOffer::factory()->awaitingConfirmation()->create(['created_at' => now()->subYear()]);
    // A direct-carrier purchase that was asked about and got no usable
    // answer: also a real unknown.
    ShippingOffer::factory()->direct()->awaitingConfirmation()->create(['created_at' => now()->subYear(), 'recovery_unanswered_at' => now()->subMonth()]);
    // A direct-carrier purchase nobody has retried: the next Ship attempt on
    // its package asks USPS, so it is kept but not shouted about.
    ShippingOffer::factory()->direct()->awaitingConfirmation()->create(['created_at' => now()->subYear()]);

    $this->artisan('data:purge')
        ->expectsOutputToContain('Kept 2 consumed shipping offer(s) with no confirmed purchase.')
        ->expectsOutputToContain('Kept 1 direct-carrier offer(s) whose purchase got no reply and has not been retried')
        ->assertSuccessful();

    expect(ShippingOffer::count())->toBe(3);
});

it('purges an offer the source declined, which resolved nothing to recover', function (): void {
    ShippingOffer::factory()->declined()->create(['created_at' => now()->subDays(30)]);

    $this->artisan('data:purge')->assertSuccessful();

    expect(ShippingOffer::count())->toBe(0);
});

it('honours a retention of zero as keep forever', function (): void {
    Setting::updateOrCreate(
        ['key' => 'shipping_offer_retention_days'],
        ['value' => '0', 'type' => 'integer', 'group' => 'system'],
    );
    ShippingOffer::factory()->create(['created_at' => now()->subYears(2)]);

    $this->artisan('data:purge')->assertSuccessful();

    expect(ShippingOffer::count())->toBe(1);
});

it('keeps observed services on a separate clock from offers', function (): void {
    $service = ObservedService::factory()->create([
        'created_at' => now()->subYears(2),
        'first_seen_at' => now()->subYears(2),
        'last_seen_at' => now()->subYears(2),
    ]);

    $this->artisan('data:purge')->assertSuccessful();

    expect($service->fresh())->not->toBeNull();
});
