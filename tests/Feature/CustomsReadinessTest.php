<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\Customs\CustomsFinding;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\AmazonChannelType;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\CustomsFindingSeverity;
use App\Enums\DutiesTerms;
use App\Enums\LabelBatchItemStatus;
use App\Enums\PackageStatus;
use App\Enums\RecipientTaxIdType;
use App\Enums\Role;
use App\Enums\ShippingRuleAction;
use App\Filament\Pages\Ship;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ShipmentResource;
use App\Jobs\GenerateLabelJob;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Client;
use App\Models\ClientTaxRegistration;
use App\Models\ExchangeRate;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Location;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingOffer;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Customs\CustomsReadiness;
use App\Services\Customs\CustomsReferenceData;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Livewire\Livewire;

/**
 * `international-customs-terms/05`: one readiness check, authoritative at
 * purchase and previewed on the Ship page. Rates are synthetic and round: one
 * euro is 1.25 USD, 0.80 GBP, 12.50 NOK and 1.60 AUD, so $1 is NOK 10 and
 * AUD 1.28.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    $this->actingAs(User::factory()->admin()->create());

    foreach (['USD' => 1.25, 'GBP' => 0.80, 'NOK' => 12.50, 'AUD' => 1.60] as $currency => $rate) {
        ExchangeRate::factory()->quoting($currency, $rate, now()->subDay()->toDateString())->create();
    }
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * A packed box with one line per entry, bound for a consumer in the country.
 *
 * @param  list<array{value?: float, quantity?: int, hs?: string|null, origin?: string|null, sku?: string, description?: string}>  $lines
 * @param  array<string, mixed>  $shipment
 */
function readinessPackage(string $country, array $lines, array $shipment = [], ?Client $client = null, ?Location $from = null, ?ShippingMethod $method = null): Package
{
    $shipment = Shipment::factory()->create($shipment + [
        'client_id' => $client?->id ?? Client::factory()->create()->id,
        'company' => null,
        'email' => 'recipient@example.test',
        'city' => 'Example City',
        'state_or_province' => null,
        'postal_code' => '10115',
        'country' => $country,
        'shipping_method_id' => $method?->id ?? ShippingMethod::factory()->create()->id,
        // Every EU case here has been given its terms unless it says otherwise.
        'duties_terms' => in_array($country, ['DE', 'FR'], true) ? DutiesTerms::Ddp : null,
    ]);

    $package = Package::factory()->for($shipment)->create(array_filter([
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0,
        'status' => PackageStatus::Unshipped,
        'location_id' => $from?->id,
    ]));

    foreach ($lines as $index => $line) {
        $product = Product::factory()->create([
            'sku' => $line['sku'] ?? 'SKU-'.($index + 1),
            'manufacturer_part_number' => 'MPN-'.($index + 1),
            'description' => $line['description'] ?? 'Item '.($index + 1),
            'hs_tariff_number' => array_key_exists('hs', $line) ? $line['hs'] : '610910',
            'country_of_origin' => array_key_exists('origin', $line) ? $line['origin'] : 'US',
            'weight' => 0.2,
        ]);
        $shipmentItem = ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'quantity' => $line['quantity'] ?? 1,
            'value' => $line['value'] ?? 20.0,
            'transparency' => false,
        ]);
        $package->packageItems()->create([
            'shipment_item_id' => $shipmentItem->id,
            'product_id' => $product->id,
            'quantity' => $line['quantity'] ?? 1,
        ]);
    }

    return $package->fresh();
}

/**
 * @param  list<CustomsFinding>  $findings
 * @return list<string>
 */
function findingCodes(array $findings): array
{
    return array_map(fn (CustomsFinding $finding): string => $finding->code, $findings);
}

function findingNamed(array $findings, string $code): ?CustomsFinding
{
    foreach ($findings as $finding) {
        if ($finding->code === $code) {
            return $finding;
        }
    }

    return null;
}

function readinessFor(Package $package, mixed ...$arguments): array
{
    return app(CustomsReadiness::class)->check($package, ...$arguments);
}

/**
 * Customs reference data read from a directory of the test's own.
 *
 * @param  array<string, mixed>  $exportFiling
 */
function useCustomsReferenceData(array $exportFiling, ?array $recipientTaxId = null): void
{
    $directory = sys_get_temp_dir().'/customs-reference-'.bin2hex(random_bytes(8));
    mkdir($directory);
    file_put_contents($directory.'/export-filing.json', json_encode($exportFiling));
    copy(resource_path('data/customs/recipient-tax-id.json'), $directory.'/recipient-tax-id.json');

    if ($recipientTaxId !== null) {
        file_put_contents($directory.'/recipient-tax-id.json', json_encode($recipientTaxId));
    }

    register_shutdown_function(static function () use ($directory): void {
        array_map('unlink', glob($directory.'/*') ?: []);
        @rmdir($directory);
    });

    app()->instance(CustomsReferenceData::class, new CustomsReferenceData($directory));
    app()->forgetInstance(CustomsReadiness::class);
}

