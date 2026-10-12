<?php

use App\Enums\BoxSizeType;
use App\Filament\Pages\PrintBoxSizeBarcodes;
use App\Models\BoxSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('prints box sizes grouped by type and smallest first within each type', function (): void {
    $sizes = [
        ['code' => 'A', 'label' => '10x10x10', 'type' => BoxSizeType::BOX, 'length' => 10, 'width' => 10, 'height' => 10],
        ['code' => 'B', 'label' => '19x14.5 Poly Mailer', 'type' => BoxSizeType::POLYBAG, 'length' => 19, 'width' => 14.5, 'height' => 0.5],
        ['code' => 'C', 'label' => '6x4x4', 'type' => BoxSizeType::BOX, 'length' => 6, 'width' => 4, 'height' => 4],
        ['code' => 'D', 'label' => '#0 Padded Mailer', 'type' => BoxSizeType::PADDED_MAILER, 'length' => 10, 'width' => 6, 'height' => 0.5],
        ['code' => 'E', 'label' => '13x10 Poly Mailer', 'type' => BoxSizeType::POLYBAG, 'length' => 13, 'width' => 10, 'height' => 0.5],
        ['code' => 'F', 'label' => '8x8x8', 'type' => BoxSizeType::BOX, 'length' => 8, 'width' => 8, 'height' => 8],
    ];

    foreach ($sizes as $size) {
        BoxSize::factory()->create([...$size, 'carrier_packaging' => null]);
    }

    $printed = Livewire::test(PrintBoxSizeBarcodes::class)
        ->get('boxSizes')
        ->pluck('code')
        ->all();

    expect($printed)->toBe(['C', 'F', 'A', 'E', 'B', 'D']);
});
