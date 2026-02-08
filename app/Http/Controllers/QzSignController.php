<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class QzSignController extends Controller
{
    public function sign(Request $request)
    {
        $request->validate([
            'request' => 'required|string|max:2048',
        ]);

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
        openssl_sign($request->input('request'), $signature, $privateKey, OPENSSL_ALGO_SHA512);

        return response(base64_encode($signature))
            ->header('Content-Type', 'text/plain');
    }
}