describe('duties terms', function (): void {
    it('blocks an EU Shipment whose terms nobody chose, and names the fix', function (): void {
        $package = readinessPackage('IT', [['value' => 20.0]]);

        $finding = findingNamed(readinessFor($package), 'duties_terms_unresolved');

        expect($finding?->severity)->toBe(CustomsFindingSeverity::Block)
            ->and($finding?->message)->toStartWith('No duties terms are set for Italy or the EU')
            ->and($finding?->fixUrl)->not->toBeNull();
    });

    it('lets an EU Shipment through once the order or the client has chosen', function (): void {
        $ordered = readinessPackage('IT', [['value' => 20.0]], ['duties_terms' => DutiesTerms::Ddu]);
        $client = Client::factory()->ddpToEu()->create();
        $clients = readinessPackage('IT', [['value' => 20.0]], client: $client);

        expect(findingCodes(readinessFor($ordered)))->not->toContain('duties_terms_unresolved')
            ->and(findingCodes(readinessFor($clients)))->not->toContain('duties_terms_unresolved');
    });

    it('does not ask for terms outside the EU or for a source-decided purchase', function (): void {
        $outside = readinessPackage('JP', [['value' => 20.0]]);
        $eu = readinessPackage('IT', [['value' => 20.0]]);
        $blind = new BlindPurchaseOffer(source: 'Shopify', sourceLabel: 'Shopify Shipping', serviceCode: 'auto', selectionLabel: "Shopify's choice");

        expect(findingCodes(readinessFor($outside)))->not->toContain('duties_terms_unresolved')
            ->and(readinessFor($eu, blindOffer: $blind))->toBe([]);
    });
});

describe('country of origin and HS code', function (): void {
    it('blocks a line without a country of origin on any international label', function (): void {
        $package = readinessPackage('JP', [['origin' => null, 'sku' => 'NO-ORIGIN'], ['origin' => 'CN']]);

        $finding = findingNamed(readinessFor($package), 'origin_missing');

        expect($finding?->severity)->toBe(CustomsFindingSeverity::Block)
            ->and($finding?->lines)->toBe(['NO-ORIGIN'])
            ->and($finding?->fixUrl)->toContain('/products/')
            ->and(findingCodes(readinessFor(readinessPackage('JP', [['origin' => 'CN']]))))->not->toContain('origin_missing');
    });

    it('does not ask for an origin on a domestic label', function (): void {
        $package = readinessPackage('US', [['origin' => null]], ['state_or_province' => 'WA', 'postal_code' => '98101']);

        expect(readinessFor($package))->toBe([]);
    });

    it('blocks a missing or short HS code for the EU and the UK, and accepts six digits', function (string $country, string $postalCode): void {
        $short = readinessPackage($country, [['hs' => '61091']], ['postal_code' => $postalCode, 'duties_terms' => DutiesTerms::Ddu]);
        $missing = readinessPackage($country, [['hs' => null]], ['postal_code' => $postalCode, 'duties_terms' => DutiesTerms::Ddu]);
        $six = readinessPackage($country, [['hs' => '6109.10']], ['postal_code' => $postalCode, 'duties_terms' => DutiesTerms::Ddu]);

        expect(findingNamed(readinessFor($short), 'hs_code_missing')?->severity)->toBe(CustomsFindingSeverity::Block)
            ->and(findingCodes(readinessFor($missing)))->toContain('hs_code_missing')
            ->and(findingCodes(readinessFor($six)))->not->toContain('hs_code_missing');
    })->with([
        'Germany' => ['DE', '10115'],
        'United Kingdom' => ['GB', 'SW1A 1AA'],
    ]);

    it('only warns about a missing HS code elsewhere', function (): void {
        $missing = readinessPackage('JP', [['hs' => null]]);
        $present = readinessPackage('JP', [['hs' => '610910']]);

        $finding = findingNamed(readinessFor($missing), 'hs_code_missing_warning');

        expect($finding?->severity)->toBe(CustomsFindingSeverity::Warn)
            ->and(findingCodes(readinessFor($missing)))->not->toContain('hs_code_missing')
            ->and(CustomsReadiness::blocks(readinessFor($missing)))->toBe([])
            ->and(findingCodes(readinessFor($present)))->not->toContain('hs_code_missing_warning');
    });

    it('holds an Amazon Buy Shipping offer to neither, since its declaration is Amazon\'s', function (): void {
        $package = readinessPackage('JP', [['origin' => null, 'hs' => null]]);
        $offer = Mockery::mock(ShippingOffer::class);
        $offer->shouldReceive('amazonChannelType')->andReturn(AmazonChannelType::Amazon);

        expect(readinessFor($package, $offer))->toBe([]);
    });
});

