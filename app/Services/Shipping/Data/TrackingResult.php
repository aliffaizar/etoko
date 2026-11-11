<?php

namespace App\Services\Shipping\Data;

use App\Enums\ShipmentStatus;

/**
 * What a courier says about where a parcel is (P2-E2-T2, T6, ADR-004).
 *
 * A whole history rather than a position, because that is what tracking APIs
 * return and because the customer's page is a timeline: asking for the latest
 * scan only would make a parcel that moved three times between two sweeps look
 * as though it teleported.
 *
 * `status` is the courier's reading of where the parcel is *now*, which is not
 * necessarily the last event in the list — some providers list newest first,
 * some oldest. `RecordShipmentTracking` takes this as the position and the
 * events as the record, and refuses to move a parcel backwards whichever order
 * they arrive in.
 *
 * **A failure to reach the courier is not a `TrackingResult`.** `track()`
 * throws, because a sweep that recorded "we could not ask" as a tracking event
 * would fill a customer's timeline with the state of our own network.
 */
final class TrackingResult
{
    /**
     * @param  array<int, TrackingEvent>  $events
     * @param  array<string, mixed>|null  $rawPayload
     */
    public function __construct(
        public readonly ShipmentStatus $status,
        public readonly array $events = [],
        public readonly ?string $receivedBy = null,
        public readonly ?array $rawPayload = null,
    ) {}

    /**
     * The events in the order they happened, oldest first.
     *
     * Sorted here rather than trusted from the provider: the order they arrive
     * in differs per API, and `RecordShipmentTracking` decides whether a scan
     * is news by comparing it against what it has already applied.
     *
     * @return array<int, TrackingEvent>
     */
    public function orderedEvents(): array
    {
        $events = $this->events;

        usort(
            $events,
            static fn (TrackingEvent $a, TrackingEvent $b): int => $a->eventAt <=> $b->eventAt,
        );

        return $events;
    }

    /**
     * Whether the courier reports the parcel as delivered.
     */
    public function isDelivered(): bool
    {
        return $this->status->completesDelivery();
    }
}
