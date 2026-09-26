<?php

use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\DataSource;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| carrier-catalog-reset/15 — connection scopes move to Amazon Shipping
|--------------------------------------------------------------------------
|
| The scopes that choose which Amazon connection sells Amazon Shipping to
| other channels sat on the `Amazon` row. They move to the Amazon Shipping
| carrier, so the `Amazon` row's cascade cannot delete them in `12`.
|
*/

function offAmazonScopeMigration(): object
{
    return require database_path('migrations/2026_09_26_051234_move_off_amazon_scopes_to_amazon_shipping.php');
}

/**
 * A connection scope left on the `Amazon` row, as installs have it before
 * this migration. Written directly, because the model now derives Amazon
 * Shipping.
 */
function legacyConnectionScope(DataSource $connection, Carrier $amazon): int
{
    return DB::table('carrier_account_scopes')->insertGetId([
        'data_source_id' => $connection->id,
        'carrier_id' => $amazon->id,
        'rate_shop' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('moves every connection scope onto Amazon Shipping and leaves account scopes alone', function (): void {
    $amazon = Carrier::seedSystem(AmazonBuyShippingAdapter::SOURCE_NAME);
    $amazonShipping = Carrier::seedSystem(Carrier::AMAZON_SHIPPING);
    $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
    $scopeId = legacyConnectionScope($connection, $amazon);
    $accountScope = CarrierAccountScope::create(['carrier_account_id' => CarrierAccount::factory()->usps()->create()->id]);

    offAmazonScopeMigration()->up();

    expect(DB::table('carrier_account_scopes')->where('id', $scopeId)->value('carrier_id'))->toBe($amazonShipping->id)
        ->and($accountScope->refresh()->carrier->name)->toBe(Carrier::USPS)
        ->and(DataSource::resolveOffAmazonShipping(null, null)?->id)->toBe($connection->id);
});

it('creates Amazon Shipping when the reference-data sync has not yet seeded it', function (): void {
    $amazon = Carrier::seedSystem(AmazonBuyShippingAdapter::SOURCE_NAME);
    $scopeId = legacyConnectionScope(DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create(), $amazon);

    offAmazonScopeMigration()->up();

    $amazonShipping = Carrier::where('name', Carrier::AMAZON_SHIPPING)->sole();

    expect($amazonShipping->is_system)->toBeTrue()
        ->and(DB::table('carrier_account_scopes')->where('id', $scopeId)->value('carrier_id'))->toBe($amazonShipping->id)
        ->and(Carrier::seedSystem(Carrier::AMAZON_SHIPPING)->id)->toBe($amazonShipping->id);
});

it('does nothing on an install with no connection scopes', function (): void {
    Carrier::seedSystem(AmazonBuyShippingAdapter::SOURCE_NAME);

    offAmazonScopeMigration()->up();

    expect(Carrier::where('name', Carrier::AMAZON_SHIPPING)->exists())->toBeFalse();
});