describe('export ITN', function (): void {
    it('blocks a single line over $2,500 with no ITN, and lets it through with one', function (): void {
        $package = readinessPackage('JP', [['value' => 3000.0]]);

        $finding = findingNamed(readinessFor($package), 'export_itn_required');

        expect($finding?->severity)->toBe(CustomsFindingSeverity::Block)
            ->and($finding?->message)->toContain('610910 totals $3,000.00')
            ->and($finding?->fixUrl)->toBe(ShipmentResource::getUrl('edit', ['record' => $package->shipment]));

        $client = Client::factory()->withExporterEin()->create();
        $filed = readinessPackage('JP', [['value' => 3000.0]], ['export_itn' => 'X00000000000001'], $client);

        expect(findingCodes(readinessFor($filed)))->not->toContain('export_itn_required');
    });

    it('measures $2,500 per classification, not per parcel', function (): void {
        $lines = array_map(fn (int $index): array => ['value' => 300.0, 'hs' => '61091'.$index.'00'], range(0, 9));
        $package = readinessPackage('JP', $lines);

        expect($package->packageItems)->toHaveCount(10)
            ->and(findingCodes(readinessFor($package)))->not->toContain('export_itn_required');
    });

    it('sums lines that share a classification', function (): void {
        $package = readinessPackage('JP', array_fill(0, 9, ['value' => 300.0, 'hs' => '6109100012']));

        expect(findingCodes(readinessFor($package)))->toContain('export_itn_required');
    });

    it('does not truncate to six digits, which would merge classifications that differ past the sixth', function (): void {
        $package = readinessPackage('JP', [
            ['value' => 1500.0, 'hs' => '6109100012'],
            ['value' => 1500.0, 'hs' => '6109100099'],
        ]);

        expect(findingCodes(readinessFor($package)))->not->toContain('export_itn_required');
    });

    it('merges a shorter code that prefixes a longer one', function (): void {
        $prefixed = readinessPackage('JP', [
            ['value' => 1500.0, 'hs' => '610910'],
            ['value' => 1500.0, 'hs' => '6109100012'],
        ]);
        $unrelated = readinessPackage('JP', [
            ['value' => 1500.0, 'hs' => '610910'],
            ['value' => 1500.0, 'hs' => '620342'],
        ]);

        expect(findingCodes(readinessFor($prefixed)))->toContain('export_itn_required')
            ->and(findingCodes(readinessFor($unrelated)))->not->toContain('export_itn_required');
    });

    it('requires the ITN when a line has no HS code and the parcel is over $2,500', function (): void {
        $over = readinessPackage('JP', [['value' => 1500.0, 'hs' => null], ['value' => 1500.0, 'hs' => '610910']]);
        $under = readinessPackage('JP', [['value' => 1000.0, 'hs' => null], ['value' => 1000.0, 'hs' => '610910']]);

        $finding = findingNamed(readinessFor($over), 'export_itn_required');

        expect($finding?->message)->toContain('no HS code')
            ->and(findingCodes(readinessFor($under)))->not->toContain('export_itn_required');
    });

    it('exempts Canada and parcels that do not leave the US', function (): void {
        $canada = readinessPackage('CA', [['value' => 3000.0]], ['state_or_province' => 'ON', 'postal_code' => 'M5V 3L9']);
        $toBerlin = readinessPackage('DE', [['value' => 3000.0]]);
        $fromToronto = Location::factory()->create(['city' => 'Toronto', 'state_or_province' => 'ON', 'postal_code' => 'M5V 3L9', 'country' => 'CA']);
        $abroad = readinessPackage('JP', [['value' => 3000.0]], from: $fromToronto);

        expect(findingCodes(readinessFor($canada)))->not->toContain('export_itn_required')
            ->and(findingCodes(readinessFor($toBerlin)))->toContain('export_itn_required')
            ->and(findingCodes(readinessFor($abroad)))->not->toContain('export_itn_required');
    });

    it('requires the ITN whatever the value for a destination in export-filing.json', function (): void {
        useCustomsReferenceData(['version' => 'test', 'destinations' => ['JP' => ['source' => 'a test', 'checked' => '2026-10-08']]]);

        $cheap = readinessPackage('JP', [['value' => 10.0]]);
        $elsewhere = readinessPackage('AU', [['value' => 10.0]]);

        expect(findingCodes(readinessFor($cheap)))->toContain('export_itn_required')
            ->and(findingCodes(readinessFor($elsewhere)))->not->toContain('export_itn_required');
    });

    it('ships the committed list empty rather than invented', function (): void {
        $data = json_decode((string) file_get_contents(resource_path('data/customs/export-filing.json')), true);

        expect($data['status'])->toBe('unsourced')
            ->and($data['destinations'])->toBe([])
            ->and((new CustomsReferenceData)->alwaysFileDestinations())->toBe([]);
    });

    it('says the imported ITN was invalid when the import recorded that', function (): void {
        $package = readinessPackage('JP', [['value' => 3000.0]], ['validation_message' => 'Export ITN not imported: An export ITN must be X followed by 14 digits.']);

        expect(findingNamed(readinessFor($package), 'export_itn_required')?->message)->toContain('imported with an invalid ITN');
    });

    it('blocks a Shipment with an ITN whose client has no EIN, and lets it through once set', function (): void {
        $client = Client::factory()->create(['exporter_ein' => null]);
        $package = readinessPackage('JP', [['value' => 20.0]], ['export_itn' => 'X00000000000001'], $client);

        $finding = findingNamed(readinessFor($package), 'exporter_ein_missing');

        expect($finding?->severity)->toBe(CustomsFindingSeverity::Block)
            ->and($finding?->message)->toContain("{$client->name} has no EIN")
            ->and($finding?->message)->toContain('filer to supply the ITN')
            ->and($finding?->fixUrl)->not->toBeNull();

        $client->update(['exporter_ein' => '123456789']);

        expect(findingCodes(readinessFor($package->fresh())))->not->toContain('exporter_ein_missing');
    });

    it('does not ask for an EIN when there is no ITN', function (): void {
        $client = Client::factory()->create(['exporter_ein' => null]);

        expect(findingCodes(readinessFor(readinessPackage('JP', [['value' => 20.0]], client: $client))))->not->toContain('exporter_ein_missing');
    });

    it('sends a user who cannot edit the Shipment to a manager instead of a link', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::User]));
        $package = readinessPackage('JP', [['value' => 3000.0]]);

        $finding = findingNamed(readinessFor($package), 'export_itn_required');

        expect($finding?->fixUrl)->toBeNull()
            ->and($finding?->message)->toContain('A manager must add it.');
    });
});

