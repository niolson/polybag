<?php

namespace App\DataTransferObjects\PackSlips;

/**
 * One print-bridge job of pack slips and the receipt that records it once the
 * bridge reports the job sent.
 */
final readonly class PackSlipPrintJob
{
    public function __construct(
        public string $pdf,
        public string $receipt,
        public int $count,
    ) {}

    /**
     * @return array{data: string, receipt: string, count: int}
     */
    public function toBrowserPayload(): array
    {
        return [
            'data' => base64_encode($this->pdf),
            'receipt' => $this->receipt,
            'count' => $this->count,
        ];
    }
}
