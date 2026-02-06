<?php

namespace App\Http\Integrations\USPS\Responses;

use Saloon\Http\Response;

class ScanFormResponse extends Response
{
    public array $metadata = [];

    public string $image = '';

    public function parseBody(): void
    {
        $contentType = $this->headers()->get('Content-Type') ?? '';

        // Check if this is a JSON error response
        if (str_contains($contentType, 'application/json')) {
            $json = $this->json();
            logger()->error('USPS Scan Form Error Response', ['response' => $json]);
            throw new \Exception('USPS Scan Form Error: '.($json['error']['message'] ?? 'Unknown error'));
        }

        // Extract boundary from Content-Type header
        if (! preg_match('/boundary=([^\s;]+)/i', $contentType, $matches)) {
            throw new \Exception('Could not find boundary in Content-Type header');
        }

        $boundary = trim($matches[1], '"');
        $parts = $this->parseMultipart($this->body(), $boundary);

        if (count($parts) < 2) {
            logger()->error('USPS Scan Form Response: Expected at least 2 parts', [
                'parts_count' => count($parts),
                'body_preview' => substr($this->body(), 0, 1000),
            ]);
            throw new \Exception('Invalid USPS scan form response format: expected at least 2 parts');
        }

        // First part: JSON metadata
        $this->metadata = json_decode($parts[0]['body'], true) ?? [];

        // Second part: Base64 encoded PDF
        $this->image = $parts[1]['body'];

        logger()->debug('USPS Scan Form Parsed', [
            'manifest_number' => $this->metadata['manifestNumber'] ?? 'N/A',
            'tracking_numbers_count' => count($this->metadata['trackingNumbers'] ?? []),
            'image_length' => strlen($this->image),
        ]);
    }

    /**
     * Parse a multipart response body into its component parts.
     *
     * @return array<int, array{headers: string, body: string}>
     */
    private function parseMultipart(string $body, string $boundary): array
    {
        $parts = [];
        $segments = explode('--'.$boundary, $body);

        foreach ($segments as $segment) {
            $segment = trim($segment);

            // Skip empty segments and closing boundary marker
            if ($segment === '' || $segment === '--') {
                continue;
            }

            // Split headers from body at double newline
            $split = preg_split('/\r?\n\r?\n/', $segment, 2);

            if (count($split) === 2) {
                $parts[] = [
                    'headers' => $split[0],
                    'body' => trim($split[1]),
                ];
            }
        }

        return $parts;
    }
}