describe('recipient tax ID', function (): void {
    it('blocks a Brazilian parcel with no CPF or CNPJ', function (): void {
        $package = readinessPackage('BR', [['value' => 20.0]], ['state_or_province' => 'SP', 'postal_code' => '01000-000']);

        $finding = findingNamed(readinessFor($package), 'recipient_tax_id_missing');

        expect($finding?->severity)->toBe(CustomsFindingSeverity::Block)
            ->and($finding?->message)->toContain('CPF or CNPJ')
            ->and($finding?->fixUrl)->not->toBeNull();
    });

    it('lets Brazil through with a CPF or CNPJ, but not with a type that does not satisfy it', function (): void {
        $cpf = readinessPackage('BR', [['value' => 20.0]], ['recipient_tax_id_type' => RecipientTaxIdType::Cpf, 'recipient_tax_id' => '12345678909']);
        $vat = readinessPackage('BR', [['value' => 20.0]], ['recipient_tax_id_type' => RecipientTaxIdType::Vat, 'recipient_tax_id' => 'ABC123']);

        expect(findingCodes(readinessFor($cpf)))->not->toContain('recipient_tax_id_missing')
            ->and(findingCodes(readinessFor($vat)))->toContain('recipient_tax_id_missing');
    });

    it('says the imported ID was invalid when the import recorded that', function (): void {
        $package = readinessPackage('BR', [['value' => 20.0]], ['validation_message' => 'Recipient tax ID not imported: bad check digit']);

        expect(findingNamed(readinessFor($package), 'recipient_tax_id_missing')?->message)->toContain('imported with an invalid recipient tax ID');
    });

    it('only warns for a Korean consumer, and not at all for a company', function (): void {
        $consumer = readinessPackage('KR', [['value' => 20.0]]);
        $company = readinessPackage('KR', [['value' => 20.0]], ['company' => 'Seoul Trading']);

        $finding = findingNamed(readinessFor($consumer), 'recipient_tax_id_missing');

        expect($finding?->severity)->toBe(CustomsFindingSeverity::Warn)
            ->and(CustomsReadiness::blocks(readinessFor($consumer)))->toBe([])
            ->and(findingCodes(readinessFor($company)))->not->toContain('recipient_tax_id_missing');
    });

    it('records the KR decision and its source in the data file', function (): void {
        $data = json_decode((string) file_get_contents(resource_path('data/customs/recipient-tax-id.json')), true);

        expect($data['destinations']['KR']['severity'])->toBe('warn')
            ->and($data['destinations']['KR']['source'])->toContain('KRA0011')
            ->and($data['destinations']['BR']['severity'])->toBe('block')
            ->and($data['destinations']['BR']['source'])->toContain('BRA0100');
    });
});

