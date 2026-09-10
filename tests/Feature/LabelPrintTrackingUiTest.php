<?php

use App\Enums\LabelBatchStatus;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Resources\LabelBatchResource\Pages\ViewLabelBatch;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Package;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('filters packages by whether their label has been printed', function (): void {
    $printed = Package::factory()->shipped()->create(['label_printed_at' => now()]);
    $unprinted = Package::factory()->shipped()->create(['label_printed_at' => null]);

    Livewire::test(ListPackages::class)
        ->filterTable('label_printed', true)
        ->assertCanSeeTableRecords([$printed])
        ->assertCanNotSeeTableRecords([$unprinted])
        ->filterTable('label_printed', false)
        ->assertCanSeeTableRecords([$unprinted])
        ->assertCanNotSeeTableRecords([$printed]);
});

it('excludes unshipped packages from the not-printed filter', function (): void {
    $unshipped = Package::factory()->create(['label_printed_at' => null]);
    $shippedUnprinted = Package::factory()->shipped()->create(['label_printed_at' => null]);

    Livewire::test(ListPackages::class)
        ->filterTable('label_printed', false)
        ->assertCanSeeTableRecords([$shippedUnprinted])
        ->assertCanNotSeeTableRecords([$unshipped]);
});

it('sends only unprinted labels when printing a batch', function (): void {
    $batch = LabelBatch::factory()->create([
        'status' => LabelBatchStatus::Completed,
        'total_shipments' => 2,
        'successful_shipments' => 2,
    ]);

    $printed = Package::factory()->shipped()->create(['label_printed_at' => now()]);
    $unprinted = Package::factory()->shipped()->create(['label_printed_at' => null]);

    LabelBatchItem::factory()->success()->create([
        'label_batch_id' => $batch->id,
        'package_id' => $printed->id,
    ]);
    LabelBatchItem::factory()->success()->create([
        'label_batch_id' => $batch->id,
        'package_id' => $unprinted->id,
    ]);

    Livewire::test(ViewLabelBatch::class, ['record' => $batch->id])
        ->callAction(TestAction::make('printUnprintedLabels'))
        ->assertDispatched(
            'print-batch-labels',
            fn (string $event, array $params): bool => count($params['labels']) === 1
                && $params['labels'][0]['packageId'] === $unprinted->id,
        );
});

it('sends every label when reprinting a batch', function (): void {
    $batch = LabelBatch::factory()->create([
        'status' => LabelBatchStatus::Completed,
        'total_shipments' => 2,
        'successful_shipments' => 2,
    ]);

    $printed = Package::factory()->shipped()->create(['label_printed_at' => now()]);
    $unprinted = Package::factory()->shipped()->create(['label_printed_at' => null]);

    foreach ([$printed, $unprinted] as $package) {
        LabelBatchItem::factory()->success()->create([
            'label_batch_id' => $batch->id,
            'package_id' => $package->id,
        ]);
    }

    Livewire::test(ViewLabelBatch::class, ['record' => $batch->id])
        ->callAction(TestAction::make('reprintAllLabels'))
        ->assertDispatched(
            'print-batch-labels',
            fn (string $event, array $params): bool => count($params['labels']) === 2,
        );
});

it('hides the print action once every label in the batch is printed', function (): void {
    $batch = LabelBatch::factory()->create([
        'status' => LabelBatchStatus::Completed,
        'total_shipments' => 1,
        'successful_shipments' => 1,
    ]);

    LabelBatchItem::factory()->success()->create([
        'label_batch_id' => $batch->id,
        'package_id' => Package::factory()->shipped()->create(['label_printed_at' => now()])->id,
    ]);

    Livewire::test(ViewLabelBatch::class, ['record' => $batch->id])
        ->assertActionHidden(TestAction::make('printUnprintedLabels'))
        ->assertActionVisible(TestAction::make('reprintAllLabels'));
});

