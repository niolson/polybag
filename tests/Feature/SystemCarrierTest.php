<?php

use App\Exceptions\SystemCarrierLockedException;
use App\Filament\Pages\EndOfDay;
use App\Filament\Pages\SetupWizard;
use App\Filament\Resources\CarrierAccounts\Pages\CreateCarrierAccount;
use App\Filament\Resources\Carriers\Pages\CreateCarrier;
use App\Filament\Resources\Carriers\Pages\EditCarrier;
use App\Filament\Resources\Carriers\Pages\ListCarriers;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use App\Services\CarrierNormalizer;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\SettingsService;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Livewire;

/**
 * A system carrier's name is its key, and `display_name` is what an operator
 * relabels — `carrier-catalog-reset/05`, ADR-0006 decision 1 as amended
 * 2026-09-25.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

describe('a system carrier', function (): void {
    it('refuses a rename', function (): void {
        $ups = Carrier::factory()->ups()->system()->create();

        expect(fn () => $ups->update(['name' => 'United Parcel Service']))
            ->toThrow(SystemCarrierLockedException::class);

        expect($ups->refresh()->name)->toBe(Carrier::UPS);
    });

    it('refuses deletion', function (): void {
        $ups = Carrier::factory()->ups()->system()->create();

        expect(fn () => $ups->delete())->toThrow(SystemCarrierLockedException::class);

        expect(Carrier::whereKey($ups->id)->exists())->toBeTrue();
    });

    it('can be deactivated and relabelled', function (): void {
        $ups = Carrier::factory()->ups()->system()->create();

        $ups->update(['active' => false, 'display_name' => 'Brown']);

        $ups->refresh();

        expect($ups->active)->toBeFalse()
            ->and($ups->display_name)->toBe('Brown');
    });

    it('offers neither a rename nor deletion in the carrier form', function (): void {
        $ups = Carrier::factory()->ups()->system()->create();

        Livewire::test(EditCarrier::class, ['record' => $ups->id])
            ->assertFormFieldDisabled('name')
            ->assertActionHidden(DeleteAction::class)
            ->fillForm(['display_name' => 'Brown'])
            ->call('save')
            ->assertHasNoFormErrors();

        $ups->refresh();

        expect($ups->name)->toBe(Carrier::UPS)
            ->and($ups->display_name)->toBe('Brown');
    });

    it('is not made system from a request', function (): void {
        $carrier = Carrier::create(['name' => 'Regional Courier', 'is_system' => true]);

        expect($carrier->refresh()->is_system)->toBeFalse();
    });
});

describe('a custom carrier', function (): void {
    it('can be created, renamed and deleted in the carrier form', function (): void {
        Livewire::test(CreateCarrier::class)
            ->fillForm(['name' => 'Regional Courier', 'active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $carrier = Carrier::where('name', 'Regional Courier')->sole();

        expect($carrier->is_system)->toBeFalse();

        Livewire::test(EditCarrier::class, ['record' => $carrier->id])
            ->assertFormFieldEnabled('name')
            ->fillForm(['name' => 'Regional Courier Co'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->callAction(DeleteAction::class);

        expect(Carrier::whereKey($carrier->id)->exists())->toBeFalse();
    });

    it('cannot take a name another carrier has', function (): void {
        Carrier::factory()->usps()->system()->create();

        Livewire::test(CreateCarrier::class)
            ->fillForm(['name' => Carrier::USPS])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);

        expect(fn () => Carrier::factory()->create(['name' => Carrier::USPS]))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('the reference-data sync', function (): void {
    it('finds the same rows on every run, marks them system, and keeps display names', function (): void {
        $this->artisan('app:sync-reference-data')->assertSuccessful();

        $ids = Carrier::query()->orderBy('id')->pluck('id');
        Carrier::where('name', Carrier::UPS)->sole()->update(['display_name' => 'Brown']);

        $this->artisan('app:sync-reference-data')->assertSuccessful();

        expect(Carrier::query()->orderBy('id')->pluck('id')->all())->toBe($ids->all())
            ->and(Carrier::where('is_system', false)->exists())->toBeFalse()
            ->and(Carrier::where('name', Carrier::UPS)->sole()->display_name)->toBe('Brown')
            ->and(Carrier::where('name', Carrier::UPS)->sole()->carrierAliases()->exists())->toBeTrue()
            ->and(Carrier::query()->pluck('name')->all())->toContain(
                Carrier::USPS,
                Carrier::FEDEX,
                Carrier::UPS,
                ShopifyAdapter::CARRIER_NAME,
                AmazonBuyShippingAdapter::SOURCE_NAME,
            );
    });

    it('adopts a custom carrier an operator made under a seeded name', function (): void {
        $custom = Carrier::factory()->ups()->create(['display_name' => 'Brown']);

        $this->artisan('app:sync-reference-data')->assertSuccessful();

        $custom->refresh();

        expect($custom->is_system)->toBeTrue()
            ->and($custom->display_name)->toBe('Brown')
            ->and(Carrier::where('name', Carrier::UPS)->count())->toBe(1);
    });
});

describe('the display name', function (): void {
    it('falls back to the name', function (): void {
        $ups = Carrier::factory()->ups()->system()->create();

        expect($ups->label())->toBe(Carrier::UPS);

        $ups->update(['display_name' => 'Brown']);
        expect($ups->label())->toBe('Brown');

        $ups->update(['display_name' => null]);
        expect($ups->label())->toBe(Carrier::UPS);
    });

    it('is shown in the carrier list, the account form and End of Day', function (): void {
        $ups = Carrier::factory()->ups()->system()->create(['display_name' => 'Brown']);

        Livewire::test(ListCarriers::class)
            ->assertCanSeeTableRecords([$ups])
            ->assertTableColumnFormattedStateSet('name', 'Brown', $ups);

        Livewire::test(CreateCarrierAccount::class)
            ->assertFormFieldExists('carrier_id', fn (Select $field): bool => $field->getOptions() === [$ups->id => 'Brown']);

        Livewire::test(EndOfDay::class)
            ->assertSet('carrierSummary', fn (array $summary): bool => collect($summary)
                ->contains(fn (array $row): bool => $row['carrier'] === Carrier::UPS && $row['label'] === 'Brown'))
            ->assertSee('Brown');
    });

    it('is shown for a package, whose carrier is text, and unknown text is left as it is', function (): void {
        Carrier::factory()->ups()->system()->create(['display_name' => 'Brown']);
        Carrier::factory()->create(['name' => 'Regional Courier', 'display_name' => 'RC Express']);
        $packages = collect(['UPS', 'Regional Courier', 'OnTrac'])
            ->map(fn (string $carrier): Package => Package::factory()->shipped()->create(['carrier' => $carrier]));

        Livewire::test(ListPackages::class)
            ->assertCanSeeTableRecords($packages)
            // The logo is still found by name; only the text is the label.
            ->assertSeeHtml('src="'.Carrier::logoUrlForName(Carrier::UPS).'" alt="Brown"')
            ->assertSeeHtml('>RC Express</span>')
            ->assertSeeHtml('>OnTrac</span>')
            ->assertDontSeeHtml('>Regional Courier</span>');
    });

    it('is escaped in the setup summary', function (): void {
        Setting::updateOrCreate(
            ['key' => 'setup_complete'],
            ['value' => '0', 'type' => 'boolean', 'group' => 'system'],
        );
        app(SettingsService::class)->clearCache();

        $usps = Carrier::factory()->usps()->system()->create(['display_name' => '<b>Mail</b>']);
        CarrierAccount::factory()->create(['carrier_id' => $usps->id]);
        DataSource::factory()->shopify()->create(['name' => '<i>Orders</i>']);

        Livewire::test(SetupWizard::class)
            ->assertSeeHtml('Connect &lt;b&gt;Mail&lt;/b&gt;')
            ->assertDontSeeHtml('<b>Mail</b>')
            ->assertSeeHtml('Finish configuring &lt;i&gt;Orders&lt;/i&gt;')
            ->assertDontSeeHtml('Finish configuring <i>Orders</i>');
    });

    it('changes nothing that finds the carrier by its name', function (): void {
        $ups = Carrier::factory()->ups()->system()->create(['display_name' => 'Brown']);
        $account = CarrierAccount::factory()->create([
            'carrier_id' => $ups->id,
            'credentials' => ['account_number' => 'A1B2C3'],
            'secret_credentials' => ['client_id' => 'id', 'client_secret' => 'secret'],
        ]);

        expect(app(CarrierNormalizer::class)->resolve(Carrier::UPS)?->is($ups))->toBeTrue()
            ->and(app(CarrierNormalizer::class)->resolve('Brown'))->toBeNull()
            ->and($account->hasUsableCredentials())->toBeTrue()
            ->and($ups->logoUrl())->toBe(Carrier::logoUrlForName(Carrier::UPS));
    });
});

describe('carrier accounts', function (): void {
    it('are offered only for carriers whose integration uses one', function (): void {
        $direct = collect([
            Carrier::factory()->usps()->system()->create(),
            Carrier::factory()->ups()->system()->create(),
            Carrier::factory()->fedex()->system()->create(),
        ]);
        Carrier::factory()->shopify()->system()->create();
        Carrier::factory()->system()->create(['name' => AmazonBuyShippingAdapter::SOURCE_NAME]);
        Carrier::factory()->create(['name' => 'Regional Courier']);

        Livewire::test(CreateCarrierAccount::class)
            ->assertFormFieldExists('carrier_id', fn (Select $field): bool => collect($field->getOptions())->keys()->sort()->values()->all()
                === $direct->pluck('id')->sort()->values()->all());
    });

    it('are refused for any other carrier', function (string $name): void {
        $carrier = Carrier::factory()->create(['name' => $name]);

        expect(fn () => CarrierAccount::create(['carrier_id' => $carrier->id, 'name' => 'Account', 'active' => true]))
            ->toThrow(InvalidArgumentException::class);

        expect(CarrierAccount::query()->exists())->toBeFalse();
    })->with([
        'Shopify' => [ShopifyAdapter::CARRIER_NAME],
        'Amazon' => [AmazonBuyShippingAdapter::SOURCE_NAME],
        'a custom carrier' => ['Regional Courier'],
    ]);

    it('are refused when moved to another carrier', function (): void {
        $account = CarrierAccount::factory()->usps()->create();
        $shopify = Carrier::factory()->shopify()->create();

        expect(fn () => $account->update(['carrier_id' => $shopify->id]))
            ->toThrow(InvalidArgumentException::class);
    });
});