describe('seller registrations', function (): void {
    it('warns when a DDP parcel to a regime destination has no registration', function (): void {
        $client = Client::factory()->ddpToEu()->create();
        $without = readinessPackage('DE', [['value' => 20.0]], client: $client);

        $withIoss = Client::factory()->ddpToEu()->withIossRegistration()->create();
        $with = readinessPackage('DE', [['value' => 20.0]], client: $withIoss);

        $ddu = readinessPackage('DE', [['value' => 20.0]], ['duties_terms' => DutiesTerms::Ddu], $client);

        expect(findingNamed(readinessFor($without), 'ddp_without_registration')?->severity)->toBe(CustomsFindingSeverity::Warn)
            ->and(findingNamed(readinessFor($without), 'ddp_without_registration')?->message)->toContain('VAT may be charged twice')
            ->and(findingCodes(readinessFor($with)))->not->toContain('ddp_without_registration')
            ->and(findingCodes(readinessFor($ddu)))->not->toContain('ddp_without_registration');
    });

    it('warns that the registration is not sent when the order is over the threshold', function (): void {
        $client = Client::factory()->ddpToEu()->withIossRegistration()->create();
        // $200 is €160, over the €150 IOSS limit; $100 is €80.
        $over = readinessPackage('DE', [['value' => 200.0]], client: $client);
        $under = readinessPackage('DE', [['value' => 100.0]], client: $client);

        $finding = findingNamed(readinessFor($over), 'registration_not_sent');

        expect($finding?->severity)->toBe(CustomsFindingSeverity::Warn)
            ->and($finding?->message)->toContain('registration is not sent')
            ->and(findingCodes(readinessFor($under)))->not->toContain('registration_not_sent');
    });

    it('blocks a VOEC parcel with an item at NOK 3,000 or more, and only that', function (): void {
        $client = Client::factory()->create();
        ClientTaxRegistration::factory()->voec()->for($client)->create();

        $over = readinessPackage('NO', [['value' => 300.0, 'description' => 'Big Coat'], ['value' => 50.0]], ['postal_code' => '0150'], $client);
        $under = readinessPackage('NO', [['value' => 299.0, 'quantity' => 2]], ['postal_code' => '0150'], $client);

        $finding = findingNamed(readinessFor($over), 'voec_item_over_limit');

        expect($finding?->severity)->toBe(CustomsFindingSeverity::Block)
            ->and($finding?->lines)->toBe(['Big Coat'])
            ->and($finding?->message)->toContain('Ship this item separately')
            ->and(findingCodes(readinessFor($under)))->not->toContain('voec_item_over_limit');
    });

    it('blocks an ARN parcel with an item over AUD 1,000, and only that', function (): void {
        $client = Client::factory()->create();
        ClientTaxRegistration::factory()->arn()->for($client)->create();

        // $800 is AUD 1,024; $781.25 is AUD 1,000, which still qualifies.
        $over = readinessPackage('AU', [['value' => 800.0, 'description' => 'Drone']], ['postal_code' => '2000'], $client);
        $under = readinessPackage('AU', [['value' => 781.25]], ['postal_code' => '2000'], $client);
        $noRegistration = readinessPackage('AU', [['value' => 800.0]], ['postal_code' => '2000']);

        $finding = findingNamed(readinessFor($over), 'arn_item_over_limit');

        expect($finding?->severity)->toBe(CustomsFindingSeverity::Block)
            ->and($finding?->lines)->toBe(['Drone'])
            ->and(findingCodes(readinessFor($under)))->not->toContain('arn_item_over_limit')
            ->and(findingCodes(readinessFor($noRegistration)))->not->toContain('arn_item_over_limit');
    });

    it('warns about IOSS with a company name in the address', function (): void {
        $client = Client::factory()->ddpToEu()->withIossRegistration()->create();
        $business = readinessPackage('DE', [['value' => 20.0]], ['company' => 'Meyer GmbH'], $client);
        $consumer = readinessPackage('DE', [['value' => 20.0]], client: $client);

        expect(findingNamed(readinessFor($business), 'ioss_with_company')?->severity)->toBe(CustomsFindingSeverity::Warn)
            ->and(findingCodes(readinessFor($consumer)))->not->toContain('ioss_with_company');
    });
});

