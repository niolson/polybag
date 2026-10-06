<?php

namespace App\Services\Scanning;

use App\Enums\ScanCodeType;
use App\Enums\ScanCommand;
use App\Models\BoxSize;
use App\Models\Package;
use App\Models\Shipment;
use InvalidArgumentException;

/**
 * A PolyBag code: the install's prefix, a type token, then a body, as in
 * `PBS216` for Shipment 216 (ADR-0007). A scan with the prefix is always one:
 * if its token or body is not understood it is unrecognized, and it is never
 * looked up as anything else.
 */
final readonly class ScanCode
{
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
        $rest = substr($normalized, strlen(self::prefix()));

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
     * with the install's prefix, in any case. An operator-chosen code that does
     * could never be scanned as itself (ADR-0007, decision 6).
     */
    public static function claims(string $text): bool
    {
        return str_starts_with(strtoupper(trim($text)), self::prefix());
    }

    /**
     * The install's prefix, upper-cased. It starts with a letter: a leading
     * digit would claim a whole range of numeric UPCs.
     *
     * @throws InvalidArgumentException when SCAN_CODE_PREFIX is not a letter then up to three letters or digits
     */
    public static function prefix(): string
    {
        $prefix = strtoupper((string) config('app.scan_code_prefix'));

        if (preg_match('/^[A-Z][A-Z0-9]{0,3}$/', $prefix) !== 1) {
            throw new InvalidArgumentException("SCAN_CODE_PREFIX must be a letter followed by up to three letters or digits; '{$prefix}' is not.");
        }

        return $prefix;
    }

    public static function forShipment(Shipment $shipment): string
    {
        return self::prefix().ScanCodeType::Shipment->value.$shipment->getKey();
    }

    public static function forPackage(Package $package): string
    {
        return self::prefix().ScanCodeType::Package->value.$package->getKey();
    }

    public static function forBoxSize(BoxSize $boxSize): string
    {
        return self::prefix().ScanCodeType::BoxSize->value.$boxSize->getKey();
    }

    public static function forCommand(ScanCommand $command): string
    {
        return self::prefix().ScanCodeType::Command->value.$command->value;
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
