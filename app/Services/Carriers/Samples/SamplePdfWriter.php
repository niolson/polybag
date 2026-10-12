<?php

namespace App\Services\Carriers\Samples;

/**
 * Writes {@see SampleCanvas} pages as a small, uncompressed PDF.
 *
 * Hand-built rather than rendered through Gotenberg so a fake carrier answers
 * without a service behind it, in tests as in a demo. It needs nothing beyond
 * the two standard Helvetica faces every PDF reader carries, which is all a
 * sample label draws in.
 */
class SamplePdfWriter
{
    /** How far below the top of a line its baseline sits, as a share of the font size. */
    private const ASCENT = 0.78;

    /**
     * @param  list<SampleCanvas>  $pages
     * @return string The PDF bytes
     */
    public function render(array $pages): string
    {
        // 1 catalog, 2 page tree, 3 Helvetica, 4 Helvetica-Bold, then a page
        // and its content stream for each canvas.
        $objects = [];
        $kids = [];

        foreach ($pages as $index => $page) {
            $pageNumber = 5 + $index * 2;
            $kids[] = "{$pageNumber} 0 R";
            $content = $this->contentStream($page);

            $objects[$pageNumber] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $this->number($page->width),
                $this->number($page->height),
                $pageNumber + 1,
            );
            $objects[$pageNumber + 1] = '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream";
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function contentStream(SampleCanvas $page): string
    {
        $operations = [];

        foreach ($page->elements() as $element) {
            // PDF measures up from the bottom; the canvas measures down from the top.
            $operations[] = match ($element['type']) {
                'fill' => sprintf(
                    '0 g %s %s %s %s re f',
                    $this->number($element['x']),
                    $this->number($page->height - $element['y'] - $element['height']),
                    $this->number($element['width']),
                    $this->number($element['height']),
                ),
                'box' => sprintf(
                    '0 G %s w %s %s %s %s re S',
                    $this->number($element['thickness']),
                    $this->number($element['x'] + $element['thickness'] / 2),
                    $this->number($page->height - $element['y'] - $element['height'] + $element['thickness'] / 2),
                    $this->number($element['width'] - $element['thickness']),
                    $this->number($element['height'] - $element['thickness']),
                ),
                'text' => $this->textOperation($page, $element),
            };
        }

        return implode("\n", $operations);
    }

    /**
     * @param  array{type: 'text', x: float, y: float, size: float, text: string, bold: bool, inverse: bool, centerWidth: float|null}  $element
     */
    private function textOperation(SampleCanvas $page, array $element): string
    {
        $x = $element['x'];

        if ($element['centerWidth'] !== null) {
            $x += max(0, ($element['centerWidth'] - SampleCanvas::estimatedWidth($element['text'], $element['size'], $element['bold'])) / 2);
        }

        return sprintf(
            'BT %s g /%s %s Tf %s %s Td (%s) Tj ET',
            $element['inverse'] ? '1' : '0',
            $element['bold'] ? 'F2' : 'F1',
            $this->number($element['size']),
            $this->number($x),
            $this->number($page->height - $element['y'] - $element['size'] * self::ASCENT),
            $this->escape($element['text']),
        );
    }

    /**
     * A PDF string literal in the fonts' WinAnsi encoding: Latin accents
     * survive, anything WinAnsi has no glyph for prints as a question mark.
     */
    private function escape(string $text): string
    {
        $encoded = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return strtr($encoded, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => ' ', "\n" => ' ']);
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