describe('carrier warnings', function (): void {
    it('warns about a USPS description over 30 characters, for USPS or before a carrier is chosen', function (): void {
        $package = readinessPackage('JP', [['description' => str_repeat('Long ', 8)]]);
        $usps = new RateResponse(Carrier::USPS, 'PMI', 'Priority Mail International', 40.0);
        $ups = new RateResponse(Carrier::UPS, '07', 'UPS Worldwide Express', 60.0);
        $short = readinessPackage('JP', [['description' => 'Cotton T-Shirt']]);

        expect(findingNamed(readinessFor($package), 'usps_description_cut')?->severity)->toBe(CustomsFindingSeverity::Warn)
            ->and(findingCodes(readinessFor($package, rate: $usps)))->toContain('usps_description_cut')
            ->and(findingCodes(readinessFor($package, rate: $ups)))->not->toContain('usps_description_cut')
            ->and(findingCodes(readinessFor($short)))->not->toContain('usps_description_cut');
    });

    it('warns about a FedEx DDU label with no recipient email', function (): void {
        $fedex = new RateResponse(Carrier::FEDEX, 'INTL', 'FedEx International Priority', 60.0);
        $noEmail = readinessPackage('JP', [['value' => 20.0]], ['email' => null]);
        $withEmail = readinessPackage('JP', [['value' => 20.0]]);

        expect(findingNamed(readinessFor($noEmail, rate: $fedex), 'fedex_ddu_without_email')?->severity)->toBe(CustomsFindingSeverity::Warn)
            ->and(findingCodes(readinessFor($withEmail, rate: $fedex)))->not->toContain('fedex_ddu_without_email')
            ->and(findingCodes(readinessFor($noEmail, rate: new RateResponse(Carrier::UPS, '07', 'UPS', 60.0))))->not->toContain('fedex_ddu_without_email');
    });
});

describe('the moved guards', function (): void {
    it('still reports a zero-value line and a missing EU identifier, first and with their titles', function (): void {
        $zero = readinessPackage('JP', [['value' => 0.0, 'origin' => null]]);
        $identifiers = readinessPackage('FR', [['value' => 20.0]]);
        $identifiers->packageItems->first()->product->update(['manufacturer_part_number' => null]);

        $zeroFindings = readinessFor($zero);
        $identifierFindings = readinessFor($identifiers->fresh());

        expect($zeroFindings[0]->code)->toBe('zero_value')
            ->and($zeroFindings[0]->title)->toBe('Customs Value Required')
            ->and($identifierFindings[0]->code)->toBe('product_identifier_missing')
            ->and($identifierFindings[0]->title)->toBe('Product Identifier Required');
    });

    it('keeps ShipRequest answering through the same rules', function (): void {
        $package = readinessPackage('JP', [['value' => 0.0]]);
        $request = ShipRequest::fromPackageAndRate($package, new RateResponse('MockCarrier', 'X', 'X', 5.0));

        expect($request->zeroValueCustomsItems())->toHaveCount(1);
    });
});

/**
 * A carrier that quotes one service and, where a test lets it, sells; the
 * method's one rule selects the service so automation buys it.
 */
function readinessCarrier(int $sales = 0): ShippingMethod
{
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $service = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Test Service',
        'service_code' => 'TEST',
        'active' => true,
    ]);
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($service->id);
    ShippingRule::factory()->create([
        'shipping_method_id' => $method->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
    ]);

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('customsDocumentDelivery')->andReturn(CustomsDocumentDelivery::FusedIntoLabel);
    $adapter->shouldReceive('getCarrierName')->andReturn('MockCarrier');
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect([
        new RateResponse('MockCarrier', 'TEST', 'Test Service', 7.50, carrierServiceId: $service->id),
    ]));

    if ($sales === 0) {
        $adapter->shouldNotReceive('createShipment');
    } else {
        $adapter->shouldReceive('createShipment')->times($sales)->andReturnUsing(fn (): ShipResponse => ShipResponse::success(
            trackingNumber: 'TRACK'.random_int(1000, 9999),
            cost: 7.50,
            carrier: 'MockCarrier',
            service: 'Test Service',
            labelData: base64_encode('label'),
        ));
    }

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    return $method;
}

