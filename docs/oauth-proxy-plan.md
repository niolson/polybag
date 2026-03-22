# Shared OAuth Callback Proxy — Implementation Plan

## Context

The OAuth authorization code flow requires each provider to have registered callback URLs. With per-tenant domains and 5 providers (Shopify, Amazon, USPS, FedEx, UPS), this means registering `tenants × providers` URLs across multiple developer dashboards. A shared proxy on a single domain (e.g. `connect.polybag.app`) collapses this to 5 URLs total, regardless of tenant count.

The `OAUTH_PROXY_SECRET` (and shared OAuth client credentials) should live in a shared env file rather than being duplicated in every tenant's `.env`.

### Prerequisites

The OAuth authorization code flow infrastructure is already implemented:
- `app/Contracts/OAuthProvider.php` — provider interface
- `app/Services/OAuthProviderRegistry.php` — maps provider keys to implementations
- `app/Services/OAuthService.php` — orchestrates state, callback, token storage
- `app/Http/Controllers/OAuthCallbackController.php` — handles `/oauth/{provider}/callback`
- `app/Http/Integrations/Shopify/ShopifyOAuthProvider.php` — Shopify implementation
- Registered in `AppServiceProvider`, route in `routes/web.php`

### Architecture Overview

```
Tenant: "Connect with OAuth" button
  → OAuthService encrypts { nonce, return_url } as state
  → Redirects to Shopify with redirect_uri = connect.polybag.app/oauth/shopify/callback

Shopify: User authorizes
  → Redirects to connect.polybag.app/oauth/shopify/callback?code=...&state=<encrypted>

Proxy: Receives callback
  → Decrypts state → extracts return_url + nonce
  → Replaces state param with just the nonce
  → 302 redirects to {return_url}/oauth/shopify/callback?code=...&state=<nonce>&...

Tenant: OAuthCallbackController receives callback
  → Validates nonce (same as non-proxy flow)
  → Validates HMAC, exchanges code for token, stores encrypted in settings
```

---

## Part 1: Shared Environment File

### Create `/opt/shared/oauth.env`

```env
OAUTH_PROXY_SECRET=<run: openssl rand -hex 32>
OAUTH_PROXY_URL=https://connect.polybag.app
```

This file is read by the provision script and appended to each tenant's `.env`. The proxy service also reads `OAUTH_PROXY_SECRET` from its own `.env`.

### Changes to `config/services.php`

Add to the array:
```php
'oauth' => [
    'proxy_url' => env('OAUTH_PROXY_URL'),
    'proxy_secret' => env('OAUTH_PROXY_SECRET'),
],
```

### Changes to `.env.example`

Add (empty, documented):
```env
# OAuth Callback Proxy (SaaS/multi-tenant only)
# Leave empty for on-prem — OAuth callbacks go directly to this instance
OAUTH_PROXY_URL=
OAUTH_PROXY_SECRET=
```

---

## Part 2: State Encoder

### New file: `app/Support/OAuthStateEncoder.php`

Handles encrypting and decrypting the OAuth state payload. Uses AES-256-CBC with a shared secret so both the PolyBag app and the standalone proxy can encrypt/decrypt.

```php
class OAuthStateEncoder
{
    /**
     * Encrypt a state payload (nonce + return URL) into a URL-safe string.
     */
    public static function encode(string $nonce, string $returnUrl, string $secret): string
    {
        $payload = json_encode(['nonce' => $nonce, 'return_url' => $returnUrl]);
        $iv = random_bytes(16);
        $key = hex2bin($secret); // 32-byte key from 64-char hex string
        $encrypted = openssl_encrypt($payload, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return rtrim(strtr(base64_encode($iv . $encrypted), '+/', '-_'), '=');
    }

    /**
     * Decrypt a state string back into [nonce, return_url].
     * Returns null on failure.
     */
    public static function decode(string $state, string $secret): ?array
    {
        $data = base64_decode(strtr($state, '-_', '+/'));
        if (strlen($data) < 17) return null;
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        $key = hex2bin($secret);
        $json = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($json === false) return null;
        $payload = json_decode($json, true);
        if (!isset($payload['nonce'], $payload['return_url'])) return null;
        return $payload;
    }
}
```

