<?php

use App\DataTransferObjects\PostageSources\ApprovalRule;
use App\Enums\AmazonChannelType;
use App\Enums\ApprovalEffect;
use App\Enums\Role;
use App\Enums\SourceEnvironment;
use App\Filament\Pages\ServiceApprovals;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\ObservedService;
use App\Models\ServiceApproval;
use App\Models\User;
use App\Services\PostageSources\ServiceApprovalGate;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));

    $this->client = Client::where('is_default', true)->firstOrFail();

    // The page is Amazon's, and is only offered while there is an Amazon
    // connection to buy through.
    DataSource::factory()->amazon()->create();

    ObservedService::factory()->create([
        'external_carrier_id' => 'ONTRAC',
        'external_carrier_name' => 'OnTrac',
        'external_service_id' => 'ONTRAC_MFN_GROUND',
        'external_service_name' => 'OnTrac Ground',
    ]);
    ObservedService::factory()->create([
        'external_carrier_id' => 'UPS',
        'external_carrier_name' => 'UPS',
        'external_service_id' => 'UPS_PTP_GND',
        'external_service_name' => 'UPS Ground',
    ]);
});

/**
 * The form-state key the page gave a carrier.
 */
function carrierKey($component, string $carrierId): string
{
    return collect($component->get('carriers'))->search(fn (array $carrier): bool => $carrier['id'] === $carrierId);
}

/**
 * @return list<string>
 */
function storedRules(
    Client $client,
    SourceEnvironment $environment = SourceEnvironment::Production,
    AmazonChannelType $channelType = AmazonChannelType::Amazon,
): array {
    return app(ServiceApprovalGate::class)
        ->rulesFor('amazon', $environment, $channelType, $client->id)
        ->rules
        ->map(fn (ApprovalRule $rule): string => $rule->key())
        ->sort()
        ->values()
        ->all();
}

it('renders for an admin, grouped by carrier', function (): void {
    Livewire::test(ServiceApprovals::class)
        ->assertFormSet(['mode' => ServiceApprovals::MODE_SELECTED])
        ->assertSuccessful()
        ->assertSee('OnTrac')
        ->assertSeeText('UPS Ground')
        // The source's identifiers are for the database, not for the person
        // deciding what automation may buy.
        ->assertDontSeeText('UPS_PTP_GND')
        ->assertDontSeeText('ONTRAC_MFN_GROUND');
});

it('is hidden while no Amazon connection is active', function (): void {
    // Approvals already on file stay put, and apply again once a connection
    // is reactivated.
    DataSource::query()->update(['active' => false]);

    expect(ServiceApprovals::canAccess())->toBeFalse();

    DataSource::factory()->shopify()->create();

    expect(ServiceApprovals::canAccess())->toBeFalse();

    DataSource::query()->where('source_type', AmazonSource::class)->update(['active' => true]);

    expect(ServiceApprovals::canAccess())->toBeTrue();
});

it('says it is for Amazon', function (): void {
    Livewire::test(ServiceApprovals::class)
        ->assertSeeText('Amazon Automation Approvals')
        ->assertSeeText('Direct carrier accounts need no approval here.');
});

it('is not open to a manager', function (): void {
    // Naming a service is a manager's job. Deciding that money may be spent on
    // it with nobody watching is not.
    $this->actingAs(User::factory()->manager()->create());

    expect(ServiceApprovals::canAccess())->toBeFalse();
});

