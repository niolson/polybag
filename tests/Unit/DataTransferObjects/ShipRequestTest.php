<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\AmazonChannelType;
use App\Enums\PostageSource;
use App\Models\ShippingOffer;
use App\Services\AmazonBuyShippingService;

/*
|--------------------------------------------------------------------------
| EU product identifiers — eu-product-identifiers/04
|--------------------------------------------------------------------------
|
| An EU consumer label is refused while any line lacks the merchant or the
| manufacturer identifier, wherever our declaration is actually sent.
|
*/

function warehouseIn(string $country): AddressData
{
    return $country === 'DE'
        ? new AddressData('Shipping', 'Center', 'Lagerstrasse 5', 'Hamburg', null, '20095', 'DE')
        : new AddressData('Shipping', 'Center', '123 Warehouse St', 'Seattle', 'WA', '98072');
}

function consigneeIn(string $country, ?string $company = null): AddressData
{
    return match ($country) {
        'FR' => new AddressData('Camille', 'Martin', '10 Rue de Rivoli', 'Paris', null, '75001', 'FR', company: $company),
        'DE' => new AddressData('Anna', 'Schmidt', 'Hauptstrasse 1', 'Berlin', null, '10115', 'DE', company: $company),
        'CA' => new AddressData('Jean', 'Tremblay', '100 Queen St W', 'Toronto', 'ON', 'M5H 2N2', 'CA', company: $company),
        default => throw new InvalidArgumentException("No test consignee in {$country}."),
    };
}

function lineIdentifiedBy(?string $sku, ?string $mpn, ?string $gtin = '01234567890128', string $description = 'Ceramic Mug'): CustomsItem
{
    return new CustomsItem(
        description: $description,
        quantity: 1,
        unitValue: 12.0,
        weight: 0.8,
        merchantProductId: $sku,
        manufacturerProductId: $mpn,
        standardProductId: $gtin,
    );
}

/**
 * @param  list<CustomsItem>  $customsItems
 */
function shipRequestFor(AddressData $to, array $customsItems, string $originCountry = 'US', ?BlindPurchaseOffer $blindOffer = null, ?ShippingOffer $offer = null): ShipRequest
{
    return new ShipRequest(
        fromAddress: warehouseIn($originCountry),
        toAddress: $to,
        packageData: new PackageData(weight: 2.0, length: 8, width: 6, height: 4),
        selectedRate: new RateResponse('FedEx', 'INTERNATIONAL_PRIORITY', 'International Priority', 48.10),
        customsItems: $customsItems,
        blindOffer: $blindOffer,
        offer: $offer,
    );
}

it('lists a line with no manufacturer part number on an EU consumer label', function (): void {
    $incomplete = lineIdentifiedBy('SKU-1', null);

    $request = shipRequestFor(consigneeIn('FR'), [lineIdentifiedBy('SKU-2', 'MFG-2'), $incomplete]);

    expect($request->customsItemsMissingProductIdentifiers())->toBe([$incomplete]);
});

it('lists a line whose product has no SKU on an EU consumer label', function (): void {
    $incomplete = lineIdentifiedBy(null, 'MFG-1');

    expect(shipRequestFor(consigneeIn('FR'), [$incomplete])->customsItemsMissingProductIdentifiers())->toBe([$incomplete]);
});

it('lists nothing for a business consignee in the EU', function (): void {
    expect(shipRequestFor(consigneeIn('FR', 'Maison Martin SARL'), [lineIdentifiedBy('SKU-1', null)])->customsItemsMissingProductIdentifiers())
        ->toBe([]);
});

it('treats a blank company as a consumer', function (): void {
    expect(shipRequestFor(consigneeIn('FR', '  '), [lineIdentifiedBy('SKU-1', null)])->customsItemsMissingProductIdentifiers())
        ->toHaveCount(1);
});

it('lists nothing for a consumer outside the EU', function (): void {
    expect(shipRequestFor(consigneeIn('CA'), [lineIdentifiedBy('SKU-1', null)])->customsItemsMissingProductIdentifiers())
        ->toBe([]);
});

it('lists nothing when every line carries both required identifiers', function (): void {
    expect(shipRequestFor(consigneeIn('FR'), [lineIdentifiedBy('SKU-1', 'MFG-1'), lineIdentifiedBy('SKU-2', 'MFG-2')])->customsItemsMissingProductIdentifiers())
        ->toBe([]);
});

it('never requires the standard identifier', function (): void {
    expect(shipRequestFor(consigneeIn('FR'), [lineIdentifiedBy('SKU-1', 'MFG-1', gtin: null)])->customsItemsMissingProductIdentifiers())
        ->toBe([]);
});

it('lists nothing for a blind purchase, whose seller declares from its own catalog', function (): void {
    $request = shipRequestFor(consigneeIn('FR'), [lineIdentifiedBy('SKU-1', null)], blindOffer: new BlindPurchaseOffer(
        source: 'Shopify',
        sourceLabel: 'Shopify Shipping',
        serviceCode: 'auto',
        selectionLabel: "Shopify's choice",
    ));

    expect($request->customsItemsMissingProductIdentifiers())->toBe([]);
});

/**
 * An Amazon offer as its adapter stores it, quoted on the given channel.
 */
function amazonOfferOn(AmazonChannelType $channel): ShippingOffer
{
    return new ShippingOffer([
        'postage_source' => PostageSource::PostageDataSource,
        'purchase_context' => [AmazonBuyShippingService::CHANNEL_TYPE_KEY => $channel->value],
    ]);
}

it('lists nothing for an Amazon Buy Shipping offer, whose request has nowhere to carry an identifier', function (): void {
    expect(shipRequestFor(consigneeIn('FR'), [lineIdentifiedBy('SKU-1', null)], offer: amazonOfferOn(AmazonChannelType::Amazon))->customsItemsMissingProductIdentifiers())
        ->toBe([]);
});

it('still lists a line on Amazon Shipping sold to another channel\'s order, which is a direct rate', function (): void {
    $incomplete = lineIdentifiedBy('SKU-1', null);

    expect(shipRequestFor(consigneeIn('FR'), [$incomplete], offer: amazonOfferOn(AmazonChannelType::External))->customsItemsMissingProductIdentifiers())
        ->toBe([$incomplete]);
});

it('still refuses a zero-value line on an Amazon Buy Shipping offer, whose item values Amazon declares', function (): void {
    $free = new CustomsItem(description: 'Sample', quantity: 1, unitValue: 0.0, weight: 0.2);

    expect(shipRequestFor(consigneeIn('FR'), [$free], offer: amazonOfferOn(AmazonChannelType::Amazon))->zeroValueCustomsItems())
        ->toBe([$free]);
});

it('lists nothing for a label inside one customs zone, which carries no declaration', function (): void {
    expect(shipRequestFor(consigneeIn('DE'), [lineIdentifiedBy('SKU-1', null)], originCountry: 'DE')->customsItemsMissingProductIdentifiers())
        ->toBe([]);
});

it('lists nothing for a label from one EU country to a consumer in another, which never enters the EU', function (): void {
    expect(shipRequestFor(consigneeIn('FR'), [lineIdentifiedBy('SKU-1', null)], originCountry: 'DE')->customsItemsMissingProductIdentifiers())
        ->toBe([]);
});
