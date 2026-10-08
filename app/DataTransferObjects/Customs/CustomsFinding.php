<?php

namespace App\DataTransferObjects\Customs;

use App\Enums\CustomsFindingSeverity;

/**
 * One thing `CustomsReadiness` found wrong, or worth knowing, about a label's
 * customs data (ADR-0008 decision 7).
 *
 * @param  string  $code  A stable identifier for the rule, such as `origin_missing`
 * @param  string  $title  The heading a refused purchase carries
 * @param  list<string>  $lines  The offending customs lines, by description, when the finding is about lines
 * @param  string|null  $fixUrl  Where the fix is made, when there is one place to make it
 * @param  string|null  $fixLabel  The link's text
 */
readonly class CustomsFinding
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public CustomsFindingSeverity $severity,
        public string $code,
        public string $title,
        public string $message,
        public array $lines = [],
        public ?string $fixUrl = null,
        public ?string $fixLabel = null,
    ) {}

    public function isBlock(): bool
    {
        return $this->severity === CustomsFindingSeverity::Block;
    }

    /**
     * @return array{severity: string, code: string, title: string, message: string, lines: list<string>, fixUrl: string|null, fixLabel: string|null}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'code' => $this->code,
            'title' => $this->title,
            'message' => $this->message,
            'lines' => $this->lines,
            'fixUrl' => $this->fixUrl,
            'fixLabel' => $this->fixLabel,
        ];
    }
}
