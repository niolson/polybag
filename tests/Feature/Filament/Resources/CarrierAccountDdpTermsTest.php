<?php

use App\Filament\Resources\CarrierAccounts\Pages\EditCarrierAccount;
use App\Models\CarrierAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * `international-customs-terms/08`: an Admin records the account's acceptance
 * of USPS's prepaid-duties terms; a manager cannot.
 */
beforeEach(function (): void {
    $this->account = CarrierAccount::factory()->usps()->create();
});

it('lets an Admin accept the terms, recording who and when', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(EditCarrierAccount::class, ['record' => $this->account->id])
        ->assertSee('Not accepted')
        ->assertSee('https://zonos.com/docs/legal/usps-terms-of-service')
        ->callAction('usps_accept_ddp_terms', ['accepted' => true])
        ->assertNotified('USPS DDP terms accepted for this account.');

    $account = $this->account->fresh();

    expect($account->hasAcceptedDdpTerms())->toBeTrue()
        ->and($account->ddp_terms_accepted_by)->toBe($admin->id)
        ->and($account->ddp_terms_accepted_at)->not->toBeNull();

    Livewire::test(EditCarrierAccount::class, ['record' => $this->account->id])
        ->assertSee('Accepted')
        ->assertActionHidden('usps_accept_ddp_terms');
});

it('requires the confirmation box', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditCarrierAccount::class, ['record' => $this->account->id])
        ->callAction('usps_accept_ddp_terms', ['accepted' => false])
        ->assertHasActionErrors(['accepted']);

    expect($this->account->fresh()->hasAcceptedDdpTerms())->toBeFalse();
});

it('does not offer the action on another carrier\'s account', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $fedex = CarrierAccount::factory()->fedex()->create();

    Livewire::test(EditCarrierAccount::class, ['record' => $fedex->id])
        ->assertActionHidden('usps_accept_ddp_terms');
});

it('keeps a manager from accepting the terms', function (): void {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    expect(Gate::forUser($manager)->allows('acceptDdpTerms', $this->account))->toBeFalse();

    Livewire::test(EditCarrierAccount::class, ['record' => $this->account->id])->assertForbidden();

    expect($this->account->fresh()->hasAcceptedDdpTerms())->toBeFalse();
});

it('cannot be set by mass assignment', function (): void {
    $this->account->update(['ddp_terms_accepted_at' => now(), 'ddp_terms_accepted_by' => User::factory()->create()->id]);

    expect($this->account->fresh()->hasAcceptedDdpTerms())->toBeFalse();
});

it('builds an accepted account from the factory state', function (): void {
    $account = CarrierAccount::factory()->usps()->ddpTermsAccepted()->create();

    expect($account->hasAcceptedDdpTerms())->toBeTrue()
        ->and($account->ddpTermsAcceptedBy)->toBeInstanceOf(User::class);
});