describe('at purchase', function (): void {
    it('refuses a blocked purchase on every path and leaves the Offer unclaimed', function (): void {
        $method = readinessCarrier();
        $package = readinessPackage('JP', [['value' => 3000.0]], method: $method);

        $rate = quotedDirectly($package, new RateResponse('MockCarrier', 'TEST', 'Test Service', 7.50));
        $offer = ShippingOffer::query()->where('public_id', $rate->offerId)->firstOrFail();

        $result = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(selectedRate: $rate));

        expect($result->success)->toBeFalse()
            ->and($result->title)->toBe('Export ITN Required')
            ->and($result->message)->toContain('File EEI in AESDirect')
            ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped)
            ->and($offer->fresh()->isConsumed())->toBeFalse();
    });

    it('lets the same purchase through once the ITN and the EIN are set', function (): void {
        $method = readinessCarrier(sales: 1);
        $client = Client::factory()->withExporterEin()->create();
        $package = readinessPackage('JP', [['value' => 3000.0]], ['export_itn' => 'X00000000000001'], $client, method: $method);
        $rate = quotedDirectly($package, new RateResponse('MockCarrier', 'TEST', 'Test Service', 7.50));

        $result = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(selectedRate: $rate));

        expect($result->success)->toBeTrue();
    });

    it('does not refuse on a warning', function (): void {
        $method = readinessCarrier(sales: 1);
        $package = readinessPackage('JP', [['value' => 20.0, 'hs' => null]], method: $method);
        $rate = quotedDirectly($package, new RateResponse('MockCarrier', 'TEST', 'Test Service', 7.50));

        expect(findingCodes(readinessFor($package)))->toContain('hs_code_missing_warning')
            ->and(app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(selectedRate: $rate))->success)->toBeTrue();
    });

    it('records the reason against a batch row and goes on to the next package', function (): void {
        $method = readinessCarrier(sales: 1);
        $user = User::factory()->admin()->create();
        $client = Client::factory()->create();

        $blocked = readinessPackage('JP', [['value' => 20.0, 'origin' => null, 'sku' => 'NO-ORIGIN']], client: $client, method: $method);
        $fine = readinessPackage('JP', [['value' => 20.0]], client: $client, method: $method);

        $batch = LabelBatch::factory()->processing()->create(['user_id' => $user->id, 'total_shipments' => 2]);
        $items = collect([$blocked, $fine])->map(fn (Package $package): LabelBatchItem => LabelBatchItem::factory()->create([
            'label_batch_id' => $batch->id,
            'shipment_id' => $package->shipment_id,
            'package_id' => $package->id,
        ]));

        $items->each(fn (LabelBatchItem $item) => (new GenerateLabelJob($item->id, 'pdf', null))->handle());

        [$blockedItem, $fineItem] = $items->map->fresh()->all();

        expect($blockedItem->status)->toBe(LabelBatchItemStatus::Failed)
            ->and($blockedItem->error_message)->toContain('NO-ORIGIN')
            ->and($blockedItem->error_message)->toContain('country of origin')
            ->and($fineItem->status)->toBe(LabelBatchItemStatus::Success);
    });

    it('tells a batch row an EU Shipment has no duties terms, not that there are no rates', function (): void {
        readinessCarrier();
        $method = ShippingMethod::query()->latest('id')->firstOrFail();
        $user = User::factory()->admin()->create();
        $package = readinessPackage('IT', [['value' => 20.0]], method: $method);

        $batch = LabelBatch::factory()->processing()->create(['user_id' => $user->id, 'total_shipments' => 1]);
        $item = LabelBatchItem::factory()->create([
            'label_batch_id' => $batch->id,
            'shipment_id' => $package->shipment_id,
            'package_id' => $package->id,
        ]);

        (new GenerateLabelJob($item->id, 'pdf', null))->handle();

        expect($item->fresh()->status)->toBe(LabelBatchItemStatus::Failed)
            ->and($item->fresh()->error_message)->toContain('no duties terms are set for Italy or the EU')
            ->and($item->fresh()->error_message)->not->toContain('No shipping rates available');
    });

    it('surfaces a rate dropped for duties-support.json on the batch row', function (): void {
        $method = readinessCarrier();
        $user = User::factory()->admin()->create();
        // The mock carrier is not in the support table, so use USPS instead.
        $uspsCarrier = Carrier::query()->firstOrCreate(['name' => Carrier::USPS], Carrier::factory()->make(['name' => Carrier::USPS, 'active' => true])->getAttributes());
        $uspsCarrier->update(['active' => true]);
        $service = CarrierService::factory()->create(['carrier_id' => $uspsCarrier->id, 'name' => 'USPS Intl', 'service_code' => 'USPS_INTL', 'active' => true]);
        $uspsMethod = ShippingMethod::factory()->create();
        $uspsMethod->carrierServices()->attach($service->id);
        ShippingRule::factory()->create(['shipping_method_id' => $uspsMethod->id, 'action' => ShippingRuleAction::UseService, 'carrier_service_id' => $service->id]);

        $adapter = Mockery::mock(DirectCarrierAdapter::class);
        $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
        $adapter->shouldReceive('getCarrierName')->andReturn(Carrier::USPS);
        $adapter->shouldReceive('isConfigured')->andReturnTrue();
        $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
        $adapter->shouldReceive('getRates')->andReturn(collect([
            new RateResponse(Carrier::USPS, 'USPS_INTL', 'USPS Intl', 30.0, carrierServiceId: $service->id, carrierId: $uspsCarrier->id),
        ]));
        $adapter->shouldNotReceive('createShipment');
        app(CarrierRegistry::class)->registerInstance(Carrier::USPS, $adapter);

        // DDU into Germany: USPS requires prepaid duties there.
        $package = readinessPackage('DE', [['value' => 20.0]], ['duties_terms' => DutiesTerms::Ddu], method: $uspsMethod);

        $batch = LabelBatch::factory()->processing()->create(['user_id' => $user->id, 'total_shipments' => 1]);
        $item = LabelBatchItem::factory()->create([
            'label_batch_id' => $batch->id,
            'shipment_id' => $package->shipment_id,
            'package_id' => $package->id,
        ]);

        (new GenerateLabelJob($item->id, 'pdf', null))->handle();

        expect($item->fresh()->status)->toBe(LabelBatchItemStatus::Failed)
            ->and($item->fresh()->error_message)->toContain('USPS dropped: Germany requires prepaid duties (IMM)');
    });
});

