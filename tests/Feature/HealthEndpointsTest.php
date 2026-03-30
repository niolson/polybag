<?php

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
