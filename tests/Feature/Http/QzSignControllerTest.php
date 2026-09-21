<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The request/payload pair the browser actually sends: `payload` is the exact
 * pre-hash string qz-tray.js hashed (JSON-encoded {call, params, timestamp}),
 * and `request` is its SHA-256 digest — matching what the Sha256 shim in
 * qz-tray-script.blade.php captures and forwards.
 *
 * @return array{request: string, payload: string}
 */
function qzSignRequest(string $call = 'printers.find', array $params = []): array
{
    $payload = json_encode([
        'call' => $call,
        'params' => $params === [] ? new stdClass : $params,
        'timestamp' => 1234567890,
    ]);

    return [
        'request' => hash('sha256', $payload),
        'payload' => $payload,
    ];
}

it('returns 401 for unauthenticated requests', function (): void {
    $this->postJson('/qz/sign', qzSignRequest())
        ->assertUnauthorized();
});

it('rejects payloads that are not a QZ Tray call request', function (): void {
    $this->actingAs(User::factory()->create());

    $payload = 'arbitrary-string-to-sign';

    $this->postJson('/qz/sign', [
        'request' => hash('sha256', $payload),
        'payload' => $payload,
    ])
        ->assertStatus(422)
        ->assertJson(['error' => 'Unsupported signing request']);
});

it('rejects a payload that does not match the signed digest', function (): void {
    $this->actingAs(User::factory()->create());

    // The digest genuinely corresponds to a disallowed call, but the attached
    // payload falsely claims an allow-listed one. Without hash verification
    // this would sail through the allow-list check on the fake label alone.
    $realPayload = qzSignRequest('hid.sendData')['payload'];
    $fakePayload = qzSignRequest('printers.find')['payload'];

    $this->postJson('/qz/sign', [
        'request' => hash('sha256', $realPayload),
        'payload' => $fakePayload,
    ])
        ->assertStatus(422)
        ->assertJson(['error' => 'Unsupported signing request']);
});

it('rejects QZ Tray calls that are not on the allow-list', function (string $call): void {
    $this->actingAs(User::factory()->create());

    $this->postJson('/qz/sign', qzSignRequest($call))
        ->assertStatus(422)
        ->assertJson(['error' => 'Unsupported signing request']);
})->with([
    'file read' => 'file.read',
    'serial send' => 'serial.sendData',
    'usb claim' => 'usb.claimDevice',
    'networking device' => 'networking.device',
    // HID data-transfer calls the app never uses must not be signable, even
    // though the HID lifecycle calls (claim/open/close/list) are allow-listed.
    'hid send data' => 'hid.sendData',
    'hid read data' => 'hid.readData',
    'hid get feature report' => 'hid.getFeatureReport',
]);

it('rejects hid feature reports other than the PS60 zero command', function (array $params): void {
    $this->actingAs(User::factory()->create());

    $this->postJson('/qz/sign', qzSignRequest('hid.sendFeatureReport', $params))
        ->assertStatus(422)
        ->assertJson(['error' => 'Unsupported signing request']);
})->with([
    'wrong vendor' => [[
        'vendorId' => '0x0922',
        'productId' => '0xF000',
        'reportId' => '0x02',
        'data' => '02',
        'type' => 'HEX',
    ]],
    'tare-like value' => [[
        'vendorId' => '0x0EB8',
        'productId' => '0xF000',
        'reportId' => '0x02',
        'data' => '01',
        'type' => 'HEX',
    ]],
    'extra parameter' => [[
        'vendorId' => '0x0EB8',
        'productId' => '0xF000',
        'reportId' => '0x02',
        'data' => '02',
        'type' => 'HEX',
        'usagePage' => '0x008D',
    ]],
]);

it('returns 422 for missing request or payload parameter', function (): void {
    $this->actingAs(User::factory()->create());

    $this->postJson('/qz/sign', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['request', 'payload']);
});

it('returns 422 when request is not a well-formed sha-256 digest', function (): void {
    $this->actingAs(User::factory()->create());

    $this->postJson('/qz/sign', [
        'request' => 'not-a-hash',
        'payload' => qzSignRequest()['payload'],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request');
});

it('returns 422 when payload exceeds max length', function (): void {
    $this->actingAs(User::factory()->create());

    $payload = str_repeat('a', 6000001);

    $this->postJson('/qz/sign', [
        'request' => hash('sha256', $payload),
        'payload' => $payload,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payload');
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
        $this->postJson('/qz/sign', qzSignRequest())
            ->assertStatus(500)
            ->assertJson(['error' => 'Signing service unavailable']);
    } finally {
        if ($hadKey) {
            rename($backupPath, $keyPath);
        }
    }
});

it('signs allow-listed calls, including the hid.* scale integration family', function (string $call, array $params = []): void {
    $this->actingAs(User::factory()->create());

    $keyPath = storage_path('app/private/qz-private-key.pem');
    $keyDir = dirname($keyPath);

    if (! is_dir($keyDir)) {
        mkdir($keyDir, 0755, true);
    }

    $existedBefore = file_exists($keyPath);
    $originalContent = $existedBefore ? file_get_contents($keyPath) : null;

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
        $this->postJson('/qz/sign', qzSignRequest($call, $params))
            ->assertOk();
    } finally {
        if ($existedBefore) {
            file_put_contents($keyPath, $originalContent);
        } else {
            @unlink($keyPath);
        }
    }
})->with([
    'printers.find' => 'printers.find',
    'print' => 'print',
    'hid.listDevices' => 'hid.listDevices',
    'hid.claimDevice' => 'hid.claimDevice',
    'hid.releaseDevice' => 'hid.releaseDevice',
    'hid.openStream' => 'hid.openStream',
    'hid.closeStream' => 'hid.closeStream',
    'PS60 zero feature report' => [
        'hid.sendFeatureReport',
        [
            'vendorId' => '0x0EB8',
            'productId' => '0xF000',
            'reportId' => '0x02',
            'data' => '02',
            'type' => 'HEX',
        ],
    ],
]);

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
        $response = $this->postJson('/qz/sign', qzSignRequest());

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
