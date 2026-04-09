<?php

namespace App\DataTransferObjects\Tracking;

use App\Enums\TrackingStatus;
use Carbon\CarbonImmutable;

readonly class TrackShipmentResponse
{
    /**
     * @param  array<int, TrackingEventData>  $events
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public bool $success,
        public bool $supported = true,
        public ?TrackingStatus $status = null,
        public ?CarbonImmutable $estimatedDeliveryAt = null,
        public ?CarbonImmutable $deliveredAt = null,
        public ?string $statusLabel = null,
        public ?string $message = null,
        public array $events = [],
        public array $details = [],
    ) {}

    /**
     * @param  array<int, TrackingEventData>  $events
     * @param  array<string, mixed>  $details
     */
    public static function success(
        TrackingStatus $status,
        array $events = [],
        ?CarbonImmutable $estimatedDeliveryAt = null,
        ?CarbonImmutable $deliveredAt = null,
        ?string $statusLabel = null,
        array $details = [],
    ): self {
        return new self(
            success: true,
            supported: true,
            status: $status,
            estimatedDeliveryAt: $estimatedDeliveryAt,
            deliveredAt: $deliveredAt,
            statusLabel: $statusLabel,
            events: $events,
            details: $details,
        );
    }

    public static function unsupported(string $message = 'Tracking is not supported for this carrier.'): self
    {
        return new self(
            success: false,
            supported: false,
            message: $message,
        );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function failure(string $message, array $details = []): self
    {
        return new self(
            success: false,
            supported: true,
            message: $message,
            details: $details,
        );
    }

    /**
     * @return array<int, array{timestamp: ?string, location: ?string, description: string, status_code: ?string, status: ?string, raw: array<string, mixed>}>
     */
    public function eventsToArray(): array
    {
        return array_map(
            fn (TrackingEventData $event): array => $event->toArray(),
            $this->events,
        );
    }
}
