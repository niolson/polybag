<?php

use App\Models\Client;
use App\Services\ClientContext;
use Illuminate\Support\Facades\DB;

it('demotes the previous default when a new client is saved as default', function (): void {
    // Global beforeEach already creates a default client
    $first = Client::where('is_default', true)->firstOrFail();

    $second = Client::factory()->create(['is_default' => true]);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue();
});

it('does not demote the default client when another client is saved without changing is_default', function (): void {
    $default = Client::where('is_default', true)->firstOrFail();
    $other = Client::factory()->create(['is_default' => false]);

    $other->name = 'Updated Name';
    $other->save();

    expect($default->fresh()->is_default)->toBeTrue();
});

it('returns the default client from ClientContext', function (): void {
    $default = Client::where('is_default', true)->first();

    expect(app(ClientContext::class)->default()->id)->toBe($default->id);
});

it('id() returns the default client id', function (): void {
    $default = Client::where('is_default', true)->first();

    expect(app(ClientContext::class)->id())->toBe($default->id);
});

it('throws RuntimeException from ClientContext when no default client exists', function (): void {
    Client::query()->update(['is_default' => false]);

    expect(fn () => app(ClientContext::class)->default())
        ->toThrow(RuntimeException::class);
});

it('hasReturnAddress returns true when all required fields are present', function (): void {
    $client = Client::factory()->create([
        'return_address1' => '123 Main St',
        'return_city' => 'Springfield',
        'return_postal_code' => '12345',
    ]);

    expect($client->hasReturnAddress())->toBeTrue();
});

it('hasReturnAddress returns false when return_address1 is blank', function (): void {
    $client = Client::factory()->create([
        'return_address1' => null,
        'return_city' => 'Springfield',
        'return_postal_code' => '12345',
    ]);

    expect($client->hasReturnAddress())->toBeFalse();
});

it('hasReturnAddress returns false when return_city is blank', function (): void {
    $client = Client::factory()->create([
        'return_address1' => '123 Main St',
        'return_city' => null,
        'return_postal_code' => '12345',
    ]);

    expect($client->hasReturnAddress())->toBeFalse();
});

it('hasReturnAddress returns false when return_postal_code is blank', function (): void {
    $client = Client::factory()->create([
        'return_address1' => '123 Main St',
        'return_city' => 'Springfield',
        'return_postal_code' => null,
    ]);

    expect($client->hasReturnAddress())->toBeFalse();
});

it('reads the default client once per request', function (): void {
    $context = app(ClientContext::class);
    $context->forget();
    $reads = 0;
    DB::listen(function ($query) use (&$reads): void {
        $reads += str_contains($query->sql, '"clients"') ? 1 : 0;
    });

    $context->default();
    $context->default();
    $context->id();

    expect($reads)->toBe(1);
});

it('gives the new default client once another is saved as default', function (): void {
    app(ClientContext::class)->default();

    $second = Client::factory()->create(['is_default' => true]);

    expect(app(ClientContext::class)->id())->toBe($second->id);
});
