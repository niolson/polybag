<?php

// Shared OAuth Callback Proxy
//
// Receives OAuth callbacks from providers (Shopify, Amazon, etc.),
// decrypts the state parameter to find the originating tenant,
// and redirects the callback to that tenant's actual callback URL.
//
// The proxy does NOT exchange codes or validate HMACs — it only routes.

$secret = getenv('OAUTH_PROXY_SECRET');
if (! $secret) {
    http_response_code(500);
    echo 'OAUTH_PROXY_SECRET not configured';
    exit;
}

// Extract provider from path: /oauth/{provider}/callback
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (! preg_match('#^/oauth/([a-z]+)/callback$#', $path, $matches)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}
$provider = $matches[1];

// Decrypt state to find the originating tenant
$state = $_GET['state'] ?? '';
$payload = oauthStateDecode($state, $secret);
if (! $payload) {
    http_response_code(400);
    echo 'Invalid or expired state parameter';
    exit;
}

// Validate return URL uses HTTPS (or localhost for dev)
$returnUrl = $payload['return_url'];
$host = parse_url($returnUrl, PHP_URL_HOST);
$scheme = parse_url($returnUrl, PHP_URL_SCHEME);
if ($scheme !== 'https' && $host !== 'localhost' && $host !== '127.0.0.1') {
    http_response_code(400);
    echo 'Invalid return URL scheme';
    exit;
}

// Forward all params unchanged — the tenant decrypts the state itself.
// Modifying params here would break provider HMAC validation (e.g. Shopify).
$redirectUrl = rtrim($returnUrl, '/')."/oauth/{$provider}/callback?".http_build_query($_GET);
header("Location: {$redirectUrl}", true, 302);
exit;

// --- State decryption (matches App\Support\OAuthStateEncoder::decode) ---

function oauthStateDecode(string $state, string $secret): ?array
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
