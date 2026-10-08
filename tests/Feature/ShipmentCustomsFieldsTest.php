<?php

use App\Console\Commands\PurgePiiCommand;
use App\Enums\DutiesTerms;
use App\Enums\RecipientTaxIdType;
use App\Enums\Role;
use App\Enums\TaxRegistrationRegime;
use App\Filament\Resources\ShipmentResource\Pages\EditShipment;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Logging\PiiRedactor;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use Livewire\Livewire;

/**
 * `international-customs-terms/03`: the order's own customs terms on the
 * Shipment — edited by a manager, shown on the view, purged with the address.
 */
it('lets a manager set every customs field, normalizing what is entered', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $shipment = Shipment::factory()->create(['country' => 'BR', 'state_or_province' => 'SP', 'postal_code' => '01000-000']);

    Livewire::test(EditShipment::class, ['record' => $shipment->getRouteKey()])
        ->fillForm([
            'duties_terms' => DutiesTerms::Ddp->value,
            'seller_tax_regime' => TaxRegistrationRegime::Ioss->value,
            'seller_tax_number' => 'im 0000000001',
            'recipient_tax_id_type' => RecipientTaxIdType::Cpf->value,
            'recipient_tax_id' => '123.456.789-09',
            'export_itn' => 'x00000000000001',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $shipment->refresh();

    expect($shipment->duties_terms)->toBe(DutiesTerms::Ddp)
        ->and($shipment->seller_tax_regime)->toBe(TaxRegistrationRegime::Ioss)
        ->and($shipment->seller_tax_number)->toBe('IM0000000001')
        ->and($shipment->recipient_tax_id_type)->toBe(RecipientTaxIdType::Cpf)
        ->and($shipment->recipient_tax_id)->toBe('12345678909')
        ->and($shipment->export_itn)->toBe('X00000000000001');
});

it('clears customs fields a manager empties', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $shipment = Shipment::factory()->withCustomsTerms()->create();

    Livewire::test(EditShipment::class, ['record' => $shipment->getRouteKey()])
        ->fillForm([
            'duties_terms' => null,
            'seller_tax_regime' => null,
            'seller_tax_number' => '',
            'recipient_tax_id_type' => null,
            'recipient_tax_id' => '',
            'export_itn' => '',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($shipment->refresh()->only(Shipment::CUSTOMS_FIELDS))
        ->toBe(array_fill_keys(Shipment::CUSTOMS_FIELDS, null));
});

it('rejects a malformed customs value on the Shipment form', function (array $fields, string $errorField): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $shipment = Shipment::factory()->create();

    Livewire::test(EditShipment::class, ['record' => $shipment->getRouteKey()])
        ->fillForm($fields)
        ->call('save')
        ->assertHasFormErrors([$errorField]);

    expect($shipment->refresh()->only(Shipment::CUSTOMS_FIELDS))
        ->toBe(array_fill_keys(Shipment::CUSTOMS_FIELDS, null));
})->with([
    'IOSS' => [['seller_tax_regime' => 'ioss', 'seller_tax_number' => 'IM000000001'], 'seller_tax_number'],
    'UK VAT' => [['seller_tax_regime' => 'uk_vat', 'seller_tax_number' => 'GB0000000001'], 'seller_tax_number'],
    'VOEC' => [['seller_tax_regime' => 'voec', 'seller_tax_number' => '000001'], 'seller_tax_number'],
    'ARN' => [['seller_tax_regime' => 'arn', 'seller_tax_number' => '0000000000001'], 'seller_tax_number'],
    'CPF' => [['recipient_tax_id_type' => 'cpf', 'recipient_tax_id' => '12345678900'], 'recipient_tax_id'],
    'CNPJ' => [['recipient_tax_id_type' => 'cnpj', 'recipient_tax_id' => '11222333000182'], 'recipient_tax_id'],
    'PCCC' => [['recipient_tax_id_type' => 'pccc', 'recipient_tax_id' => 'P12345'], 'recipient_tax_id'],
    'ITN' => [['export_itn' => 'NO EEI 30.37(a)'], 'export_itn'],
    'a number with no regime' => [['seller_tax_number' => 'IM0000000001'], 'seller_tax_regime'],
    'a regime with no number' => [['seller_tax_regime' => 'ioss'], 'seller_tax_number'],
    'an ID with no type' => [['recipient_tax_id' => '12345678909'], 'recipient_tax_id_type'],
]);

it('accepts a well-formed value of each customs format on the Shipment form', function (array $fields): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $shipment = Shipment::factory()->create();

    Livewire::test(EditShipment::class, ['record' => $shipment->getRouteKey()])
        ->fillForm($fields)
        ->call('save')
        ->assertHasNoFormErrors();
})->with([
    'IOSS' => [['seller_tax_regime' => 'ioss', 'seller_tax_number' => 'IM0000000001']],
    'UK VAT' => [['seller_tax_regime' => 'uk_vat', 'seller_tax_number' => 'GB000000000001']],
    'VOEC' => [['seller_tax_regime' => 'voec', 'seller_tax_number' => '0000001']],
    'ARN' => [['seller_tax_regime' => 'arn', 'seller_tax_number' => '000000000001']],
    'CPF' => [['recipient_tax_id_type' => 'cpf', 'recipient_tax_id' => '12345678909']],
    'CNPJ' => [['recipient_tax_id_type' => 'cnpj', 'recipient_tax_id' => '11.222.333/0001-81']],
    'PCCC' => [['recipient_tax_id_type' => 'pccc', 'recipient_tax_id' => 'P123456789012']],
    'ITN' => [['export_itn' => 'X00000000000001']],
]);