it('hides the print action when the only unprinted label was voided', function (): void {
    $batch = LabelBatch::factory()->create([
        'status' => LabelBatchStatus::Completed,
        'total_shipments' => 1,
        'successful_shipments' => 1,
    ]);

    LabelBatchItem::factory()->success()->create([
        'label_batch_id' => $batch->id,
        'package_id' => Package::factory()->create([
            'status' => PackageStatus::Unshipped,
            'label_data' => null,
            'label_printed_at' => null,
        ])->id,
    ]);

    Livewire::test(ViewLabelBatch::class, ['record' => $batch->id])
        ->assertActionHidden(TestAction::make('printUnprintedLabels'))
        ->assertActionHidden(TestAction::make('reprintAllLabels'));
});

it('includes the package id when printing a single label so the browser can report back', function (): void {
    $package = Package::factory()->shipped()->create([
        'shipped_by_user_id' => auth()->id(),
        'label_data' => 'base64-label',
    ]);

    Livewire::test(ListPackages::class)
        ->callAction(TestAction::make('reprint')->table($package))
        ->assertDispatched(
            'print-label',
            fn (string $event, array $params): bool => $params['packageId'] === $package->id,
        );
});

it('sends the customs form alongside the label it belongs to', function (): void {
    $package = Package::factory()->withCustomsForm()->create(['shipped_by_user_id' => auth()->id()]);

    Livewire::test(ListPackages::class)
        ->callAction(TestAction::make('reprint')->table($package))
        ->assertDispatched(
            'print-label',
            fn (string $event, array $params): bool => $params['customsForm'] === $package->customs_form_data,
        );
});

it('sends no customs form for a package that has none', function (): void {
    // Absent rather than null, so the browser can tell "nothing to print" from
    // "a customs form that failed to print".
    $package = Package::factory()->shipped()->create(['shipped_by_user_id' => auth()->id()]);

    Livewire::test(ListPackages::class)
        ->callAction(TestAction::make('reprint')->table($package))
        ->assertDispatched(
            'print-label',
            fn (string $event, array $params): bool => ! array_key_exists('customsForm', $params),
        );
});

it('interleaves each customs form with its own label in a batch print', function (): void {
    $batch = LabelBatch::factory()->create([
        'status' => LabelBatchStatus::Completed,
        'total_shipments' => 2,
        'successful_shipments' => 2,
    ]);

    $domestic = Package::factory()->shipped()->create(['label_printed_at' => null]);
    $international = Package::factory()->withCustomsForm()->create(['label_printed_at' => null]);

    foreach ([$domestic, $international] as $package) {
        LabelBatchItem::factory()->success()->create([
            'label_batch_id' => $batch->id,
            'package_id' => $package->id,
        ]);
    }

    Livewire::test(ViewLabelBatch::class, ['record' => $batch->id])
        ->callAction(TestAction::make('printUnprintedLabels'))
        ->assertDispatched(
            'print-batch-labels',
            fn (string $event, array $params): bool => collect($params['labels'])
                ->mapWithKeys(fn (array $label): array => [$label['packageId'] => $label['customsForm']])
                ->all() === [
                    $domestic->id => null,
                    $international->id => $international->customs_form_data,
                ],
        );
});

it('refuses to print a label the user is not allowed to reprint', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));

    $package = Package::factory()->shipped()->create([
        'shipped_by_user_id' => User::factory()->create(['role' => Role::User])->id,
        'label_data' => 'base64-label',
    ]);

    Livewire::test(ListPackages::class)
        ->call('printStoredPackageLabel', $package->id)
        ->assertNotDispatched('print-label');
});

it('labels the package print action by whether it has been printed before', function (): void {
    $unprinted = Package::factory()->shipped()->create(['label_printed_at' => null]);
    $printed = Package::factory()->shipped()->create(['label_printed_at' => now()]);

    Livewire::test(ListPackages::class)
        ->assertActionHasLabel(TestAction::make('reprint')->table($unprinted), 'Print')
        ->assertActionHasLabel(TestAction::make('reprint')->table($printed), 'Reprint');
});
