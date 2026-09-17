<?php

use App\Filament\Pages\DeviceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('offers separate PDF/image and raw ZPL label printers and a document printer', function (): void {
    Livewire::test(DeviceSettings::class)
        ->assertSeeHtml('id="image-label-printer"')
        ->assertSeeHtml('id="raw-label-printer"')
        ->assertSeeHtml('id="report-printer"')
        ->assertSee('Label Printers')
        ->assertSee('PDF / Image Printer')
        ->assertSee('Raw ZPL Printer')
        ->assertSee('Preferred Label Format')
        ->assertSee('Document Printer')
        ->assertDontSee('Report Printer');
});

it('loads the shared printer settings resolver the print pages read', function (): void {
    Livewire::test(DeviceSettings::class)
        ->assertSeeHtml('window.PrinterSettings');
});
