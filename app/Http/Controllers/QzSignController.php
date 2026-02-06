<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class QzSignController extends Controller
{
    public function sign(Request $request)
    {
        $request->validate([
            'request' => 'required|string',
        ]);

        $privateKeyPath = storage_path('app/private/qz-private-key.pem');

        if (! file_exists($privateKeyPath)) {
            return response()->json(['error' => 'Private key not found'], 500);
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($privateKeyPath));

        if (! $privateKey) {
            return response()->json(['error' => 'Invalid private key'], 500);
        }

        $signature = null;
        openssl_sign($request->input('request'), $signature, $privateKey, OPENSSL_ALGO_SHA512);

        return response(base64_encode($signature))
            ->header('Content-Type', 'text/plain');
    }
}
