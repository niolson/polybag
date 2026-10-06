<?php

namespace App\Services\Scanning;

use App\Enums\ScanCodeType;
use App\Enums\ScanCommand;
use App\Models\BoxSize;
use App\Models\Package;
use App\Models\Shipment;

/**
 * A PolyBag code: `%`, a type token, then a body, as in `%S216` for
 * Shipment 216 (ADR-0007). A scan beginning with `%` is always one: if its
 * token or body is not understood it is unrecognized, and it is never looked
 * up as anything else.
 */
final readonly class ScanCode
{
    /**
     * The prefix on every PolyBag code. `%` is the one character outside
     * letters and digits that a scanner set to the wrong keyboard country
     * mangles only where it also mangles digits (ADR-0007, decision 1).
     */
    public const string PREFIX = '%';

    private function __construct(
        public string $scan,
        public ?ScanCodeType $type,
        public ?int $id = null,
        public ?string $name = null,
    ) {}

    /**
     * The PolyBag code a scan spells, or null for an external identifier (an
     * order reference, box code, SKU or UPC).
     */
    public static function parse(string $scan): ?self
    {
        if (! self::claims($scan)) {
            return null;
        }

        $normalized = strtoupper(trim($scan));
        $rest = substr($normalized, strlen(self::PREFIX));

        foreach (ScanCodeType::cases() as $type) {
            if (! str_starts_with($rest, $type->value)) {
                continue;
            }

            $body = substr($rest, strlen($type->value));

            if (preg_match($type->bodyPattern(), $body) !== 1) {
                break;
            }

            if ($type === ScanCodeType::Command) {
                return new self($normalized, $type, name: $body);
            }

            $id = (int) $body;

            return $id > 0 ? new self($normalized, $type, id: $id) : new self($normalized, null);
        }

        return new self($normalized, null);
    }

    /**
     * Whether a scan of this text would be read as a PolyBag code: it begins
     * with `%`. An operator-chosen code that does could never be scanned as
     * itself (ADR-0007, decision 6).
     */
    public static function claims(string $text): bool
    {
        return str_starts_with(trim($text), self::PREFIX);
    }

    public static function forShipment(Shipment $shipment): string
    {
        return self::PREFIX.ScanCodeType::Shipment->value.$shipment->getKey();
    }

    public static function forPackage(Package $package): string
    {
        return self::PREFIX.ScanCodeType::Package->value.$package->getKey();
    }

    public static function forBoxSize(BoxSize $boxSize): string
    {
        return self::PREFIX.ScanCodeType::BoxSize->value.$boxSize->getKey();
    }

    public static function forCommand(ScanCommand $command): string
    {
        return self::PREFIX.ScanCodeType::Command->value.$command->value;
    }

    public function isRecognized(): bool
    {
        return $this->type !== null;
    }

    /**
     * The built-in command this code names, or null.
     */
    public function command(): ?ScanCommand
    {
        return $this->type === ScanCodeType::Command ? ScanCommand::tryFrom((string) $this->name) : null;
    }
}
