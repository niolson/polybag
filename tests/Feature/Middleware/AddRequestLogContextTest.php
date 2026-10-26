<?php

use App\Http\Middleware\AddRequestLogContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Context;

it('adds the method and path, never the query string', function (): void {
    $request = Request::create('/pack/12?token=secret', 'GET');

    (new AddRequestLogContext)->handle($request, fn () => new Response);

    expect(Context::get('request'))->toBe(['method' => 'GET', 'path' => '/pack/12']);
});

it('names the Livewire work of a Livewire request without its values', function (): void {
    $request = Request::create('/livewire-abc/update', 'POST');
    $request->headers->set('X-Livewire', '1');
    $request->merge(['components' => [[
        'snapshot' => json_encode(['memo' => ['name' => 'app.filament.pages.pack']]),
        'updates' => ['weight' => '1.25'],
        'calls' => [['method' => 'saveDraft', 'params' => [['secret' => '1600 Pennsylvania Ave']]]],
    ]]]);

    (new AddRequestLogContext)->handle($request, fn () => new Response);

    expect(Context::get('request')['livewire'])->toBe([[
        'component' => 'app.filament.pages.pack',
        'calls' => ['saveDraft'],
        'updates' => ['weight'],
    ]])->and(json_encode(Context::all()))->not->toContain('Pennsylvania');
});

it('runs on every web request', function (): void {
    $this->get('/__no-such-page');

    expect(Context::get('request')['path'])->toBe('/__no-such-page');
});
