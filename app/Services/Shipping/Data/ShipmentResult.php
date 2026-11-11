<?php

namespace App\Services\Shipping\Data;

/**
 * What came of booking a parcel with a courier (P2-E2-T2, ADR-004).
 *
 * Two outcomes, and they are not degrees of the same thing:
 *
 * - **`booked`** — somebody has issued a waybill. An aggregator answers this,
 *   and the number it gives back is the number the customer will track.
 * - **`manual`** — there is nobody to book with. The manual driver always
 *   answers this: the branch goes to the counter, comes back with a receipt,
 *   and types the number in. The driver saying so out loud is what stops the
 *   dispatch form pretending it called somebody.
 *
 * A booking that is *refused* is not an outcome here — it throws
 * `ShippingFailedException`, because `createShipment()` is a foreground request
 * somebody is waiting on and the only useful answer is a sentence naming what
 * to do instead.
 */
final class ShipmentResult
{
    public const OUTCOME_BOOKED = 'booked';

    public const OUTCOME_MANUAL = 'manual';

    /**
     * @param  array<string, mixed>|null  $rawPayload
     */
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $trackingNumber = null,
        public readonly ?string $externalId = null,
        public readonly ?array $rawPayload = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $rawPayload
     */
    public static function booked(
        string $trackingNumber,
        ?string $externalId = null,
        ?array $rawPayload = null,
    ): self {
        return new self(self::OUTCOME_BOOKED, $trackingNumber, $externalId, $rawPayload);
    }

    /**
     * No provider to book with: the branch brings the waybill number back from
     * the counter.
     */
    public static function manual(?string $trackingNumber = null): self
    {
        return new self(self::OUTCOME_MANUAL, $trackingNumber);
    }

    public function wasBooked(): bool
    {
        return $this->outcome === self::OUTCOME_BOOKED;
    }

    /**
     * The columns this result fills in on the shipment row.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return array_filter(
            [
                'tracking_number' => $this->trackingNumber,
                'external_id' => $this->externalId,
                'raw_payload' => $this->rawPayload,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
