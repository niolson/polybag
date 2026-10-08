<?php

use App\DataTransferObjects\Customs\ResolvedCustomsTerms;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\Enums\CustomsTermsOrigin;
use App\Enums\DutiesTerms;
use App\Enums\TaxRegistrationRegime;
use App\Models\Client;
use App\Models\ClientTaxRegistration;
use App\Models\ExchangeRate;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Services\Customs\CustomsTermsResolver;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

/**
 * `international-customs-terms/04`: one answer per Shipment and destination,
 * from the order, then the client (PRD *Resolution*). Rates are synthetic and
 * round: one euro is 1.25 USD, 0.80 GBP, 12.50 NOK and 1.60 AUD, so $1 is
 * €0.80, £0.64, NOK 10 and AUD 1.28.
 */
const RESOLVER_ORDER_DATE = '2026-10-07 18:00:00';

beforeEach(function (): void {
    foreach (['USD' => 1.25, 'GBP' => 0.80, 'NOK' => 12.50, 'AUD' => 1.60] as $currency => $rate) {
        ExchangeRate::factory()->quoting($currency, $rate, '2026-10-07')->create();
    }
});

function resolverDestination(string $country, string $postalCode = '10115'): AddressData
{
    return new AddressData(
        firstName: 'Test',
        lastName: 'Recipient',
        streetAddress: '1 Example Street',
        city: 'Example City',
        stateOrProvince: null,
        postalCode: $postalCode,
        country: $country,
    );
}

/**
 * @param  array<string, mixed>  $attributes
 */
function resolverShipment(Client $client, array $attributes = []): Shipment
{
    $shipment = Shipment::factory()->create($attributes + ['client_id' => $client->id, 'country' => 'DE']);
    $shipment->forceFill(['created_at' => RESOLVER_ORDER_DATE])->save();

    return $shipment->fresh();
}

/**
 * @param  list<array{0: float, 1?: int}>  $lines  Unit value in USD and quantity, per line
 * @return list<CustomsItem>
 */
function resolverLines(array $lines): array
{
    return array_map(
        fn (array $line, int $index): CustomsItem => new CustomsItem(
            description: 'Line '.($index + 1),
            quantity: $line[1] ?? 1,
            unitValue: $line[0],
            weight: 0.5,
            countryOfOrigin: 'US',
        ),
        $lines,
        array_keys($lines),
    );
}

/**
 * @param  list<array{0: float, 1?: int}>  $lines
 */
function resolveFor(Shipment $shipment, AddressData $destination, array $lines = [[20.0]], ?AddressData $origin = null): ResolvedCustomsTerms
{
    return app(CustomsTermsResolver::class)->resolve($shipment, $origin ?? AddressData::fromConfig(), $destination, resolverLines($lines));
}

/**
 * Collects warnings logged from here on; call the result to read them.
 *
 * @return Closure(): list<string>
 */
function captureWarnings(): Closure
{
    $warnings = [];

    Log::listen(function (MessageLogged $event) use (&$warnings): void {
        if ($event->level === 'warning') {
            $warnings[] = $event->message;
        }
    });

    return function () use (&$warnings): array {
        return $warnings;
    };
}

