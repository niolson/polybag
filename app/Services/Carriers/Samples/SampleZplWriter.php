<?php

namespace App\Services\Carriers\Samples;

/**
 * Writes {@see SampleCanvas} labels as ZPL for a raw thermal printer.
 *
 * Each canvas becomes one `^XA … ^XZ` format, so a label with a customs
 * declaration fused into it prints as consecutive labels, as USPS's does.
 * Text uses the printer's built-in scalable font, so nothing has to be
 * downloaded to the printer first.
 */
class SampleZplWriter
{
    /** The print densities thermal label printers run at. */
    public const SUPPORTED_DPI = [203, 300];

    /**
     * @param  list<SampleCanvas>  $labels
     * @return string The ZPL program
     */
    public function render(array $labels, int $dpi): string
    {
        $dpi = in_array($dpi, self::SUPPORTED_DPI, true) ? $dpi : 203;

        return implode("\n", array_map(fn (SampleCanvas $label): string => $this->format($label, $dpi), $labels))."\n";
    }

    private function format(SampleCanvas $label, int $dpi): string
    {
        // ^CI28 reads field data as UTF-8; ^PW and ^LL size the label in dots.
        $commands = ['^XA', '^CI28', '^PW'.$this->dots($label->width, $dpi), '^LL'.$this->dots($label->height, $dpi), '^LH0,0'];

        foreach ($label->elements() as $element) {
            $commands[] = match ($element['type']) {
                'fill' => $this->graphicBox($element['x'], $element['y'], $element['width'], $element['height'], null, $dpi),
                'box' => $this->graphicBox($element['x'], $element['y'], $element['width'], $element['height'], $element['thickness'], $dpi),
                'text' => $this->textField($element, $dpi),
            };
        }

        $commands[] = '^XZ';

        return implode("\n", $commands);
    }

    /**
     * A `^GB` box. With no thickness it is filled: ZPL fills a box whose line
     * is as thick as its shorter side.
     */
    private function graphicBox(float $x, float $y, float $width, float $height, ?float $thickness, int $dpi): string
    {
        $widthDots = max(1, $this->dots($width, $dpi));
        $heightDots = max(1, $this->dots($height, $dpi));
        $line = $thickness === null ? min($widthDots, $heightDots) : max(1, $this->dots($thickness, $dpi));

        return sprintf('^FO%d,%d^GB%d,%d,%d,B,0^FS', $this->dots($x, $dpi), $this->dots($y, $dpi), $widthDots, $heightDots, $line);
    }

    /**
     * @param  array{type: 'text', x: float, y: float, size: float, text: string, bold: bool, inverse: bool, centerWidth: float|null}  $element
     */
    private function textField(array $element, int $dpi): string
    {
        $height = max(10, $this->dots($element['size'], $dpi));
        // Font 0 is condensed next to Helvetica; widening it keeps the layout's
        // proportions, and bold text a little wider still.
        $width = (int) round($height * ($element['bold'] ? 1.0 : 0.9));

        $field = sprintf('^FO%d,%d^A0N,%d,%d', $this->dots($element['x'], $dpi), $this->dots($element['y'], $dpi), $height, $width);

        if ($element['centerWidth'] !== null) {
            $field .= sprintf('^FB%d,1,0,C,0', $this->dots($element['centerWidth'], $dpi));
        }

        if ($element['inverse']) {
            $field .= '^FR';
        }

        return $field.'^FH\\^FD'.$this->escape($element['text']).'^FS';
    }

    /**
     * Field data with ZPL's control characters hex-escaped, so an address
     * containing a caret or tilde cannot end the field or start a command.
     */
    private function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\5C', '^' => '\\5E', '~' => '\\7E']);
    }

    private function dots(float $points, int $dpi): int
    {
        return (int) round($points * $dpi / 72);
    }
}