it('approves everything, with an exception for one carrier', function (): void {
    $component = Livewire::test(ServiceApprovals::class);
    $ontrac = carrierKey($component, 'ONTRAC');

    $component
        ->fillForm([
            'mode' => ServiceApprovals::MODE_ALL,
            "carriers.{$ontrac}.deny_all" => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(storedRules($this->client))->toBe(['allow|*|*', 'deny|ONTRAC|*'])
        ->and(ServiceApproval::query()->pluck('approved_by_user_id')->unique()->all())->toBe([auth()->id()]);
});

it('approves a whole carrier with a single-service exception', function (): void {
    ObservedService::factory()->create([
        'external_carrier_id' => 'ONTRAC',
        'external_service_id' => 'ONTRAC_MFN_SUNRISE',
    ]);

    $component = Livewire::test(ServiceApprovals::class);
    $ontrac = carrierKey($component, 'ONTRAC');

    $component
        ->fillForm([
            'mode' => ServiceApprovals::MODE_SELECTED,
            "carriers.{$ontrac}.allow_all" => true,
            "carriers.{$ontrac}.excepted" => ['ONTRAC_MFN_SUNRISE'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(storedRules($this->client))->toBe(['allow|ONTRAC|*', 'deny|ONTRAC|ONTRAC_MFN_SUNRISE']);
});

it('approves an unmapped service one at a time', function (): void {
    $component = Livewire::test(ServiceApprovals::class);
    $ups = carrierKey($component, 'UPS');

    $component
        ->fillForm(["carriers.{$ups}.approved" => ['UPS_PTP_GND']])
        ->call('save');

    expect(storedRules($this->client))->toBe(['allow|UPS|UPS_PTP_GND'])
        ->and(ObservedService::query()->whereNotNull('carrier_service_id')->exists())->toBeFalse();
});

it('writes no exception with nothing to except', function (): void {
    // Exceptions ticked under "All services" are still in the form state after
    // switching to "Selected services" — where, with no carrier approved, they
    // would except nothing.
    $component = Livewire::test(ServiceApprovals::class);
    $ontrac = carrierKey($component, 'ONTRAC');

    $component
        ->fillForm([
            'mode' => ServiceApprovals::MODE_ALL,
            "carriers.{$ontrac}.excepted" => ['ONTRAC_MFN_GROUND'],
        ])
        ->fillForm(['mode' => ServiceApprovals::MODE_SELECTED])
        ->call('save');

    expect(storedRules($this->client))->toBe([]);
});

it('prefills the form from the rules on file', function (): void {
    $gate = app(ServiceApprovalGate::class);
    $gate->grantRule('amazon', SourceEnvironment::Production, AmazonChannelType::Amazon, $this->client, ApprovalRule::everything(), User::factory()->create());
    $gate->grantRule('amazon', SourceEnvironment::Production, AmazonChannelType::Amazon, $this->client, ApprovalRule::service('UPS', 'UPS_PTP_GND', ApprovalEffect::Deny), User::factory()->create());

    $component = Livewire::test(ServiceApprovals::class);
    $ups = carrierKey($component, 'UPS');

    $component->assertFormSet([
        'mode' => ServiceApprovals::MODE_ALL,
        "carriers.{$ups}.excepted" => ['UPS_PTP_GND'],
    ]);
});

it('keeps a rule for a service this world has never reported', function (): void {
    // The form saves exactly what it shows, so a rule it did not show would be
    // withdrawn by anyone saving it for an unrelated reason.
    app(ServiceApprovalGate::class)->grantRule(
        'amazon',
        SourceEnvironment::Production,
        AmazonChannelType::Amazon,
        $this->client,
        ApprovalRule::service('DHL_ECOMMERCE', 'DHL_PARCEL_GROUND'),
        User::factory()->create(),
    );

    Livewire::test(ServiceApprovals::class)
        ->assertSee('DHL_PARCEL_GROUND')
        ->call('save')
        ->assertNotified();

    expect(storedRules($this->client))->toBe(['allow|DHL_ECOMMERCE|DHL_PARCEL_GROUND']);
});

it('withdraws approval when a service is unticked', function (): void {
    $observation = ObservedService::where('external_service_id', 'UPS_PTP_GND')->sole();
    app(ServiceApprovalGate::class)->grant($observation, AmazonChannelType::Amazon, $this->client, User::factory()->create());

    $component = Livewire::test(ServiceApprovals::class);
    $ups = carrierKey($component, 'UPS');

    $component
        ->assertFormSet(["carriers.{$ups}.approved" => ['UPS_PTP_GND']])
        ->fillForm(["carriers.{$ups}.approved" => []])
        ->call('save');

    expect(ServiceApproval::count())->toBe(0);
});

it('approves one environment and one client at a time', function (): void {
    $other = Client::factory()->create();

    Livewire::test(ServiceApprovals::class)
        ->fillForm(['environment' => SourceEnvironment::Sandbox->value])
        ->fillForm(['mode' => ServiceApprovals::MODE_ALL])
        ->call('save');

    expect(storedRules($this->client, SourceEnvironment::Sandbox))->toBe(['allow|*|*'])
        ->and(storedRules($this->client))->toBe([])
        ->and(storedRules($other, SourceEnvironment::Sandbox))->toBe([]);
});

it('reloads the form for the client picked', function (): void {
    $other = Client::factory()->create();
    app(ServiceApprovalGate::class)->grantRule('amazon', SourceEnvironment::Production, AmazonChannelType::Amazon, $other, ApprovalRule::everything(), User::factory()->create());

    Livewire::test(ServiceApprovals::class)
        ->assertFormSet(['mode' => ServiceApprovals::MODE_SELECTED])
        ->fillForm(['client_id' => $other->id])
        ->assertFormSet(['mode' => ServiceApprovals::MODE_ALL]);
});

it('hides carriers a US seller cannot buy from', function (): void {
    ObservedService::factory()->neverEligible()->create([
        'external_carrier_id' => 'YANWEN',
        'external_carrier_name' => 'Yanwen',
        'external_service_id' => 'YW_AIR_TRACK_PACKET_GENERAL',
        'external_service_name' => 'Yanwen YW Air Tracked Packet - General Cargo',
    ]);
    ObservedService::factory()->neverEligible()->create([
        'external_carrier_id' => 'SELF_DELIVERY',
        'external_carrier_name' => 'Self Delivery',
        'external_service_id' => 'SELF_DELIVERY_PTP_GROUND',
        'external_service_name' => 'by Seller',
    ]);

    $component = Livewire::test(ServiceApprovals::class)
        ->assertDontSeeText('Yanwen')
        ->assertDontSeeText('Self Delivery');

    expect(collect($component->get('carriers'))->pluck('id')->all())->toBe(['ONTRAC', 'UPS']);
});

it('shows a cross-border carrier service once it has been offered as buyable', function (): void {
    ObservedService::factory()->create([
        'external_carrier_id' => 'YUN',
        'external_carrier_name' => 'Yun Express',
        'external_service_id' => 'YUN_US_DIRECT_LINE',
        'external_service_name' => 'Yun Express US Direct Line',
        'last_eligible_at' => now(),
    ]);

    Livewire::test(ServiceApprovals::class)
        ->assertSeeText('Yun Express US Direct Line');
});

it('keeps showing a hidden carrier that a rule names, so saving cannot withdraw it unseen', function (): void {
    ObservedService::factory()->neverEligible()->create([
        'external_carrier_id' => 'YANWEN',
        'external_carrier_name' => 'Yanwen',
        'external_service_id' => 'YW_AIR_TRACK_PACKET_GENERAL',
    ]);
    app(ServiceApprovalGate::class)->grantRule(
        'amazon',
        SourceEnvironment::Production,
        AmazonChannelType::Amazon,
        $this->client,
        ApprovalRule::carrier('YANWEN'),
        User::factory()->create(),
    );

    Livewire::test(ServiceApprovals::class)
        ->assertSeeText('Yanwen')
        ->call('save');

    expect(storedRules($this->client))->toBe(['allow|YANWEN|*']);
});

it('tells apart two services a carrier reports under the same name', function (): void {
    ObservedService::factory()->create([
        'external_carrier_id' => 'UPS',
        'external_service_id' => 'UPS_PTP_GND_ALT',
        'external_service_name' => 'UPS Ground',
    ]);

    Livewire::test(ServiceApprovals::class)
        ->assertSeeText('UPS Ground (UPS_PTP_GND)')
        ->assertSeeText('UPS Ground (UPS_PTP_GND_ALT)');
});

it('approves orders from other channels separately from Amazon orders', function (): void {
    app(ServiceApprovalGate::class)->grantRule(
        'amazon',
        SourceEnvironment::Production,
        AmazonChannelType::Amazon,
        $this->client,
        ApprovalRule::everything(),
        User::factory()->create(),
    );

    $component = Livewire::test(ServiceApprovals::class)
        ->assertFormSet(['channel_type' => AmazonChannelType::Amazon->value, 'mode' => ServiceApprovals::MODE_ALL])
        ->fillForm(['channel_type' => AmazonChannelType::External->value])
        // What is on file for Amazon orders says nothing about other channels.
        ->assertFormSet(['mode' => ServiceApprovals::MODE_SELECTED]);

    $ups = carrierKey($component, 'UPS');

    $component
        ->fillForm(["carriers.{$ups}.allow_all" => true])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(storedRules($this->client, channelType: AmazonChannelType::External))->toBe(['allow|UPS|*'])
        ->and(storedRules($this->client))->toBe(['allow|*|*']);
});
