<?php

namespace App\Services\ServiceInference;

use Smalot\PdfParser\Parser;

/**
 * The human-readable text a stored label yields, whatever format it is in.
 *
 * Dispatches on the bytes rather than on `packages.label_format`. The column can
 * disagree with the file — the FedEx test-run fixtures contain ZPL saved as
 * `label.pdf` — and feeding ZPL to a PDF parser is how a format mismatch turns
 * into an exception on a shipping path instead of a clean fall-through.
 */
class LabelTextExtractor
{
    /**
     * Every text run in the label, or an empty list if none can be read.
     *
     * Never throws: a label that cannot be read is a rung that did not resolve,
     * not an error worth failing a purchase over.
     *
     * @return list<string>
     */
    public function extract(?string $labelData): array
    {
        $bytes = $this->decode($labelData);

        if ($bytes === null) {
            return [];
        }

        if (str_starts_with($bytes, '%PDF-')) {
            return $this->fromPdf($bytes);
        }

        if (str_contains($bytes, '^XA')) {
            return $this->fromZpl($bytes);
        }

        return [];
    }

    /**
     * The format this extractor recognized, for the inference method stamp.
     */
    public function formatOf(?string $labelData): ?string
    {
        $bytes = $this->decode($labelData);

        return match (true) {
            $bytes === null => null,
            str_starts_with($bytes, '%PDF-') => 'pdf',
            str_contains($bytes, '^XA') => 'zpl',
            default => null,
        };
    }

    /**
     * Labels are stored base64-encoded; ZPL is sometimes stored as plain text.
     */
    private function decode(?string $labelData): ?string
    {
        if (blank($labelData)) {
            return null;
        }

        $decoded = base64_decode($labelData, true);

        if ($decoded !== false && ($decoded !== '' || $labelData === '')) {
            return $decoded;
        }

        return $labelData;
    }

    /**
     * @return list<string>
     */
    private function fromPdf(string $bytes): array
    {
        try {
            $text = (new Parser)->parseContent($bytes)->getText();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), preg_split('/\R/', $text) ?: []),
            fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * Text fields from ZPL, skipping the ones that are barcode payloads.
     *
     * A `^FD` field belongs to whichever format command last preceded it. Under
     * `^A` it is text; under `^B` it is a barcode payload, and on a USPS-handoff
     * label that payload is the raw IMpb complete with Code 128 subset escapes.
     * Harvesting every `^FD` alike puts barcode data into the text stream and
     * lets a token match land on it.
     *
     * @return list<string>
     */
    private function fromZpl(string $bytes): array
    {
        // APIs hand back ZPL with escaped newlines interleaved with real ones.
        $zpl = str_replace('\\n', "\n", $bytes);

        $fields = [];
        $inBarcode = false;
        $offset = 0;

        while (($caret = strpos($zpl, '^', $offset)) !== false) {
            $command = strtoupper(substr($zpl, $caret + 1, 2));

            if ($command === 'FD') {
                $end = strpos($zpl, '^FS', $caret);
                $length = ($end === false ? strlen($zpl) : $end) - $caret - 3;
                $value = trim(substr($zpl, $caret + 3, $length));

                if ($value !== '' && ! $inBarcode) {
                    $fields[] = $value;
                }

                $offset = $end === false ? strlen($zpl) : $end + 3;

                continue;
            }

            // ^A selects a font, ^B a barcode -- except ^BY, which only sets bar
            // width and leaves whatever the last real command chose in place.
            if (str_starts_with($command, 'A')) {
                $inBarcode = false;
            } elseif (str_starts_with($command, 'B') && $command !== 'BY') {
                $inBarcode = true;
            }

            $offset = $caret + 1;
        }

        return $fields;
    }
}
