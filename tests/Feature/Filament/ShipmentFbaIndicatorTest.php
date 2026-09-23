<?php

use App\Enums\AmazonOrderProgram;
use App\Enums\Role;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Models\Shipment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('badges an Amazon-fulfilled shipment in the shipments table', function (): void {
    $fba = Shipment::factory()->create(['metadata' => ['amazon_fulfilled_by' => 'AMAZON']]);
    $mfn = Shipment::factory()->create(['metadata' => ['amazon_fulfilled_by' => 'MERCHANT']]);

    Livewire::test(ListShipments::class)
        ->assertTableColumnStateSet('fulfilled_by', 'FBA', record: $fba)
        ->assertTableColumnStateSet('fulfilled_by', null, record: $mfn);
});

it('badges the Amazon program in the shipments table', function (): void {
    $prime = Shipment::factory()->create(['metadata' => ['amazon_programs' => ['PRIME']]]);
    $premium = Shipment::factory()->create(['metadata' => ['amazon_programs' => ['PREMIUM', 'AMAZON_BUSINESS']]]);
    $ordinary = Shipment::factory()->create(['metadata' => ['amazon_programs' => ['FBM_SHIP_PLUS']]]);
    $shopify = Shipment::factory()->create(['metadata' => []]);

    Livewire::test(ListShipments::class)
        ->assertTableColumnStateSet('amazon_programs', [AmazonOrderProgram::Prime], record: $prime)
        ->assertTableColumnStateSet('amazon_programs', [AmazonOrderProgram::Premium], record: $premium)
        ->assertTableColumnStateSet('amazon_programs', null, record: $ordinary)
        ->assertTableColumnStateSet('amazon_programs', null, record: $shopify);
});

it('shows the FBA notice on the shipment view only for Amazon-fulfilled orders', function (): void {
    $fba = Shipment::factory()->create(['metadata' => ['amazon_fulfilled_by' => 'AMAZON']]);

    Livewire::test(ViewShipment::class, ['record' => $fba->id])
        ->assertSchemaComponentExists('fulfilled_by');

    $mfn = Shipment::factory()->create(['metadata' => []]);

    Livewire::test(ViewShipment::class, ['record' => $mfn->id])
        ->assertSchemaComponentHidden('fulfilled_by');
});

it('badges the Amazon program on the shipment view only for an enrolled order', function (array $programs, ?string $label): void {
    $shipment = Shipment::factory()->create([
        'metadata' => ['amazon_order_id' => '111-2222222-3333333', 'amazon_programs' => $programs],
    ]);

    $page = Livewire::test(ViewShipment::class, ['record' => $shipment->id]);

    if ($label === null) {
        $page->assertSchemaComponentHidden('amazon_programs');

        return;
    }

    $page->assertSchemaComponentExists('amazon_programs');
    expect($page->html())->toMatch("/class=\"fi-badge [^\"]*\">\\s*{$label}\\s*</");
})->with([
    'prime' => [['PRIME'], 'Prime'],
    'premium' => [['PREMIUM'], 'Premium'],
    'ordinary' => [[], null],
    'ship plus' => [['FBM_SHIP_PLUS'], null],
]);
