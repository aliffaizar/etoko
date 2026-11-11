<?php

namespace App\Support;

use App\Models\CourierService;
use App\Models\Shipment;
use App\Models\ShipmentTrackingEvent;
use App\Services\Shipping\Data\ShippingQuote;
use Illuminate\Database\Eloquent\Collection;

/**
 * How shipments reach the screens (P2-E2-T4, T5).
 *
 * Three screens read a parcel — the panel's dispatch card, the customer's
 * order page and the customer's tracking page — and all three show the same
 * courier, the same waybill and the same timeline. Keeping the shapes together
 * is what stops the same status from being labelled two ways, or a cost being
 * read off a different column on one screen than another.
 *
 * Everything here reads **snapshots** off the shipment (ADR-007): a courier
 * renamed last week must not rename itself on a delivery note from last month.
 * The only live readings are the capability flags, which are about what may be
 * done now, and the courier's own tracking link, which only exists to offer a
 * link and is nullable for a courier that publishes none.
 *
 * The shapes mirror the TypeScript types in `resources/js/types/shipping.ts`.
 */
final class ShipmentPresenter
{
    /**
     * The parcel as the panel reads it.
     *
     * @param  array<string, bool>  $can  Capability flags the caller worked out from the policy.
     * @return array<string, mixed>
     */
    public static function detail(Shipment $shipment, array $can = []): array
    {
        return [
            ...self::summary($shipment),
            'notes' => $shipment->notes,
            'failure_reason' => $shipment->failure_reason,
            'external_id' => $shipment->external_id,
            'dispatched_by' => $shipment->relationLoaded('dispatchedBy')
                ? $shipment->dispatchedBy?->name
                : null,
            'tracking_synced_at' => $shipment->tracking_synced_at?->toIso8601String(),
            'is_trackable' => $shipment->isTrackable(),
            'timeline' => $shipment->relationLoaded('trackingEvents')
                ? self::timeline($shipment->trackingEvents)
                : [],
            'can' => $can,
        ];
    }

    /**
     * The parcel as the customer reads it.
     *
     * Deliberately narrower than `detail()`: the aggregator's booking id, the
     * name of the staff member who packed it and when a provider was last
     * asked are the shop's own workings, and a customer reading them would be
     * reading about us rather than about their parcel.
     *
     * @return array<string, mixed>
     */
    public static function forCustomer(Shipment $shipment): array
    {
        return [
            ...self::summary($shipment),
            'failure_reason' => $shipment->failure_reason,
            'timeline' => $shipment->relationLoaded('trackingEvents')
                ? self::timeline($shipment->trackingEvents)
                : [],
        ];
    }

    /**
     * What every screen shows about a parcel.
     *
     * @return array<string, mixed>
     */
    public static function summary(Shipment $shipment): array
    {
        return [
            'id' => $shipment->id,
            'order_id' => $shipment->order_id,
            'courier_name' => $shipment->courier_name,
            'service_name' => $shipment->service_name,
            'display_name' => "{$shipment->courier_name} — {$shipment->service_name}",
            'tracking_number' => $shipment->tracking_number,
            'tracking_url' => $shipment->externalTrackingUrl(),
            'cost' => $shipment->cost,
            'weight_gram' => $shipment->weight_gram,
            'status' => $shipment->status->value,
            'status_label' => $shipment->status->label(),
            'estimate_label' => $shipment->estimateLabel(),
            'is_open' => $shipment->isOpen(),
            'is_dispatched' => $shipment->isDispatched(),
            'shipped_at' => $shipment->shipped_at?->toIso8601String(),
            'delivered_at' => $shipment->delivered_at?->toIso8601String(),
            'created_at' => $shipment->created_at?->toIso8601String(),
        ];
    }

    /**
     * The parcel's timeline, oldest first — the sequence it happened in.
     *
     * @param  Collection<int, ShipmentTrackingEvent>  $events
     * @return array<int, array<string, mixed>>
     */
    public static function timeline(Collection $events): array
    {
        return $events->map(fn (ShipmentTrackingEvent $event): array => [
            'id' => $event->id,
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
            'description' => $event->description,
            'location' => $event->location,
            'source' => $event->source,
            'source_label' => $event->sourceLabel(),
            'event_at' => $event->event_at->toIso8601String(),
        ])->values()->all();
    }

    /**
     * The couriers a packer may dispatch with, priced where the shop has a
     * rate for this address (P2-E2-T4).
     *
     * **Every active service is offered, whether it was quoted or not.** A
     * dispatch does not need a quote — `shipments.cost` is what the customer
     * already agreed to pay and is never recomputed — so a parcel the rate
     * table has no rule for must still be sendable: the branch is standing at
     * the courier's counter with the goods in their hands.
     *
     * `cost` is therefore nullable, and that is the useful part: a figure
     * beside a service is what the shop *would* have charged for it, which is
     * how "the branch used a dearer courier than the customer paid for"
     * becomes a visible decision rather than an invisible one.
     *
     * @param  Collection<int, CourierService>  $services
     * @param  array<int, ShippingQuote>  $quotes
     * @return array<int, array<string, mixed>>
     */
    public static function serviceChoices(Collection $services, array $quotes = []): array
    {
        $costs = [];

        foreach ($quotes as $quote) {
            $costs[$quote->courierServiceId] = $quote->cost;
        }

        return $services->map(fn (CourierService $service): array => [
            'courier_service_id' => $service->getKey(),
            'courier_id' => $service->courier_id,
            'courier_name' => $service->courier->name,
            'service_name' => $service->name,
            'display_name' => $service->displayName(),
            'estimate_label' => $service->estimateLabel(),
            'description' => $service->description,
            // Null when no rate in the table reaches this address — see above.
            'cost' => $costs[$service->getKey()] ?? null,
        ])->values()->all();
    }

    /**
     * A courier option, as a picker reads it.
     *
     * The same shape on the checkout screen and on the panel's dispatch form,
     * so a packer choosing a courier and a shopper choosing one are looking at
     * the same list — which is what makes "the branch used a different
     * service" a visible decision rather than a typo.
     *
     * @param  array<int, ShippingQuote>  $quotes
     * @return array<int, array<string, mixed>>
     */
    public static function options(array $quotes): array
    {
        return array_map(static fn (ShippingQuote $quote): array => $quote->toArray(), $quotes);
    }
}