describe('the duties term', function (): void {
    it('takes the order term over the client policy, in either direction', function (): void {
        $client = Client::factory()->ddpToEu()->create();

        $terms = resolveFor(resolverShipment($client, ['duties_terms' => DutiesTerms::Ddu]), resolverDestination('DE'));

        expect($terms->dutiesTerms)->toBe(DutiesTerms::Ddu)
            ->and($terms->dutiesTermsOrigin)->toBe(CustomsTermsOrigin::Order);

        $client = Client::factory()->withDutiesPolicy(['EU' => 'ddu'])->create();
        $terms = resolveFor(resolverShipment($client, ['duties_terms' => DutiesTerms::Ddp]), resolverDestination('DE'));

        expect($terms->dutiesTerms)->toBe(DutiesTerms::Ddp)
            ->and($terms->dutiesTermsOrigin)->toBe(CustomsTermsOrigin::Order);
    });

    it('takes the most specific client entry over the EU one', function (): void {
        $client = Client::factory()->withDutiesPolicy(['EU' => 'ddp', 'PL' => 'ddu'])->create();
        $shipment = resolverShipment($client);

        expect(resolveFor($shipment, resolverDestination('PL'))->dutiesTerms)->toBe(DutiesTerms::Ddu)
            ->and(resolveFor($shipment, resolverDestination('DE'))->dutiesTerms)->toBe(DutiesTerms::Ddp)
            ->and(resolveFor($shipment, resolverDestination('DE'))->dutiesTermsOrigin)->toBe(CustomsTermsOrigin::Client);
    });

    it('ships DDU outside the EU when nothing is set, and a country entry overrides that', function (): void {
        $shipment = resolverShipment(Client::factory()->create(['duties_policy' => null]));

        $terms = resolveFor($shipment, resolverDestination('JP'));

        expect($terms->dutiesTerms)->toBe(DutiesTerms::Ddu)
            ->and($terms->dutiesTermsOrigin)->toBe(CustomsTermsOrigin::Default)
            ->and($terms->isUnresolved())->toBeFalse();

        $shipment = resolverShipment(Client::factory()->withDutiesPolicy(['GB' => 'ddp'])->create());

        expect(resolveFor($shipment, resolverDestination('GB', 'SW1A 1AA'))->dutiesTerms)->toBe(DutiesTerms::Ddp);
    });

    it('leaves an EU destination unresolved when neither the order nor the client chose', function (): void {
        $shipment = resolverShipment(Client::factory()->withDutiesPolicy(['GB' => 'ddp'])->create());

        $terms = resolveFor($shipment, resolverDestination('FR'));

        expect($terms->applies)->toBeTrue()
            ->and($terms->dutiesTerms)->toBeNull()
            ->and($terms->isUnresolved())->toBeTrue();
    });

    it('resolves a domestic or same-customs-zone label to nothing', function (): void {
        $shipment = resolverShipment(Client::factory()->create(), ['country' => 'US']);

        $terms = resolveFor($shipment, new AddressData('A', 'B', '1 Main St', 'Seattle', 'WA', '98101', 'US'));

        expect($terms->applies)->toBeFalse()
            ->and($terms->dutiesTerms)->toBeNull()
            ->and($terms->isUnresolved())->toBeFalse()
            ->and($terms->registration)->toBeNull();
    });

    it('records a source-decided purchase as such, declaring nothing', function (): void {
        $client = Client::factory()->ddpToEu()->withIossRegistration()->create();

        $terms = resolveFor(resolverShipment($client), resolverDestination('DE'))->asSourceDecided();

        expect($terms->dutiesTermsOrigin)->toBe(CustomsTermsOrigin::SourceDecided)
            ->and($terms->dutiesTerms)->toBeNull()
            ->and($terms->registration)->toBeNull()
            ->and($terms->isUnresolved())->toBeFalse();
    });
});

