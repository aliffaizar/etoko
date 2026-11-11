<?php

namespace App\Services\Shipping\Data;

use App\Enums\ShipmentStatus;
use Carbon\CarbonInterface;

/**
 * One scan, as a driver read it (P2-E2-T2, T6).
 *
 * The provider's own words are kept in `description` and the provider's own
 * vocabulary is mapped to `ShipmentStatus` by the driver — because "MANIFESTED",
 * "ON PROCESS" and "received at origin gateway" all mean the same thing to this
 * application and nothing to each other. Mapping in the driver is what keeps
 * `RecordShipmentTracking` and the customer's timeline free of any one
 * courier's dialect.
 *
 * `eventAt` is the courier's timestamp, never the moment we asked: a sweep at
 * 09:00 learning about a 17:40 scan has to place it at 17:40, or the timeline
 * reads as though the parcel stood still overnight and then moved twice. It is
 * also one third of the natural key that makes the sweep idempotent — see the
 * unique index on `shipment_tracking_events`.
 */
final class TrackingEvent
{
    public function __construct(
        public readonly ShipmentStatus $status,
        public readonly string $description,
        public readonly CarbonInterface $eventAt,
        public readonly ?string $location = null,
    ) {}

    /**
     * The row this event becomes on a shipment's timeline.
     *
     * @return array<string, mixed>
     */
    public function attributes(int $shipmentId, string $source): array
    {
        return [
            'shipment_id' => $shipmentId,
            'status' => $this->status,
            // Trimmed and capped to the column, because this is a courier's
            // free text: a provider that returns a paragraph must not fail the
            // insert that is carrying the other thirty-nine scans with it.
            'description' => mb_substr(trim($this->description), 0, 255),
            'location' => $this->location === null ? null : mb_substr(trim($this->location), 0, 255),
            'source' => $source,
            'event_at' => $this->eventAt,
        ];
    }
}
