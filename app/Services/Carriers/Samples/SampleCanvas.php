<?php

namespace App\Services\Carriers\Samples;

/**
 * One page of a sample document, drawn in points from the top-left corner.
 *
 * Fake carriers print labels, customs documents and manifests that have to
 * come out of both a PDF driver and a raw ZPL printer. Recording the page as a
 * handful of primitives — text, outlined boxes and filled boxes — lets
 * {@see SamplePdfWriter} and {@see SampleZplWriter} draw the same layout, so a
 * workstation printing ZPL sees the same label as one printing PDF.
 *
 * There is deliberately no barcode primitive. A sample must never carry
 * anything a carrier scanner could read as postage.
 *
 * @phpstan-type SampleElement array{type: 'text', x: float, y: float, size: float, text: string, bold: bool, inverse: bool, centerWidth: float|null}|array{type: 'box', x: float, y: float, width: float, height: float, thickness: float}|array{type: 'fill', x: float, y: float, width: float, height: float}
 */
class SampleCanvas
{
    /** A 4×6 thermal label. */
    public const LABEL_WIDTH = 288.0;

    public const LABEL_HEIGHT = 432.0;

    /** US Letter, for documents that go to the document printer. */
    public const LETTER_WIDTH = 612.0;

    public const LETTER_HEIGHT = 792.0;

    /** @var list<SampleElement> */
    private array $elements = [];

    public function __construct(
        public readonly float $width,
        public readonly float $height,
    ) {}

    public static function label(): self
    {
        return new self(self::LABEL_WIDTH, self::LABEL_HEIGHT);
    }

    public static function letter(): self
    {
        return new self(self::LETTER_WIDTH, self::LETTER_HEIGHT);
    }

    /**
     * Write one line of text whose top-left corner is at ($x, $y).
     *
     * $width is the room the line has, to the page edge when not given; text
     * longer than that is cut, since neither renderer wraps. With $center the
     * line is centered in that span instead of starting at $x.
     */
    public function text(float $x, float $y, float $size, string $text, bool $bold = false, bool $inverse = false, ?float $width = null, bool $center = false): self
    {
        $room = $width ?? ($this->width - $x - 8);

        $this->elements[] = [
            'type' => 'text',
            'x' => $x,
            'y' => $y,
            'size' => $size,
            'text' => self::fit($text, $room, $size, $bold),
            'bold' => $bold,
            'inverse' => $inverse,
            'centerWidth' => $center ? $room : null,
        ];

        return $this;
    }

    public function box(float $x, float $y, float $width, float $height, float $thickness = 1.0): self
    {
        $this->elements[] = ['type' => 'box', 'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height, 'thickness' => $thickness];

        return $this;
    }

    public function fill(float $x, float $y, float $width, float $height): self
    {
        $this->elements[] = ['type' => 'fill', 'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];

        return $this;
    }

    /**
     * A black band across the page with white text centered in it. The SAMPLE
     * banners are drawn this way so they read from across a room.
     */
    /**
     * A black band with centered white text, across the page less `$inset` on each side.
     * A label runs it edge to edge; a Letter page insets it past the printer's unprintable margin.
     */
    public function banner(float $y, float $height, float $size, string $text, float $inset = 0): self
    {
        $width = $this->width - 2 * $inset;
        $this->fill($inset, $y, $width, $height);

        return $this->text($inset, $y + ($height - $size) / 2, $size, $text, bold: true, inverse: true, width: $width, center: true);
    }

    /** @return list<SampleElement> */
    public function elements(): array
    {
        return $this->elements;
    }

    /**
     * Roughly how wide a line of Helvetica is, in points.
     *
     * An average glyph width rather than real font metrics: the renderers only
     * need it to center a line and to stop one running off the page.
     */
    public static function estimatedWidth(string $text, float $size, bool $bold): float
    {
        return mb_strlen($text) * self::characterWidth($size, $bold);
    }

    private static function characterWidth(float $size, bool $bold): float
    {
        return $size * ($bold ? 0.62 : 0.55);
    }

    private static function fit(string $text, float $room, float $size, bool $bold): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $limit = max(4, (int) floor($room / self::characterWidth($size, $bold)));

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 3)).'...' : $text;
    }
}