describe('the seller tax registration', function (): void {
    it('uses the client registration for the regime that covers the destination', function (): void {
        $client = Client::factory()->ddpToEu()->withIossRegistration()->create();

        $terms = resolveFor(resolverShipment($client), resolverDestination('DE'), [[50.0, 2]]);

        expect($terms->registration?->regime)->toBe(TaxRegistrationRegime::Ioss)
            ->and($terms->registration?->number)->toBe('IM0000000001')
            ->and($terms->registration?->origin)->toBe(CustomsTermsOrigin::Client)
            ->and($terms->overThreshold)->toBeFalse()
            ->and($terms->convertedValue?->amount)->toBe(80.0)
            ->and($terms->convertedValue?->currency)->toBe('EUR');

        expect(resolveFor(resolverShipment($client), resolverDestination('JP'))->registration)->toBeNull();
    });

    it('replaces the client registration with the order one, never merging them', function (): void {
        $client = Client::factory()->ddpToEu()->withIossRegistration()->create();
        $shipment = resolverShipment($client, [
            'seller_tax_regime' => TaxRegistrationRegime::Ioss,
            'seller_tax_number' => 'IM0000000002',
        ]);

        $terms = resolveFor($shipment, resolverDestination('DE'));

        expect($terms->registration?->number)->toBe('IM0000000002')
            ->and($terms->registration?->origin)->toBe(CustomsTermsOrigin::Order);
    });

    it('ignores an order registration whose regime does not cover the destination', function (): void {
        $client = Client::factory()->ddpToEu()->withIossRegistration()->create();
        $shipment = resolverShipment($client, [
            'seller_tax_regime' => TaxRegistrationRegime::UkVat,
            'seller_tax_number' => 'GB000000001',
        ]);

        $terms = resolveFor($shipment, resolverDestination('DE'));

        expect($terms->registration?->regime)->toBe(TaxRegistrationRegime::Ioss)
            ->and($terms->registration?->origin)->toBe(CustomsTermsOrigin::Client);

        $withoutClientRegistration = resolverShipment(Client::factory()->ddpToEu()->create(), [
            'seller_tax_regime' => TaxRegistrationRegime::Voec,
            'seller_tax_number' => '0000001',
        ]);

        expect(resolveFor($withoutClientRegistration, resolverDestination('DE'))->registration)->toBeNull();
    });

    it('sends no IOSS registration for a consignment over €150, and says so', function (): void {
        $client = Client::factory()->ddpToEu()->withIossRegistration()->create();

        // $100 x 2 = $200 = €160.
        $over = resolveFor(resolverShipment($client), resolverDestination('DE'), [[100.0, 2]]);
        // $187.50 = €150 exactly: "not exceeding" €150 still qualifies.
        $atLimit = resolveFor(resolverShipment($client), resolverDestination('DE'), [[187.5]]);

        expect($over->overThreshold)->toBeTrue()
            ->and($over->registration)->toBeNull()
            ->and($over->applicableRegistration?->regime)->toBe(TaxRegistrationRegime::Ioss)
            ->and($over->registrationWithheld())->toBeTrue()
            ->and($over->convertedValue?->amount)->toBe(160.0)
            ->and($over->threshold)->toBe(150)
            ->and($atLimit->overThreshold)->toBeFalse()
            ->and($atLimit->registration)->not->toBeNull();
    });

    it('sends no UK VAT registration for a consignment over £135', function (): void {
        $client = Client::factory()->create();
        ClientTaxRegistration::factory()->ukVat()->for($client)->create();

        // $220 = £140.80.
        $over = resolveFor(resolverShipment($client), resolverDestination('GB', 'SW1A 1AA'), [[110.0, 2]]);
        // $200 = £128.
        $under = resolveFor(resolverShipment($client), resolverDestination('GB', 'SW1A 1AA'), [[200.0]]);

        expect($over->registration)->toBeNull()
            ->and($over->overThreshold)->toBeTrue()
            ->and($over->convertedValue?->currency)->toBe('GBP')
            ->and($under->registration?->regime)->toBe(TaxRegistrationRegime::UkVat);
    });

    it('prefers IOSS for Northern Ireland at £135, else UK VAT', function (): void {
        $both = Client::factory()->withIossRegistration()->create();
        ClientTaxRegistration::factory()->ukVat()->for($both)->create();
        $belfast = resolverDestination('GB', 'BT1 5GS');

        // $200 = £128, under £135, though €160 would be over IOSS's own €150.
        $terms = resolveFor(resolverShipment($both), $belfast, [[200.0]]);

        expect($terms->registration?->regime)->toBe(TaxRegistrationRegime::Ioss)
            ->and($terms->threshold)->toBe(135)
            ->and($terms->thresholdCurrency)->toBe('GBP')
            ->and($terms->convertedValue?->amount)->toBe(128.0);

        // $220 = £140.80: IOSS no longer applies, and nothing is sent.
        expect(resolveFor(resolverShipment($both), $belfast, [[220.0]])->registration)->toBeNull();

        // The rest of GB still gets UK VAT.
        expect(resolveFor(resolverShipment($both), resolverDestination('GB', 'SW1A 1AA'))->registration?->regime)
            ->toBe(TaxRegistrationRegime::UkVat);

        $ukVatOnly = Client::factory()->create();
        ClientTaxRegistration::factory()->ukVat()->for($ukVatOnly)->create();

        expect(resolveFor(resolverShipment($ukVatOnly), $belfast)->registration?->regime)->toBe(TaxRegistrationRegime::UkVat);
    });

    it('reports each VOEC line with an item at NOK 3,000 or more, and still declares the registration', function (): void {
        $client = Client::factory()->create();
        ClientTaxRegistration::factory()->voec()->for($client)->create();

        // $300 = NOK 3,000 (over: VOEC applies under 3,000); $299 = NOK 2,990,
        // and two of them total far more than 3,000 but each item is under.
        $terms = resolveFor(resolverShipment($client), resolverDestination('NO', '0150'), [[300.0], [299.0, 2]]);

        expect($terms->registration?->regime)->toBe(TaxRegistrationRegime::Voec)
            ->and($terms->overThreshold)->toBeFalse()
            ->and($terms->linesOverLimit)->toHaveCount(1)
            ->and($terms->linesOverLimit[0]->line)->toBe(0)
            ->and($terms->linesOverLimit[0]->convertedUnitValue)->toBe(3000.0)
            ->and($terms->linesOverLimit[0]->currency)->toBe('NOK');
    });

    it('reports each ARN line with an item over AUD 1,000', function (): void {
        $client = Client::factory()->create();
        ClientTaxRegistration::factory()->arn()->for($client)->create();

        // $800 = AUD 1,024; $781.25 = AUD 1,000, which still qualifies.
        $terms = resolveFor(resolverShipment($client), resolverDestination('AU', '2000'), [[781.25], [800.0]]);

        expect($terms->registration?->regime)->toBe(TaxRegistrationRegime::Arn)
            ->and($terms->linesOverLimit)->toHaveCount(1)
            ->and($terms->linesOverLimit[0]->line)->toBe(1)
            ->and($terms->linesOverLimit[0]->convertedUnitValue)->toBe(1024.0);
    });
});

