<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\CustomsDocumentDelivery;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\FakeCarrierAdapter;
use App\Services\Carriers\FedexAdapter;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\Carriers\UpsAdapter;
use App\Services\Carriers\UspsAdapter;

/**
 * The per-carrier rows of shopify-shipping-carrier/23, as the report printer
 * gate reads them. Each is an observation recorded there: USPS fuses its CP72
 * into the label, UPS and Shopify return a separate Letter invoice, FedEx
 * returns nothing because nothing is requested, and Amazon declares it per
 * offering.
 */
function customsLaneAddress(string $country, string $state = 'WA'): AddressData
{
    return new AddressData(
        firstName: 'Test',
        lastName: 'Person',
        streetAddress: '1 Main St',
        city: 'Somewhere',
        stateOrProvince: $state,
        postalCode: '00000',
        country: $country,
    );
}

it('answers per carrier for a lane that clears customs', function (string $adapterClass, CustomsDocumentDelivery $expected): void {
    $adapter = new $adapterClass;

    expect($adapter->customsDocumentDelivery(customsLaneAddress('US'), customsLaneAddress('CA', 'ON')))->toBe($expected);
})->with([
    'USPS fuses the CP72 into the label' => [UspsAdapter::class, CustomsDocumentDelivery::FusedIntoLabel],
    'UPS returns ShipmentResults.Form beside the label' => [UpsAdapter::class, CustomsDocumentDelivery::Separate],
    'FedEx returns nothing because nothing is requested' => [FedexAdapter::class, CustomsDocumentDelivery::NotRequested],
    'Shopify returns a separate CUSTOMS_FORM' => [ShopifyAdapter::class, CustomsDocumentDelivery::Separate],
]);

it('answers none for a lane inside one customs zone, whatever the carrier', function (string $adapterClass): void {
    $adapter = new $adapterClass;

    expect($adapter->customsDocumentDelivery(customsLaneAddress('US'), customsLaneAddress('US', 'CA')))->toBe(CustomsDocumentDelivery::None)
        // Canada to Canada is domestic for a Canadian account, whatever the country code.
        ->and($adapter->customsDocumentDelivery(customsLaneAddress('CA', 'BC'), customsLaneAddress('CA', 'ON')))->toBe(CustomsDocumentDelivery::None);
})->with([
    UspsAdapter::class,
    UpsAdapter::class,
    FedexAdapter::class,
    ShopifyAdapter::class,
    AmazonBuyShippingAdapter::class,
]);

it('asks the pair, so a territory clears customs on a domestic-priced service', function (): void {
    // The distinction AddressData::requiresCustomsDeclaration() records: Puerto
    // Rico is country US and still crosses a customs boundary.
    $usps = new UspsAdapter;
    $ups = new UpsAdapter;

    expect($usps->customsDocumentDelivery(customsLaneAddress('US'), customsLaneAddress('US', 'PR')))->toBe(CustomsDocumentDelivery::FusedIntoLabel)
        ->and($ups->customsDocumentDelivery(customsLaneAddress('US'), customsLaneAddress('US', 'PR')))->toBe(CustomsDocumentDelivery::Separate);
});

it('reads Amazon off the offering when a rate is in hand', function (): void {
    $adapter = new AmazonBuyShippingAdapter;
    $from = customsLaneAddress('US');
    $to = customsLaneAddress('CA', 'ON');

    $declaring = new RateResponse('Amazon', 'AMAZON_BUY_SHIPPING', 'Ground', 9.99, metadata: [
        'supportedDocumentSpecifications' => [
            ['printOptions' => [['supportedDocumentDetails' => [['name' => 'LABEL'], ['name' => 'CUSTOM_FORM']]]]],
        ],
    ]);
    $silent = new RateResponse('Amazon', 'AMAZON_BUY_SHIPPING', 'Ground', 9.99, metadata: [
        'supportedDocumentSpecifications' => [
            ['printOptions' => [['supportedDocumentDetails' => [['name' => 'LABEL']]]]],
        ],
    ]);

    expect($adapter->customsDocumentDelivery($from, $to, $declaring))->toBe(CustomsDocumentDelivery::Separate)
        // The offering, not the lane: an international offering that declares
        // no form returns none, and the gate must not refuse it.
        ->and($adapter->customsDocumentDelivery($from, $to, $silent))->toBe(CustomsDocumentDelivery::None)
        // Which is what lifts the territory over-block amazon-buy-shipping/09 observed.
        ->and($adapter->customsDocumentDelivery($from, customsLaneAddress('US', 'PR'), $silent))->toBe(CustomsDocumentDelivery::None);
});

it('approximates Amazon from the lane when no rate exists yet', function (): void {
    // Batch validation runs before anything is quoted. A foreign country gets
    // the separate CUSTOM_FORM Amazon states; a territory gets the plain
    // domestic label it was observed to quote.
    $adapter = new AmazonBuyShippingAdapter;
    $from = customsLaneAddress('US');

    expect($adapter->customsDocumentDelivery($from, customsLaneAddress('CA', 'ON')))->toBe(CustomsDocumentDelivery::Separate)
        ->and($adapter->customsDocumentDelivery($from, customsLaneAddress('US', 'PR')))->toBe(CustomsDocumentDelivery::None);
});

it('lets a test choose the fake carrier\'s answer', function (): void {
    $from = customsLaneAddress('US');
    $to = customsLaneAddress('CA', 'ON');

    expect((new FakeCarrierAdapter('USPS'))->customsDocumentDelivery($from, $to))->toBe(CustomsDocumentDelivery::None)
        ->and((new FakeCarrierAdapter('UPS', CustomsDocumentDelivery::Separate))->customsDocumentDelivery($from, $to))->toBe(CustomsDocumentDelivery::Separate);
});

it('needs a report printer only for a separate document', function (): void {
    expect(CustomsDocumentDelivery::Separate->needsReportPrinter())->toBeTrue()
        ->and(CustomsDocumentDelivery::FusedIntoLabel->needsReportPrinter())->toBeFalse()
        ->and(CustomsDocumentDelivery::NotRequested->needsReportPrinter())->toBeFalse()
        ->and(CustomsDocumentDelivery::None->needsReportPrinter())->toBeFalse();
});