The same decode logic is duplicated in the proxy's `index.php` (no shared dependency — the proxy has no Composer packages).

---

## Part 3: Modify OAuthService for Proxy Awareness

### Changes to `app/Services/OAuthService.php`

**`initiateAuthorization()`** — detect proxy mode and encode state accordingly:

```php
public function initiateAuthorization(string $providerKey): string
{
    $provider = $this->registry->get($providerKey);
    $nonce = Str::random(40);
    session()->put("oauth_state.{$providerKey}", $nonce);

    $proxyUrl = config('services.oauth.proxy_url');
    $proxySecret = config('services.oauth.proxy_secret');

    if ($proxyUrl && $proxySecret) {
        // Proxy mode: encrypt nonce + return URL into state
        $state = OAuthStateEncoder::encode($nonce, config('app.url'), $proxySecret);
        $redirectUri = rtrim($proxyUrl, '/') . "/oauth/{$providerKey}/callback";
    } else {
        // Direct mode: plain nonce as state
        $state = $nonce;
        $redirectUri = null; // provider uses its own default
    }

    return $provider->getAuthorizationUrl($state, $redirectUri);
}
```

**`handleCallback()`** — no changes needed. The proxy replaces the encrypted state with the plain nonce before redirecting to the tenant, so the tenant sees the same nonce it stored in session.

### Changes to `app/Contracts/OAuthProvider.php`

Add optional `redirectUri` parameter:
```php
public function getAuthorizationUrl(string $state, ?string $redirectUri = null): string;
```

### Changes to `app/Http/Integrations/Shopify/ShopifyOAuthProvider.php`

Update signature and use the passed redirect URI:
```php
public function getAuthorizationUrl(string $state, ?string $redirectUri = null): string
{
    // ... existing validation ...
    $redirectUri ??= route('oauth.callback', ['provider' => 'shopify']);
    // ... build URL with $redirectUri ...
}
```

---

## Part 4: The Proxy Service

### File structure

```
infra/oauth-proxy/
├── docker-compose.yml
├── Dockerfile
├── .env                # OAUTH_PROXY_SECRET=<same as /opt/shared/oauth.env>
└── public/
    └── index.php
```

### `infra/oauth-proxy/Dockerfile`

```dockerfile
FROM php:8.4-cli-alpine
COPY public/ /app/public/
WORKDIR /app
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
```

### `infra/oauth-proxy/docker-compose.yml`

```yaml
services:
  oauth-proxy:
    build: .
    restart: unless-stopped
    env_file: .env
    networks:
      - proxy

networks:
  proxy:
    external: true
```

### `infra/oauth-proxy/public/index.php`

```php
<?php
// Shared OAuth Callback Proxy
// Decrypts state to find the originating tenant, then redirects the callback there.

$secret = getenv('OAUTH_PROXY_SECRET');
if (!$secret) {
    http_response_code(500);
    echo 'OAUTH_PROXY_SECRET not configured';
    exit;
}

// Extract provider from path: /oauth/{provider}/callback
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!preg_match('#^/oauth/([a-z]+)/callback$#', $path, $matches)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}
$provider = $matches[1];

// Decrypt state
$state = $_GET['state'] ?? '';
$payload = oauthStateDecode($state, $secret);
if (!$payload) {
    http_response_code(400);
    echo 'Invalid or expired state parameter';
    exit;
}

// Validate return_url is HTTPS (or localhost for dev)
$returnUrl = $payload['return_url'];
$host = parse_url($returnUrl, PHP_URL_HOST);
$scheme = parse_url($returnUrl, PHP_URL_SCHEME);
if ($scheme !== 'https' && $host !== 'localhost' && $host !== '127.0.0.1') {
    http_response_code(400);
    echo 'Invalid return URL scheme';
    exit;
}

// Replace encrypted state with plain nonce in query params
$params = $_GET;
$params['state'] = $payload['nonce'];

// Redirect to tenant
$redirectUrl = rtrim($returnUrl, '/') . "/oauth/{$provider}/callback?" . http_build_query($params);
header("Location: {$redirectUrl}", true, 302);
exit;

// --- Decrypt function (same logic as OAuthStateEncoder::decode) ---
function oauthStateDecode(string $state, string $secret): ?array
{
    $data = base64_decode(strtr($state, '-_', '+/'));
    if (strlen($data) < 17) return null;
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    $key = hex2bin($secret);
    $json = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($json === false) return null;
    $payload = json_decode($json, true);
    if (!isset($payload['nonce'], $payload['return_url'])) return null;
    return $payload;
}
```