describe('the exchange rate', function (): void {
    it('converts at the rate for the order date, falling back to the latest earlier day', function (): void {
        ExchangeRate::factory()->quoting('USD', 1.0, '2026-10-09')->create();
        $client = Client::factory()->ddpToEu()->withIossRegistration()->create();

        $onTheDay = resolverShipment($client);
        // A Saturday: the ECB published nothing, so Friday's rate stands.
        $weekend = resolverShipment($client);
        $weekend->forceFill(['created_at' => '2026-10-10 12:00:00'])->save();

        $terms = resolveFor($onTheDay, resolverDestination('DE'), [[160.0]]);
        $weekendTerms = resolveFor($weekend->fresh(), resolverDestination('DE'), [[160.0]]);

        // $160 at 1.25 is €128; at Friday's 1.00 it is €160, over €150.
        expect($terms->convertedValue?->amount)->toBe(128.0)
            ->and($terms->convertedValue?->rateDate->toDateString())->toBe('2026-10-07')
            ->and($terms->registration)->not->toBeNull()
            ->and($weekendTerms->convertedValue?->rateDate->toDateString())->toBe('2026-10-09')
            ->and($weekendTerms->overThreshold)->toBeTrue()
            ->and($weekendTerms->registration)->toBeNull();
    });

    it('sends no registration and logs a warning when no rate is stored on or before the order date', function (): void {
        $warnings = [];
        Log::listen(function (MessageLogged $event) use (&$warnings): void {
            if ($event->level === 'warning') {
                $warnings[] = $event->message;
            }
        });
        $client = Client::factory()->ddpToEu()->withIossRegistration()->create();
        $shipment = resolverShipment($client);
        $shipment->forceFill(['created_at' => '2026-09-01 12:00:00'])->save();

        $terms = resolveFor($shipment->fresh(), resolverDestination('DE'), [[10.0]]);

        expect($terms->exchangeRateMissing)->toBeTrue()
            ->and($terms->overThreshold)->toBeTrue()
            ->and($terms->registration)->toBeNull()
            ->and($terms->applicableRegistration)->not->toBeNull()
            ->and($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('No ECB exchange rate');
    });
});

describe('the customs border', function (): void {
    it('resolves a parcel inside the EU to nothing, as it crosses no customs border', function (): void {
        $shipment = resolverShipment(Client::factory()->ddpToEu()->withIossRegistration()->create());

        $terms = resolveFor($shipment, resolverDestination('FR', '75001'), origin: resolverDestination('DE'));

        expect($terms->applies)->toBeFalse()
            ->and($terms->dutiesTerms)->toBeNull()
            ->and($terms->registration)->toBeNull()
            ->and($terms->isUnresolved())->toBeFalse();

        $unset = resolverShipment(Client::factory()->create(['duties_policy' => null]));

        expect(resolveFor($unset, resolverDestination('FR', '75001'), origin: resolverDestination('DE'))->isUnresolved())->toBeFalse();
    });

    it('resolves a parcel leaving the EU by its destination', function (): void {
        $shipment = resolverShipment(Client::factory()->ddpToEu()->create(), ['country' => 'US']);

        $terms = resolveFor($shipment, new AddressData('A', 'B', '1 Main St', 'Seattle', 'WA', '98101', 'US'), origin: resolverDestination('DE'));

        expect($terms->applies)->toBeTrue()
            ->and($terms->dutiesTerms)->toBe(DutiesTerms::Ddu)
            ->and($terms->dutiesTermsOrigin)->toBe(CustomsTermsOrigin::Default);
    });

    it('still resolves a parcel into the EU from outside it', function (): void {
        $shipment = resolverShipment(Client::factory()->ddpToEu()->create());

        expect(resolveFor($shipment, resolverDestination('DE'))->dutiesTerms)->toBe(DutiesTerms::Ddp)
            ->and(resolveFor($shipment, resolverDestination('DE'), origin: resolverDestination('GB', 'SW1A 1AA'))->dutiesTerms)->toBe(DutiesTerms::Ddp);
    });
});

describe('rate warnings', function (): void {
    it('warns once when the rate used is more than five days older than the order', function (): void {
        $warnings = captureWarnings();
        $client = Client::factory()->ddpToEu()->withIossRegistration()->create();
        $shipment = resolverShipment($client);
        // Ten days after the only stored rates: a fetch that stopped.
        $shipment->forceFill(['created_at' => '2026-10-17 18:00:00'])->save();

        $first = resolveFor($shipment->fresh(), resolverDestination('DE'));
        resolveFor($shipment->fresh(), resolverDestination('DE'));

        expect($first->convertedValue?->rateDate->toDateString())->toBe('2026-10-07')
            ->and($first->registration)->not->toBeNull()
            ->and($warnings())->toHaveCount(1)
            ->and($warnings()[0])->toContain('more than 5 days older');
    });

    it('does not warn for a rate a weekend old', function (): void {
        $warnings = captureWarnings();
        $shipment = resolverShipment(Client::factory()->ddpToEu()->withIossRegistration()->create());
        $shipment->forceFill(['created_at' => '2026-10-11 12:00:00'])->save();

        resolveFor($shipment->fresh(), resolverDestination('DE'));

        expect($warnings())->toBe([]);
    });

    it('logs a missing rate once however often the terms are resolved', function (): void {
        $warnings = captureWarnings();
        $shipment = resolverShipment(Client::factory()->ddpToEu()->withIossRegistration()->create());
        $shipment->forceFill(['created_at' => '2026-09-01 12:00:00'])->save();

        foreach (range(1, 3) as $ignored) {
            resolveFor($shipment->fresh(), resolverDestination('DE'));
        }

        expect($warnings())->toHaveCount(1);
    });
});

it('resolves a Package from its own customs lines and the shipment it belongs to', function (): void {
    $client = Client::factory()->ddpToEu()->withIossRegistration()->create();
    $shipment = resolverShipment($client, ['country' => 'DE', 'postal_code' => '10115', 'state_or_province' => null]);
    $package = Package::factory()->for($shipment)->create();
    $product = Product::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'value' => 100.0,
    ]);
    $package->packageItems()->create(['shipment_item_id' => $shipmentItem->id, 'product_id' => $product->id, 'quantity' => 2]);

    $terms = app(CustomsTermsResolver::class)->forPackage($package->fresh());

    expect($terms->consignmentValue)->toBe(200.0)
        ->and($terms->dutiesTerms)->toBe(DutiesTerms::Ddp)
        ->and($terms->overThreshold)->toBeTrue()
        ->and($terms->registration)->toBeNull();
});
