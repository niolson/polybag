<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class QzSignController extends Controller
{
    /**
     * The complete set of QZ Tray call names this application legitimately signs.
     *
     * This is an exact allow-list — deliberately not prefix-based. It covers only
     * the printer calls (find/print, plus status listening so a print can tell an
     * offline printer from a slow one) and the scale integration's HID device
     * lifecycle (list/claim/release/open/close stream). HID data-transfer calls
     * remain excluded here. `print` is further limited to a local, inline job
     * (isLocalPrintJob()), and `printers.startListening` to named printers without
     * spooled job data (isPrinterStatusListener()). The one hardware write the app uses is handled as a
     * parameter-constrained special case in isSignableCall(): the standard Scale
     * Zero feature report for the Mettler Toledo PS60 only. This prevents the
     * endpoint from minting signatures for arbitrary workstation hardware writes.
     * See security review issue 03.
     *
     * @var list<string>
     */
    private const ALLOWED_CALLS = [
        'printers.find',
        'print',
        'printers.getStatus',
        'printers.stopListening',
        'hid.listDevices',
        'hid.claimDevice',
        'hid.releaseDevice',
        'hid.openStream',
        'hid.closeStream',
    ];

    public function sign(Request $request)
    {
        $validated = $request->validate([
            'request' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'payload' => ['required', 'string', 'max:6000000'],
        ]);

        // QZ Tray's JS client SHA-256-hashes {call, params, timestamp} before ever
        // invoking the signature promise, so `request` is always that digest, never
        // the call itself — `payload` is the exact pre-hash string the browser
        // hashed (captured via a Sha256 shim in qz-tray-script.blade.php). Confirming
        // it reproduces `request` proves it's the true preimage rather than a
        // client-declared label, which is what makes the allow-list check below
        // meaningful instead of trivially spoofable.
        if (! hash_equals(hash('sha256', $validated['payload']), strtolower($validated['request']))) {
            logger()->warning('QZ Tray signing rejected: payload does not match signed digest', [
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);

            return response()->json(['error' => 'Unsupported signing request'], 422);
        }

        if (! $this->isSignableCall($validated['payload'])) {
            logger()->warning('QZ Tray signing rejected: payload is not an allowed QZ Tray call', [
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);

            return response()->json(['error' => 'Unsupported signing request'], 422);
        }

        $privateKeyPath = storage_path('app/private/qz-private-key.pem');

        if (! file_exists($privateKeyPath)) {
            logger()->error('QZ Tray signing failed: private key file not found', ['path' => $privateKeyPath]);

            return response()->json(['error' => 'Signing service unavailable'], 500);
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($privateKeyPath));

        if (! $privateKey) {
            logger()->error('QZ Tray signing failed: invalid private key', ['path' => $privateKeyPath]);

            return response()->json(['error' => 'Signing service unavailable'], 500);
        }

        $signature = null;
        openssl_sign($validated['request'], $signature, $privateKey, OPENSSL_ALGO_SHA512);

        return response(base64_encode($signature))
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Confirm the payload decodes to a QZ Tray call request whose `call` is on the
     * allow-list, without re-serializing it — the exact bytes must still be what
     * gets signed so the signature verifies against QZ Tray's own copy.
     */
    private function isSignableCall(string $payload): bool
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return false;
        }

        $call = $decoded['call'] ?? null;

        if (! is_string($call) || $call === '') {
            return false;
        }

        if ($call === 'hid.sendFeatureReport') {
            return $this->isPs60ZeroCommand($decoded['params'] ?? null);
        }

        if ($call === 'print') {
            return $this->isLocalPrintJob($decoded['params'] ?? null);
        }

        if ($call === 'printers.startListening') {
            return $this->isPrinterStatusListener($decoded['params'] ?? null);
        }

        return in_array($call, self::ALLOWED_CALLS, true);
    }

    /**
     * Allow a print job only in the shape the app sends: to a printer named on
     * this workstation (not a file path or a network host and port), with data
     * passed inline as raw ZPL or base64 (never a file or URL QZ would fetch).
     */
    private function isLocalPrintJob(mixed $params): bool
    {
        if (! is_array($params) || ! is_array($params['data'] ?? null)) {
            return false;
        }

        $printer = $params['printer'] ?? null;

        if (! is_array($printer) || array_keys($printer) !== ['name'] || ! is_string($printer['name'])) {
            return false;
        }

        foreach ($params['data'] as $item) {
            if (is_string($item)) {
                continue;
            }

            if (! is_array($item) || ! in_array($item['flavor'] ?? null, ['base64', 'plain'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Allow status listening on named printers only. On Windows the `jobData`
     * option returns the raw spooled content of every job sent to the printer,
     * including other applications' jobs, so any option beyond the printer
     * names is refused.
     */
    private function isPrinterStatusListener(mixed $params): bool
    {
        if (! is_array($params) || array_keys($params) !== ['printerNames']) {
            return false;
        }

        $printerNames = $params['printerNames'];

        if (! is_array($printerNames) || $printerNames === [] || ! array_is_list($printerNames)) {
            return false;
        }

        foreach ($printerNames as $printerName) {
            if (! is_string($printerName) || $printerName === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Allow only HID POS Scale Control report 2 with the Zero Scale bit set.
     */
    private function isPs60ZeroCommand(mixed $params): bool
    {
        if (! is_array($params) || count($params) !== 5) {
            return false;
        }

        return ($params['vendorId'] ?? null) === '0x0EB8'
            && ($params['productId'] ?? null) === '0xF000'
            && ($params['reportId'] ?? null) === '0x02'
            && ($params['data'] ?? null) === '02'
            && ($params['type'] ?? null) === 'HEX';
    }
}