it('does not let a user below manager edit the Shipment customs fields', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));
    $shipment = Shipment::factory()->create();

    Livewire::test(EditShipment::class, ['record' => $shipment->getRouteKey()])->assertForbidden();

    expect(User::factory()->create(['role' => Role::User])->can('update', $shipment))->toBeFalse();
});

it('shows the order\'s customs terms on the Shipment view', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));
    $shipment = Shipment::factory()->withCustomsTerms()->create();

    Livewire::test(ViewShipment::class, ['record' => $shipment->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Customs')
        ->assertSee(DutiesTerms::Ddp->getLabel())
        ->assertSee('IM0000000001')
        ->assertSee('12345678909')
        ->assertSee('X00000000000001');
});

it('leaves the Customs section off the view of a domestic Shipment with none', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));
    $shipment = Shipment::factory()->create(['country' => 'US']);

    Livewire::test(ViewShipment::class, ['record' => $shipment->getRouteKey()])
        ->assertSuccessful()
        ->assertDontSee('Recipient Tax ID');
});

it('purges the recipient tax ID with the address once the retention period passes', function (): void {
    $shipment = Shipment::factory()->withCustomsTerms()->shipped()->create(['channel_id' => null]);
    Package::factory()->withCustomsForm()->create([
        'shipment_id' => $shipment->id,
        'shipped_at' => now()->subDays(120),
    ]);

    $this->artisan(PurgePiiCommand::class)->assertSuccessful();

    $shipment->refresh();

    expect($shipment->recipient_tax_id)->toBeNull()
        ->and($shipment->first_name)->toBeNull()
        ->and($shipment->packages()->sole()->customs_form_data)->toBeNull()
        // What was declared, not who: the terms and the type stay.
        ->and($shipment->recipient_tax_id_type)->toBe(RecipientTaxIdType::Cpf)
        ->and($shipment->duties_terms)->toBe(DutiesTerms::Ddp);
});

it('keeps the recipient tax ID inside the retention period', function (): void {
    $shipment = Shipment::factory()->withCustomsTerms()->create(['channel_id' => null]);
    Package::factory()->create([
        'shipment_id' => $shipment->id,
        'shipped_at' => now()->subDays(5),
    ]);

    $this->artisan(PurgePiiCommand::class)->assertSuccessful();

    expect($shipment->refresh()->recipient_tax_id)->toBe('12345678909');
});

it('redacts tax IDs from logged carrier payloads', function (): void {
    $redacted = PiiRedactor::redact([
        'recipient_tax_id' => '12345678909',
        'tins' => [['number' => '12345678909', 'tinType' => 'PERSONAL_NATIONAL']],
        'ShipTo' => ['TaxIdentificationNumber' => '12345678909'],
        'taxId' => '12345678909',
        'serviceType' => 'INTERNATIONAL_PRIORITY',
    ]);

    expect($redacted)->toBe([
        'recipient_tax_id' => '[REDACTED]',
        'tins' => '[REDACTED]',
        'ShipTo' => ['TaxIdentificationNumber' => '[REDACTED]'],
        'taxId' => '[REDACTED]',
        'serviceType' => 'INTERNATIONAL_PRIORITY',
    ]);
});
