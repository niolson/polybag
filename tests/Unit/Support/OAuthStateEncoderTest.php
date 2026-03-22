<?php

use App\Support\OAuthStateEncoder;

// Generate a consistent test secret (64 hex chars = 32 bytes)
function testSecret(): string
{
    return str_repeat('ab', 32); // 64 hex chars
}

it('encodes and decodes a state payload', function (): void {
    $nonce = 'test-nonce-12345';
    $returnUrl = 'https://acme.polybag.app';
    $secret = testSecret();

    $encoded = OAuthStateEncoder::encode($nonce, $returnUrl, $secret);
    $decoded = OAuthStateEncoder::decode($encoded, $secret);

    expect($decoded)->not->toBeNull();
    expect($decoded['nonce'])->toBe($nonce);
    expect($decoded['return_url'])->toBe($returnUrl);
});

it('produces URL-safe output', function (): void {
    $encoded = OAuthStateEncoder::encode('nonce', 'https://example.com', testSecret());

    // Should not contain +, /, or = (standard base64 chars that aren't URL-safe)
    expect($encoded)->not->toContain('+');
    expect($encoded)->not->toContain('/');
    expect($encoded)->not->toContain('=');
});

it('produces different output each time (random IV)', function (): void {
    $secret = testSecret();
    $a = OAuthStateEncoder::encode('same-nonce', 'https://same.url', $secret);
    $b = OAuthStateEncoder::encode('same-nonce', 'https://same.url', $secret);

    expect($a)->not->toBe($b);

    // But both decode to the same payload
    $decodedA = OAuthStateEncoder::decode($a, $secret);
    $decodedB = OAuthStateEncoder::decode($b, $secret);
    expect($decodedA['nonce'])->toBe($decodedB['nonce']);
    expect($decodedA['return_url'])->toBe($decodedB['return_url']);
});

it('returns null for garbage input', function (): void {
    expect(OAuthStateEncoder::decode('not-valid-state', testSecret()))->toBeNull();
});

it('returns null for empty input', function (): void {
    expect(OAuthStateEncoder::decode('', testSecret()))->toBeNull();
});

it('returns null when decrypted with wrong secret', function (): void {
    $encoded = OAuthStateEncoder::encode('nonce', 'https://example.com', testSecret());
    $wrongSecret = str_repeat('cd', 32);

    expect(OAuthStateEncoder::decode($encoded, $wrongSecret))->toBeNull();
});

it('returns null for truncated ciphertext', function (): void {
    $encoded = OAuthStateEncoder::encode('nonce', 'https://example.com', testSecret());
    $truncated = substr($encoded, 0, 10);

    expect(OAuthStateEncoder::decode($truncated, testSecret()))->toBeNull();
});
