<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FedexMfaAuditService
{
    private const ARTIFACT_DIRECTORY = 'fedex-mfa/latest';

    private const REDACTED = '[REDACTED]';

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $response
     */
    public function recordExchange(string $step, array $request, array $response): void
    {
        $sanitizedRequest = $this->sanitize($request);
        $sanitizedResponse = $this->sanitize($response);
        $label = strtoupper(str_replace('-', ' ', $step));

        Log::channel('fedex-validation')->info("{$label} REQUEST", $sanitizedRequest);
        Log::channel('fedex-validation')->info("{$label} RESPONSE", $sanitizedResponse);

        Storage::put(
            self::ARTIFACT_DIRECTORY."/{$step}/request.json",
            json_encode($sanitizedRequest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        Storage::put(
            self::ARTIFACT_DIRECTORY."/{$step}/response.json",
            json_encode($sanitizedResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function sanitize(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = $this->sanitize($childValue, is_string($childKey) ? $childKey : null);
            }

            return $sanitized;
        }

        if (! is_string($key) || ! is_scalar($value)) {
            return $value;
        }

        return match ($key) {
            'access_token',
            'accountAuthToken',
            'authorization',
            'child_key',
            'child_Key',
            'child_secret',
            'client_id',
            'client_secret',
            'secureCodePin' => self::REDACTED,
            default => $value,
        };
    }
}
