<?php

namespace App\DataTransferObjects\PostageSources;

/**
 * Which discovered service a rate is an offer of.
 *
 * `ObservedService` is the durable row; {@see ServiceObservation} is one
 * sighting on its way into that row; this is the same identity attached to a
 * *price*, which is the only form the selection paths ever see. It tells the
 * rate's source kind apart and names the service in a refusal.
 *
 * A rate that carries none of this is not "unidentified" — it is a rate for an
 * authored `CarrierService`, quoted directly, which is what every rate was
 * before discovery existed.
 *
 * Environment and channel type are deliberately absent. Neither is a
 * dimension of what automation may buy (ADR-0006, `carrier-catalog-reset/13`):
 * sandbox and production behave alike, and only Amazon's own orders are sold
 * through Buy Shipping.
 */
readonly class ObservedServiceIdentity
{
    public function __construct(
        public string $source,
        public string $externalCarrierId,
        public string $externalServiceId,
    ) {}

    /**
     * @return array{source: string, externalCarrierId: string, externalServiceId: string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'externalCarrierId' => $this->externalCarrierId,
            'externalServiceId' => $this->externalServiceId,
        ];
    }

    /**
     * A rate serialized before `carrier-catalog-reset/13` also carries an
     * environment and a channel type, which are ignored.
     *
     * @param  array{source: string, externalCarrierId: string, externalServiceId: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['source'],
            externalCarrierId: $data['externalCarrierId'],
            externalServiceId: $data['externalServiceId'],
        );
    }
}
