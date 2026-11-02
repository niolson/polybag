<?php

namespace App\Services\Carriers\Samples;

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Models\Package;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The documents a fake carrier hands back: a shipping label, customs paperwork
 * and an end-of-day form, each laid out like the real thing and filled from
 * the order it was bought for.
 *
 * Every page is unmistakably synthetic. Each one says SAMPLE in a black band
 * at the top and bottom, the label's barcode area holds the word SAMPLE and
 * no barcode, and nothing is encoded in any carrier symbology. A tracking
 * number is printed only as text, which no carrier scanner reads.
 */
class SampleDocuments
{
    public const MARKER = 'SAMPLE';

    private const NOT_POSTAGE = 'SAMPLE - NOT VALID POSTAGE - DO NOT SHIP';

    /** How many customs lines fit on a 4×6 declaration before the rest are summarized. */
    private const DECLARATION_LINES = 8;

    /** How many customs lines fit on a Letter invoice page. */
    private const INVOICE_LINES = 20;

    /** How many tracking numbers fit on one page of the end-of-day form. */
    private const MANIFEST_LINES_PER_PAGE = 36;

    /**
     * Half an inch, in points. An office printer cannot print the outer quarter
     * inch or so of a Letter page, so a band drawn to the edge loses its top
     * half; a thermal label printer has no such margin.
     */
    private const LETTER_MARGIN = 36;

    /**
     * The 4×6 shipping label: sender, service, recipient and tracking number,
     * where a carrier prints them.
     */
    public function shippingLabel(ShipRequest $request, string $carrier, string $service, string $trackingNumber): SampleCanvas
    {
        $label = SampleCanvas::label();
        $label->banner(0, 16, 8, self::NOT_POSTAGE);

        $label->text(10, 22, 6.5, 'FROM:', bold: true);
        $this->address($label, $request->fromAddress, 10, 31, 7, 8.5, 162);

        $label->box(180, 22, 100, 50, 1.5);
        $label->text(180, 28, 15, mb_strtoupper($carrier), bold: true, width: 100, center: true);
        $label->text(180, 48, 7, 'SHIP DATE '.($request->shipDate ?? now())->format('m/d/Y'), width: 100, center: true);
        $label->text(180, 59, 7, sprintf('%s LB', $this->weight($request->packageData->weight)), width: 100, center: true);

        $label->fill(0, 78, $label->width, 24);
        $label->text(0, 84, 12, mb_strtoupper($service), bold: true, inverse: true, width: $label->width, center: true);

        $label->text(14, 110, 8, 'SHIP TO:', bold: true);
        $this->address($label, $request->toAddress, 28, 122, 12, 14.5, 252);

        $label->fill(0, 222, $label->width, 2);
        $label->box(18, 230, 252, 92, 2);
        $label->text(18, 246, 44, self::MARKER, bold: true, width: 252, center: true);
        $label->text(18, 296, 8, 'NO BARCODE - THIS LABEL CANNOT BE SCANNED', bold: true, width: 252, center: true);

        $label->text(18, 330, 8, 'TRACKING #', bold: true);
        $label->text(18, 342, 14, $this->groupedTrackingNumber($trackingNumber), bold: true);
        $label->fill(0, 364, $label->width, 2);

        if ($request->references !== []) {
            $label->text(18, 372, 8, 'REF: '.implode(' / ', $request->references));
        }

        $label->banner(400, 32, 18, self::MARKER.' - DEMO ONLY');

        return $label;
    }

