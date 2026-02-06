<?php

namespace App\Http\Integrations\USPS\Responses;

use Saloon\Http\Response;

class LabelResponse extends Response
{
    public array $metadata = [];

    public string $label = '';

    public function parseBody(): void
    {
        $contentType = $this->headers()->get('Content-Type') ?? '';

        // Check if this is a JSON error response
        if (str_contains($contentType, 'application/json')) {
            $json = $this->json();
            logger()->error('USPS Label Error Response', ['response' => $json]);
            throw new \Exception('USPS Label Error: '.($json['error']['message'] ?? 'Unknown error'));
        }

        // Extract boundary from Content-Type header
        if (! preg_match('/boundary=([^\s;]+)/i', $contentType, $matches)) {
            throw new \Exception('Could not find boundary in Content-Type header');
        }

        $boundary = trim($matches[1], '"');
        $parts = $this->parseMultipart($this->body(), $boundary);

        if (count($parts) < 2) {
            logger()->error('USPS Label Response: Expected at least 2 parts', [
                'parts_count' => count($parts),
                'body_preview' => substr($this->body(), 0, 1000),
            ]);
            throw new \Exception('Invalid USPS label response format: expected at least 2 parts');
        }

        // First part: JSON metadata
        $this->metadata = json_decode($parts[0]['body'], true) ?? [];

        // Second part: Base64 encoded label
        $this->label = $parts[1]['body'];

        logger()->debug('USPS Label Parsed', [
            'tracking_number' => $this->metadata['internationalTrackingNumber']
                ?? $this->metadata['trackingNumber']
                ?? 'N/A',
            'postage' => $this->metadata['postage'] ?? 'N/A',
            'label_length' => strlen($this->label),
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
