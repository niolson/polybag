<?php

use App\Enums\LabelReferenceSource;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\User;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('can render the list page', function (): void {
    Client::factory()->count(3)->create();

    Livewire::test(ListClients::class)
        ->assertSuccessful()
        ->assertActionDoesNotExist(TestAction::make(DeleteBulkAction::class)->table()->bulk());
});

it('can render the create page', function (): void {
    Livewire::test(CreateClient::class)->assertSuccessful();
});

it('can render the edit page', function (): void {
    $client = Client::factory()->create();

    Livewire::test(EditClient::class, ['record' => $client->id])->assertSuccessful();
});

it('can create a client', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Acme Corp',
            'active' => true,
            'is_default' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Client::class, [
        'name' => 'Acme Corp',
        'active' => true,
        'is_default' => false,
    ]);
});

it('can create a client with a return address', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Acme Corp',
            'return_company' => 'Acme Corp',
            'return_name' => 'Returns Dept',
            'return_address1' => '123 Main St',
            'return_city' => 'Springfield',
            'return_state_or_province' => 'IL',
            'return_postal_code' => '62701',
            'return_country' => 'US',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Client::class, [
        'name' => 'Acme Corp',
        'return_address1' => '123 Main St',
        'return_city' => 'Springfield',
        'return_postal_code' => '62701',
    ]);
});

it('creates clients following the app-wide label reference setting', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm(['name' => 'Acme Corp'])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Client::class, [
        'name' => 'Acme Corp',
        'label_reference_source' => null,
    ]);
});

it('can override which reference is printed on labels', function (): void {
    $client = Client::factory()->create(['label_reference_source' => null]);

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['label_reference_source' => LabelReferenceSource::None->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Client::class, [
        'id' => $client->id,
        'label_reference_source' => LabelReferenceSource::None->value,
    ]);
});

it('requires name', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm(['name' => ''])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

it('can edit a client', function (): void {
    $client = Client::factory()->create(['name' => 'Old Name']);

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Client::class, [
        'id' => $client->id,
        'name' => 'New Name',
    ]);
});

it('making a client default clears the previous default', function (): void {
    $original = Client::factory()->create(['is_default' => true]);
    $new = Client::factory()->create(['is_default' => false]);

    Livewire::test(EditClient::class, ['record' => $new->id])
        ->fillForm(['is_default' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($original->fresh()->is_default)->toBeFalse()
        ->and($new->fresh()->is_default)->toBeTrue();
});

it('can create a client with pack slip branding fields', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Acme Corp',
            'company_name' => 'ACME Corporation',
            'custom_message' => 'Thank you for your order!',
            'return_instructions' => 'Send returns to our warehouse.',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Client::class, [
        'name' => 'Acme Corp',
        'company_name' => 'ACME Corporation',
        'custom_message' => 'Thank you for your order!',
        'return_instructions' => 'Send returns to our warehouse.',
    ]);
});

it('can see clients in the list', function (): void {
    $clients = Client::factory()->count(3)->create();

    Livewire::test(ListClients::class)
        ->assertCanSeeTableRecords($clients);
});