    /**
     * The customs declaration a carrier prints as part of the label, as USPS
     * does its CP72 — another 4×6 page after the label itself.
     */
    public function customsDeclaration(ShipRequest $request, string $carrier, string $trackingNumber): SampleCanvas
    {
        $label = SampleCanvas::label();
        $label->banner(0, 16, 8, self::NOT_POSTAGE);

        $label->text(0, 22, 12, 'CUSTOMS DECLARATION', bold: true, width: $label->width, center: true);
        $label->text(0, 38, 7, sprintf('%s %s - %s', self::MARKER, mb_strtoupper($carrier), $this->groupedTrackingNumber($trackingNumber)), width: $label->width, center: true);

        $label->text(10, 52, 6.5, 'FROM:', bold: true);
        $this->address($label, $request->fromAddress, 10, 61, 6.5, 8, 130);
        $label->text(148, 52, 6.5, 'TO:', bold: true);
        $this->address($label, $request->toAddress, 148, 61, 6.5, 8, 130);

        $label->fill(0, 116, $label->width, 1.5);
        $label->text(10, 122, 6.5, 'DESCRIPTION', bold: true);
        $label->text(160, 122, 6.5, 'QTY', bold: true);
        $label->text(184, 122, 6.5, 'LB', bold: true);
        $label->text(214, 122, 6.5, 'VALUE', bold: true);
        $label->text(250, 122, 6.5, 'ORIG', bold: true);

        $y = 134;

        foreach (array_slice($request->customsItems, 0, self::DECLARATION_LINES) as $item) {
            $label->text(10, $y, 6.5, $item->description, width: 146);
            $label->text(160, $y, 6.5, (string) $item->quantity);
            $label->text(184, $y, 6.5, $this->weight($item->weight * $item->quantity));
            $label->text(214, $y, 6.5, $this->money($item->unitValue * $item->quantity));
            $label->text(250, $y, 6.5, $item->countryOfOrigin ?? '-');
            $y += 10;
        }

        $remaining = count($request->customsItems) - self::DECLARATION_LINES;

        if ($remaining > 0) {
            $label->text(10, $y, 6.5, "... and {$remaining} more ".($remaining === 1 ? 'line' : 'lines'));
            $y += 10;
        }

        $label->fill(0, $y + 2, $label->width, 1);
        $label->text(10, $y + 8, 7.5, sprintf(
            'TOTAL: %s LB / %s '.$request->customsCurrency,
            $this->weight($this->totalWeight($request->customsItems)),
            $this->money($this->totalValue($request->customsItems)),
        ), bold: true);

        $label->box(18, 300, 252, 80, 2);
        $label->text(18, 318, 40, self::MARKER, bold: true, width: 252, center: true);
        $label->text(18, 362, 7, 'NOT A CUSTOMS DECLARATION', bold: true, width: 252, center: true);

        $label->banner(400, 32, 18, self::MARKER.' - DEMO ONLY');

        return $label;
    }

    /**
     * The commercial invoice a carrier returns beside the label, on Letter
     * paper for the document printer, as UPS does.
     */
    public function commercialInvoice(ShipRequest $request, string $carrier, string $trackingNumber): SampleCanvas
    {
        $page = SampleCanvas::letter();
        $page->banner(self::LETTER_MARGIN, 22, 12, self::NOT_POSTAGE, inset: self::LETTER_MARGIN);

        $page->text(40, 70, 20, 'COMMERCIAL INVOICE', bold: true);
        $page->text(40, 96, 10, sprintf(
            '%s  |  Carrier: %s  |  Tracking: %s  |  Date: %s',
            self::MARKER,
            $carrier,
            $trackingNumber,
            ($request->shipDate ?? now())->format('m/d/Y'),
        ));

        $page->box(40, 120, 260, 110);
        $page->text(48, 128, 9, 'SHIPPER / EXPORTER', bold: true);
        $this->address($page, $request->fromAddress, 48, 144, 10, 13, 244);

        $page->box(312, 120, 260, 110);
        $page->text(320, 128, 9, 'CONSIGNEE', bold: true);
        $this->address($page, $request->toAddress, 320, 144, 10, 13, 244);

        $page->fill(40, 246, 532, 20);
        $page->text(46, 251, 9, 'DESCRIPTION', bold: true, inverse: true);
        $page->text(290, 251, 9, 'HS CODE', bold: true, inverse: true);
        $page->text(360, 251, 9, 'ORIGIN', bold: true, inverse: true);
        $page->text(410, 251, 9, 'QTY', bold: true, inverse: true);
        $page->text(450, 251, 9, 'WEIGHT', bold: true, inverse: true);
        $page->text(510, 251, 9, 'VALUE', bold: true, inverse: true);

        $y = 274;

        foreach (array_slice($request->customsItems, 0, self::INVOICE_LINES) as $item) {
            $page->text(46, $y, 9, $item->description, width: 236);
            $page->text(290, $y, 9, $item->hsTariffNumber ?? '-');
            $page->text(360, $y, 9, $item->countryOfOrigin ?? '-');
            $page->text(410, $y, 9, (string) $item->quantity);
            $page->text(450, $y, 9, $this->weight($item->weight * $item->quantity).' lb');
            $page->text(510, $y, 9, $this->money($item->unitValue * $item->quantity));
            $y += 16;
        }

        $remaining = count($request->customsItems) - self::INVOICE_LINES;

        if ($remaining > 0) {
            $page->text(46, $y, 9, "... and {$remaining} more ".($remaining === 1 ? 'line' : 'lines'));
            $y += 16;
        }

        $page->fill(40, $y + 4, 532, 1.5);
        $page->text(46, $y + 12, 10, sprintf(
            'TOTAL: %s lb / %s '.$request->customsCurrency,
            $this->weight($this->totalWeight($request->customsItems)),
            $this->money($this->totalValue($request->customsItems)),
        ), bold: true);

        $page->text(40, 650, 9, 'Produced by a PolyBag sample carrier. It is not a customs declaration');
        $page->text(40, 663, 9, 'and must not accompany a parcel.');

        $page->banner(686, 70, 40, self::MARKER.' - DEMO ONLY', inset: self::LETTER_MARGIN);

        return $page;
    }

