<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 401 for unauthenticated requests', function (): void {
    $this->postJson('/qz/sign', ['request' => 'test data'])
        ->assertUnauthorized();
});

it('returns 422 for missing request parameter', function (): void {
    $this->actingAs(User::factory()->create());

    $this->postJson('/qz/sign', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request');
});

it('returns 422 when request payload exceeds max length', function (): void {
    $this->actingAs(User::factory()->create());

    $this->postJson('/qz/sign', ['request' => str_repeat('a', 2049)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request');
});

it('returns 500 with generic error when private key file does not exist', function (): void {
    $this->actingAs(User::factory()->create());

    $keyPath = storage_path('app/private/qz-private-key.pem');
    $backupPath = $keyPath.'.test-backup';
    $hadKey = file_exists($keyPath);

    if ($hadKey) {
        rename($keyPath, $backupPath);
    }

    try {
        $this->postJson('/qz/sign', ['request' => 'test data'])
            ->assertStatus(500)
            ->assertJson(['error' => 'Signing service unavailable']);
    } finally {
        if ($hadKey) {
            rename($backupPath, $keyPath);
        }
    }
});

it('returns base64 signature when valid key exists', function (): void {
    $this->actingAs(User::factory()->create());

    $keyPath = storage_path('app/private/qz-private-key.pem');
    $keyDir = dirname($keyPath);

    if (! is_dir($keyDir)) {
        mkdir($keyDir, 0755, true);
    }

    $existedBefore = file_exists($keyPath);
    $originalContent = $existedBefore ? file_get_contents($keyPath) : null;

    // Find OpenSSL config portably (needed on Windows where it's not auto-detected)
    $config = [];
    $phpExtrasConf = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
    if (file_exists($phpExtrasConf)) {
        $config = ['config' => $phpExtrasConf];
    }

    $key = openssl_pkey_new(array_merge([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ], $config));

    if (! $key) {
        $this->markTestSkipped('Cannot generate RSA key in this environment.');
    }

    openssl_pkey_export($key, $pem, null, $config);
    file_put_contents($keyPath, $pem);

    try {
        $response = $this->postJson('/qz/sign', ['request' => 'test data to sign']);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        // Response should be valid base64
        $body = $response->getContent();
        expect(base64_decode($body, true))->not->toBeFalse();
    } finally {
        if ($existedBefore) {
            file_put_contents($keyPath, $originalContent);
        } else {
            @unlink($keyPath);
        }
    }
});
