<?php

namespace App\DataTransferObjects\PackageLabels;

final readonly class LabelVoidResult
{
    /**
     * @param  bool  $voidedAtSource  Whether the label is void at the source that sold it — voided now, or recorded as voided there already — even when PolyBag failed to record it
     */
    public function __construct(
        public bool $success,
        public string $title,
        public string $message,
        public ?string $warning = null,
        public bool $voidedAtSource = false,
    ) {}

    /**
     * @param  string|null  $warning  something the void could not put right itself, for the operator to
     */
    public static function success(?string $message = null, ?string $warning = null): self
    {
        return new self(
            success: true,
            title: 'Label voided',
            message: $message ?? 'The label has been voided.',
            warning: $warning,
            voidedAtSource: true,
        );
    }

    /**
     * The source voided the label, but PolyBag could not record it: the
     * package still reads as shipped on a label that no longer exists.
     */
    public static function voidedNotRecorded(string $message): self
    {
        return new self(
            success: false,
            title: 'Voided, not recorded',
            message: $message,
            voidedAtSource: true,
        );
    }

    public static function failure(string $title, string $message): self
    {
        return new self(
            success: false,
            title: $title,
            message: $message,
        );
    }
}
