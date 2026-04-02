<?php

use Illuminate\Support\Facades\DB;

it('returns a generic status payload for /up', function (): void {
    $this->get('/up')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
        ]);
});

it('does not expose internal dependency names on /up', function (): void {
    $this->get('/up')
        ->assertOk()
        ->assertJsonMissingPath('db')
        ->assertJsonMissingPath('redis');
});

it('returns generic test health details for /api/health', function (): void {
    $this->get('/api/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'fake_carriers' => (bool) config('app.fake_carriers'),
        ])
        ->assertJsonMissingPath('db');
});

it('returns a degraded status when the database connection is unavailable', function (): void {
    DB::shouldReceive('connection->getPdo')
        ->once()
        ->andThrow(new RuntimeException('database unavailable'));

    $this->get('/api/health')
        ->assertStatus(503)
        ->assertJson([
            'status' => 'degraded',
            'fake_carriers' => (bool) config('app.fake_carriers'),
        ])
        ->assertJsonMissingPath('db');
});