describe('on the Ship page', function (): void {
    it('lists the findings, with their links, before any rate is fetched', function (): void {
        $method = readinessCarrier();
        $package = readinessPackage('JP', [['value' => 3000.0, 'hs' => null, 'origin' => null, 'sku' => 'NO-DATA']], method: $method);

        // Rating cannot proceed, so a finding on the page proves the check ran first.
        $workflow = Mockery::mock(PackageShippingWorkflow::class);
        $workflow->shouldReceive('prepareRates')->andThrow(new LockTimeoutException);
        app()->instance(PackageShippingWorkflow::class, $workflow);

        $component = Livewire::test(Ship::class, ['package_id' => $package->id]);
        $codes = array_column($component->get('customsFindings'), 'code');

        expect($codes)->toContain('origin_missing', 'export_itn_required', 'hs_code_missing_warning')
            ->and($component->get('rateOptions'))->toBe([]);

        $component->assertSee('Country of Origin Required')
            ->assertSee('blocks the label')
            ->assertSeeHtml('data-testid="customs-finding-origin_missing"')
            ->assertSeeHtml('href="'.ShipmentResource::getUrl('edit', ['record' => $package->shipment]).'"');
    });

    it('shows nothing for a Shipment with nothing to say', function (): void {
        $method = readinessCarrier();
        $package = readinessPackage('US', [['value' => 20.0]], ['state_or_province' => 'WA', 'postal_code' => '98101'], method: $method);

        Livewire::test(Ship::class, ['package_id' => $package->id])
            ->assertSet('customsFindings', [])
            ->assertDontSeeHtml('data-testid="customs-findings"');
    });
});

describe('Products list', function (): void {
    it('finds a product on an international package with no origin, or no HS code', function (): void {
        $noOrigin = readinessPackage('JP', [['origin' => null, 'sku' => 'NO-ORIGIN']]);
        $noHs = readinessPackage('JP', [['hs' => null, 'sku' => 'NO-HS']]);
        $complete = readinessPackage('JP', [['sku' => 'COMPLETE']]);
        $domestic = readinessPackage('US', [['origin' => null, 'sku' => 'DOMESTIC']], ['state_or_province' => 'WA', 'postal_code' => '98101']);
        $shipped = readinessPackage('JP', [['origin' => null, 'sku' => 'SHIPPED']]);
        $shipped->update(['status' => PackageStatus::Shipped]);

        Livewire::test(ListProducts::class)
            ->filterTable('missing_customs_data')
            ->assertCanSeeTableRecords(Product::query()->whereIn('sku', ['NO-ORIGIN', 'NO-HS'])->get())
            ->assertCanNotSeeTableRecords(Product::query()->whereIn('sku', ['COMPLETE', 'DOMESTIC', 'SHIPPED'])->get());
    });
});