---

## Part 5: Provision Script Updates

### `scripts/provision-tenant.sh` changes (shared mode only)

After writing the tenant `.env`, append shared OAuth config:

```bash
# Append shared OAuth configuration (if available)
if [ -f "${SHARED_DIR}/oauth.env" ]; then
    info "Adding shared OAuth configuration..."
    echo "" >> "${TENANT_DIR}/.env"
    echo "# Shared OAuth Proxy" >> "${TENANT_DIR}/.env"
    cat "${SHARED_DIR}/oauth.env" >> "${TENANT_DIR}/.env"
fi
```

### Caddyfile addition (one-time server setup)

```
connect.polybag.app {
    reverse_proxy oauth-proxy:8080
}
```

---

## Summary of Changes

### PolyBag Code Changes

| File | Change |
|---|---|
| `app/Support/OAuthStateEncoder.php` | **New** — AES-256-CBC encrypt/decrypt for state payload |
| `app/Services/OAuthService.php` | Proxy-aware `initiateAuthorization()` — encrypts state with return URL when proxy configured |
| `app/Contracts/OAuthProvider.php` | Add `?string $redirectUri = null` param to `getAuthorizationUrl()` |
| `app/Http/Integrations/Shopify/ShopifyOAuthProvider.php` | Accept and use `$redirectUri` param |
| `config/services.php` | Add `oauth.proxy_url` and `oauth.proxy_secret` |
| `.env.example` | Add `OAUTH_PROXY_URL` and `OAUTH_PROXY_SECRET` |
| `scripts/provision-tenant.sh` | Append shared OAuth env vars in shared mode |

### New Proxy Service Files

| File | Purpose |
|---|---|
| `infra/oauth-proxy/public/index.php` | Proxy logic — decrypt state, redirect to tenant |
| `infra/oauth-proxy/Dockerfile` | PHP 8.4 built-in server |
| `infra/oauth-proxy/docker-compose.yml` | Container on proxy network |

### Server Setup (one-time)

1. Generate secret: `echo "OAUTH_PROXY_SECRET=$(openssl rand -hex 32)" > /opt/shared/oauth.env`
2. Add proxy URL: `echo "OAUTH_PROXY_URL=https://connect.polybag.app" >> /opt/shared/oauth.env`
3. Copy `infra/oauth-proxy/` to `/opt/oauth-proxy/` on the server
4. Copy `OAUTH_PROXY_SECRET` to `/opt/oauth-proxy/.env`
5. Start proxy: `cd /opt/oauth-proxy && docker compose up -d`
6. Add `connect.polybag.app` entry to Caddyfile and reload Caddy

---

## Verification

1. **Without proxy (on-prem):** Leave `OAUTH_PROXY_URL` empty in `.env` → OAuth flow works exactly as before, callbacks go directly to the instance. No behavioral change.
2. **With proxy:** Set `OAUTH_PROXY_URL` and `OAUTH_PROXY_SECRET` → Connect button redirects to Shopify with proxy callback URL → Shopify redirects to proxy → proxy decrypts state, redirects to tenant → tenant exchanges code and stores token.
3. Run `composer run test` — all existing + new tests pass.
4. Test with a second tenant to verify isolation (different tokens stored in different databases).
5. SSH to server, run `curl -v "https://connect.polybag.app/oauth/shopify/callback?state=garbage"` → should return 400 "Invalid or expired state parameter".