    /**
     * The end-of-day form for a day's Packages: the date, the entry address
     * and every tracking number on it, over as many Letter pages as it takes.
     *
     * @param  Collection<int, Package>  $packages
     * @return list<SampleCanvas>
     */
    public function manifest(string $carrier, string $manifestNumber, AddressData $from, CarbonImmutable $mailingDate, Collection $packages): array
    {
        $chunks = $packages->values()->chunk(self::MANIFEST_LINES_PER_PAGE)->values();
        $pageCount = max(1, $chunks->count());
        $pages = [];

        foreach ($chunks->whenEmpty(fn (Collection $c): Collection => collect([collect()])) as $index => $chunk) {
            $page = SampleCanvas::letter();
            $page->banner(self::LETTER_MARGIN, 22, 12, self::MARKER.' END-OF-DAY FORM - NOT ACCEPTED BY ANY CARRIER', inset: self::LETTER_MARGIN);

            $page->text(40, 70, 20, mb_strtoupper($carrier).' END-OF-DAY MANIFEST', bold: true);
            $page->text(40, 96, 10, sprintf(
                'Manifest %s  |  Mailing date %s  |  %d package(s)  |  Page %d of %d',
                $manifestNumber,
                $mailingDate->format('m/d/Y'),
                $packages->count(),
                $index + 1,
                $pageCount,
            ));

            $page->text(40, 116, 9, 'ENTRY ADDRESS', bold: true);
            $this->address($page, $from, 40, 130, 10, 13, 400);

            $page->fill(40, 204, 532, 18);
            $page->text(46, 208, 9, '#', bold: true, inverse: true);
            $page->text(80, 208, 9, 'TRACKING NUMBER', bold: true, inverse: true);
            $page->text(330, 208, 9, 'SERVICE', bold: true, inverse: true);
            $page->text(500, 208, 9, 'WEIGHT', bold: true, inverse: true);

            $y = 228;

            foreach ($chunk->values() as $row => $package) {
                $page->text(46, $y, 9, (string) ($index * self::MANIFEST_LINES_PER_PAGE + $row + 1));
                $page->text(80, $y, 9, (string) $package->tracking_number);
                $page->text(330, $y, 9, (string) ($package->service ?? ''), width: 160);
                $page->text(500, $y, 9, $this->weight((float) $package->weight).' lb');
                $y += 12.5;
            }

            $page->banner(686, 70, 40, self::MARKER.' - DEMO ONLY', inset: self::LETTER_MARGIN);
            $pages[] = $page;
        }

        return $pages;
    }

    /**
     * Name, company, street lines, city line and country, one per line,
     * skipping whatever the address does not have.
     */
    private function address(SampleCanvas $canvas, AddressData $address, float $x, float $y, float $size, float $leading, float $width): void
    {
        $cityLine = trim(sprintf(
            '%s%s %s',
            $address->city,
            $address->stateOrProvince ? ', '.$address->stateOrProvince : '',
            $address->postalCode ?? '',
        ));

        $lines = array_filter([
            trim($address->firstName.' '.$address->lastName),
            $address->company,
            $address->streetAddress,
            $address->streetAddress2,
            $cityLine,
            $address->country,
        ], fn (?string $line): bool => $line !== null && trim($line) !== '');

        foreach (array_values($lines) as $index => $line) {
            $canvas->text($x, $y + $index * $leading, $size, mb_strtoupper($line), bold: $index === 0, width: $width);
        }
    }

    private function groupedTrackingNumber(string $trackingNumber): string
    {
        return trim(chunk_split($trackingNumber, 4, ' '));
    }

    /** @param  array<int, CustomsItem>  $items */
    private function totalWeight(array $items): float
    {
        return array_sum(array_map(fn (CustomsItem $item): float => $item->weight * $item->quantity, $items));
    }

    /** @param  array<int, CustomsItem>  $items */
    private function totalValue(array $items): float
    {
        return array_sum(array_map(fn (CustomsItem $item): float => $item->unitValue * $item->quantity, $items));
    }

    private function weight(float $pounds): string
    {
        return number_format($pounds, 2);
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2);
    }
}
