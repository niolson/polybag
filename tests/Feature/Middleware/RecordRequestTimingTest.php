<?php

use App\Http\Middleware\RecordRequestTiming;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

function livewireTimingRequest(): Request
{
    $request = Request::create('/livewire-abc/update', 'POST', server: ['REQUEST_TIME_FLOAT' => microtime(true) - 0.25]);
    $request->headers->set('X-Livewire', '1');
    $request->merge(['components' => [[
        'snapshot' => json_encode(['memo' => ['name' => 'app.filament.pages.pack']]),
        'updates' => ['weight' => '1.25'],
        'calls' => [
            ['method' => 'saveDraft', 'params' => [['secret' => '1600 Pennsylvania Ave']]],
            ['method' => 'mountAction', 'params' => ['Ship', ['recipient' => 'Jane Doe']]],
        ],
    ]]]);

    return $request;
}

it('logs nothing while request timing is off', function (): void {
    config(['app.request_timing' => false]);
    Log::shouldReceive('channel')->never();

    (new RecordRequestTiming)->terminate(livewireTimingRequest(), new Response);
});

it('logs wall time, peak memory and the Livewire work by name only', function (): void {
    config(['app.request_timing' => true]);

    $logged = null;
    Log::shouldReceive('channel')->once()->with('request-timing')->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$logged): bool {
        $logged = $context;

        return $message === 'request';
    });

    (new RecordRequestTiming)->terminate(livewireTimingRequest(), new Response);

    expect($logged)
        ->method->toBe('POST')
        ->status->toBe(200)
        ->ms->toBeGreaterThanOrEqual(250)
        ->peak_mb->toBeGreaterThan(0)
        ->livewire->toBe([[
            'component' => 'app.filament.pages.pack',
            'calls' => ['saveDraft', 'mountAction:Ship'],
            'updates' => ['weight'],
        ]])
        ->and(json_encode($logged))
        ->not->toContain('Pennsylvania')
        ->not->toContain('Jane Doe')
        ->not->toContain('1.25');
});

it('runs on every request, as one JSON line', function (): void {
    $path = storage_path('logs/request-timing-'.uniqid().'.log');
    config([
        'app.request_timing' => true,
        'logging.channels.request-timing.path' => $path,
    ]);

    try {
        $this->get('/up')->assertOk();

        $line = json_decode((string) file_get_contents($path), true);

        expect($line['message'])->toBe('request')
            ->and($line['context'])->toMatchArray(['method' => 'GET', 'route' => '/up', 'status' => 200])
            ->and($line['context'])->not->toHaveKey('livewire');
    } finally {
        @unlink($path);
    }
});
