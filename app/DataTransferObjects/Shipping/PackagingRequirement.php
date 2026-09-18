<?php

namespace App\DataTransferObjects\Shipping;

use App\Enums\CarrierPackaging;
use InvalidArgumentException;

/**
 * Which carrier packaging a rate is valid in — ADR-0005 decision 3.
 *
 * The Package's identity is exact (one {@see CarrierPackaging}, or null for
 * the packer's own packaging); the rate's requirement may match several. The
 * asymmetry is forced by the data: a USPS serviceId names one envelope or box,
 * but Amazon's FedEx One Rate offers say only "FedEx-supplied packaging" and
 * never which.
 *
 * The adapter that produced the rate sets this, because it is the only party
 * that knows. Everything downstream — the shared filter at rate shopping, the
 * purchase re-check — asks {@see accepts()} and nothing else.
 */
readonly class PackagingRequirement
{
    /**
     * The key this travels under inside a `RateResponse::$metadata` array and
     * an `OfferDraft::$rateMetadata` array, so an offer restores the
     * requirement without a column of its own.
     */
    public const string RATE_METADATA_KEY = 'packagingRequirement';

    private const string KIND_SHIPPER_PACKAGING = 'shipper_packaging';

    private const string KIND_EXACTLY = 'exactly';

    private const string KIND_ANY_OF = 'any_of';

    /**
     * @param  list<CarrierPackaging>  $packagings  Empty only for the shipper's own packaging.
     */
    private function __construct(
        private string $kind,
        private array $packagings,
    ) {}

    /**
     * Valid only in the packer's own packaging — never in anything a carrier supplies.
     */
    public static function shipperPackaging(): self
    {
        return new self(self::KIND_SHIPPER_PACKAGING, []);
    }

    /**
     * Valid only in this one carrier packaging.
     */
    public static function exactly(CarrierPackaging $packaging): self
    {
        return new self(self::KIND_EXACTLY, [$packaging]);
    }

    /**
     * Valid in any of these carrier packagings, and never in the packer's own.
     *
     * @throws InvalidArgumentException when no packaging is listed: a rate valid in nothing is a programming error, not a requirement.
     */
    public static function anyOf(CarrierPackaging ...$packagings): self
    {
        if ($packagings === []) {
            throw new InvalidArgumentException('anyOf() needs at least one carrier packaging.');
        }

        $distinct = [];

        foreach ($packagings as $packaging) {
            $distinct[$packaging->value] = $packaging;
        }

        return new self(self::KIND_ANY_OF, array_values($distinct));
    }

    public function accepts(?CarrierPackaging $packaging): bool
    {
        if ($packaging === null) {
            return $this->isShipperPackaging();
        }

        return in_array($packaging, $this->packagings, true);
    }

    public function isShipperPackaging(): bool
    {
        return $this->kind === self::KIND_SHIPPER_PACKAGING;
    }

    /**
     * The packaging named, for a message to the packer.
     */
    public function describe(): string
    {
        if ($this->isShipperPackaging()) {
            return 'your own packaging';
        }

        $labels = array_map(fn (CarrierPackaging $packaging): string => $packaging->getLabel(), $this->packagings);

        if (count($labels) === 1) {
            return $labels[0];
        }

        return 'one of '.implode(', ', $labels);
    }

    /**
     * @return array{kind: string, packagings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'packagings' => array_map(fn (CarrierPackaging $packaging): string => $packaging->value, $this->packagings),
        ];
    }

    /**
     * Lossless round-trip from {@see toArray()}.
     *
     * @param  array{kind?: string, packagings?: list<string>}  $data
     */
    public static function fromArray(array $data): self
    {
        $packagings = array_map(
            fn (string $value): CarrierPackaging => CarrierPackaging::from($value),
            $data['packagings'] ?? [],
        );

        return match ($data['kind'] ?? self::KIND_SHIPPER_PACKAGING) {
            self::KIND_EXACTLY => self::exactly($packagings[0]),
            self::KIND_ANY_OF => self::anyOf(...$packagings),
            default => self::shipperPackaging(),
        };
    }

    /**
     * The rate's metadata with this requirement stored in it, under
     * {@see RATE_METADATA_KEY}, on its way onto an offer.
     *
     * The adapters that read the metadata at purchase read their own keys and
     * ignore this one; it is there so that the rate restored from the offer
     * carries the requirement the source stamped at quote time, for display,
     * while the purchase re-check still asks the adapter.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function intoRateMetadata(array $metadata): array
    {
        return [...$metadata, self::RATE_METADATA_KEY => $this->toArray()];
    }

    /**
     * The requirement stored with an offer's rate metadata.
     *
     * A missing key reads as the shipper's own packaging, which is the safe
     * direction: it accepts nothing a carrier supplies.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function fromRateMetadata(array $metadata): self
    {
        $stored = $metadata[self::RATE_METADATA_KEY] ?? null;

        return is_array($stored) ? self::fromArray($stored) : self::shipperPackaging();
    }
}
