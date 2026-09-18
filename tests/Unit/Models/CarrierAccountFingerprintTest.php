<?php

use App\Models\CarrierAccount;

/**
 * `CarrierAccount::fingerprint()` — the billing identity a direct offer is
 * bound to, and nothing that rotates.
 */
it('changes when a billing credential changes', function (): void {
    $account = CarrierAccount::factory()->fedex()->create(['credentials' => ['account_number' => '510087720']]);
    $before = $account->fingerprint();

    $account->mergeCredential('account_number', 'A1B2C3');
    $account->save();

    expect($account->fresh()->fingerprint())->not->toBe($before);
});

it('changes when the carrier changes', function (): void {
    $usps = CarrierAccount::factory()->usps()->create();
    $fedex = CarrierAccount::factory()->fedex()->create(['credentials' => $usps->credentials]);

    expect($fedex->fingerprint())->not->toBe($usps->fingerprint());
});

it('ignores secrets, so a token refresh or a rotated client secret keeps the quote', function (): void {
    $account = CarrierAccount::factory()->fedex()->create(['credentials' => ['account_number' => '510087720']]);
    $before = $account->fingerprint();

    $account->mergeSecret('oauth_token', 'refreshed');
    $account->mergeSecret('client_secret', 'rotated');
    $account->save();

    expect($account->fresh()->fingerprint())->toBe($before);
});

it('is stable across the order credentials were stored in', function (): void {
    $one = CarrierAccount::factory()->create(['credentials' => ['account_number' => '123', 'hub_id' => '5531', 'nested' => ['b' => 2, 'a' => 1]]]);
    $other = CarrierAccount::factory()->create(['carrier_id' => $one->carrier_id, 'credentials' => ['nested' => ['a' => 1, 'b' => 2], 'hub_id' => '5531', 'account_number' => '123']]);

    expect($one->fingerprint())->toBe($other->fingerprint());
});
