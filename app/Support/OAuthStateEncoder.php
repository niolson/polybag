<?php

namespace App\Support;

class OAuthStateEncoder
{
    /**
     * Encrypt a state payload (nonce + return URL) into a URL-safe string.
     *
     * @param  string  $secret  Hex-encoded 32-byte key (64 hex chars)
     */
    public static function encode(string $nonce, string $returnUrl, string $secret): string
    {
        $payload = json_encode(['nonce' => $nonce, 'return_url' => $returnUrl]);
        $iv = random_bytes(16);
        $key = hex2bin($secret);
        $encrypted = openssl_encrypt($payload, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        // base64url encoding (URL-safe, no padding)
        return rtrim(strtr(base64_encode($iv.$encrypted), '+/', '-_'), '=');
    }

    /**
     * Decrypt a state string back into ['nonce' => ..., 'return_url' => ...].
     *
     * @param  string  $secret  Hex-encoded 32-byte key (64 hex chars)
     * @return array{nonce: string, return_url: string}|null Null on failure
     */
    public static function decode(string $state, string $secret): ?array
    {
        $data = base64_decode(strtr($state, '-_', '+/'));

        if ($data === false || strlen($data) < 17) {
            return null;
        }

        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        $key = hex2bin($secret);
        $json = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);

        if (! isset($payload['nonce'], $payload['return_url'])) {
            return null;
        }

        return $payload;
    }
}
