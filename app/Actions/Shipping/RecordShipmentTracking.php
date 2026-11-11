<?php

namespace App\Actions\Shipping;

use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\IllegalOrderTransitionException;
use App\Exceptions\ShippingFailedException;
use App\Models\Shipment;
use App\Models\ShipmentTrackingEvent;
use App\Models\User;
use App\Scopes\BranchScope;
use App\Services\Shipping\Data\TrackingEvent;
use App\Services\Shipping\Data\TrackingResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only way a parcel's position changes (P2-E2-T4, T5, T6).
 *
 * Four things happen together or not at all, which is why they live in one
 * action rather than in each screen and job that learns something about a
 * parcel:
 *
 * 1. **The timeline gets its rows**, append-only, idempotently. A courier
 *    returns its *whole* history on every call, so the sweep would otherwise
 *    append the same forty scans every ten minutes; the unique index on
 *    `(shipment_id, status, event_at)` is the natural key that makes a repeat
 *    a no-op. There is no provider event id to key on — most Indonesian
 *    tracking APIs do not issue one.
 * 2. **The parcel's status moves forward only.** Couriers report scans out of
 *    order and sometimes re-report old ones; a late "in transit" arriving
 *    after "delivered" must not un-deliver a parcel.
 *    `ShipmentStatus::progress()` is what decides, which is why the enum has
 *    one.
 * 3. **The moments are stamped** — `delivered_at`, `failure_reason`,
 *    `tracking_synced_at` — as a denormalisation of the timeline, so a listing
 *    can sort by "when did it arrive?" without a subquery.
 * 4. **The order follows the parcel, but only one way.** A delivered parcel
 *    moves the order to `delivered`, because that is the shop's obligation
 *    discharged. A failed or returned one does **not** move it: the goods are
 *    somewhere between the courier and the shelf, and what to do about it is a
 *    return document (`P2-E4`) rather than a status. The order staying
 *    `shipped` with a parcel that says `returned` is the honest reading of
 *    that, and the two columns exist so they can disagree.
 *
 * The shipment is re-read under a row lock first. The sweep and a staff member
 * updating the same parcel then queue, instead of both deciding what the
 * status should be from the same stale read.
 */
final class RecordShipmentTracking
{
    /** How many timeline rows the most recent call actually appended. */
    public private(set) int $appendedEvents = 0;

    public function __construct(private readonly TransitionOrderStatus $transitionOrderStatus) {}

    /**
     * Apply what a courier reported (P2-E2-T6).
     *
     * @throws IllegalOrderTransitionException when the order cannot follow the parcel.
     */
    public function handle(Shipment $shipment, TrackingResult $result): Shipment
    {
        return $this->apply(
            shipment: $shipment,
            status: $result->status,
            events: $result->orderedEvents(),
            source: ShipmentTrackingEvent::SOURCE_COURIER,
            actor: null,
            rawPayload: $result->rawPayload,
            synced: true,
        );
    }

    /**
     * Apply a position a branch typed in (P2-E2-T4).
     *
     * The branch's own words are the event's description, because this is the
     * whole timeline for a manual courier and "Dalam Perjalanan" with nothing
     * beside it tells a waiting customer nothing. The status label is the
     * fallback rather than the content.
     *
     * `tracking_synced_at` is deliberately **not** touched: it records when a
     * provider was last asked, and a staff member typing a status is not an
     * answer from a provider. Writing it here would make the sweep in
     * `P2-E2-T6` skip exactly the parcels somebody is worried enough about to
     * be updating by hand.
     *
     * @throws ShippingFailedException when the parcel has not left the branch yet.
     * @throws IllegalOrderTransitionException when the order cannot follow the parcel.
     */
    public function manual(
        Shipment $shipment,
        ShipmentStatus $status,
        ?string $description = null,
        ?string $location = null,
        ?User $actor = null,
        ?CarbonInterface $moment = null,
    ): Shipment {
        if (! $shipment->isDispatched()) {
            throw ShippingFailedException::unsupported(
                'Pengiriman ini belum diserahkan ke kurir, jadi statusnya belum dapat diperbarui. '
                    .'Catat nomor resinya terlebih dahulu.',
            );
        }

        $event = new TrackingEvent(
            status: $status,
            description: $description ?? $status->label(),
            eventAt: $moment ?? now(),
            location: $location,
        );

        return $this->apply(
            shipment: $shipment,
            status: $status,
            events: [$event],
            source: ShipmentTrackingEvent::SOURCE_BRANCH,
            actor: $actor,
            rawPayload: null,
            synced: false,
        );
    }

    /**
     * @param  array<int, TrackingEvent>  $events
     * @param  array<string, mixed>|null  $rawPayload
     */
    private function apply(
        Shipment $shipment,
        ShipmentStatus $status,
        array $events,
        string $source,
        ?User $actor,
        ?array $rawPayload,
        bool $synced,
    ): Shipment {
        $this->appendedEvents = 0;

        return DB::transaction(function () use ($shipment, $status, $events, $source, $actor, $rawPayload, $synced): Shipment {
            $locked = $this->lock($shipment);

            foreach ($events as $event) {
                $this->append($locked, $event, $source);
            }

            $moved = $status->progress() > $locked->status->progress();

            $locked->fill([
                ...($moved ? $this->advanceTo($locked, $status) : []),
                ...($synced ? ['tracking_synced_at' => now()] : []),
                ...($rawPayload === null ? [] : ['raw_payload' => $rawPayload]),
            ])->save();

            if ($moved && $status->completesDelivery()) {
                $this->markOrderDelivered($locked, $actor);
            }

            return $locked;
        });
    }

    /**
     * One timeline row, or nothing when it is already there.
     *
     * The natural key is matched to the second, which is the resolution a
     * courier reports in and the resolution the unique index is built on.
     */
    private function append(Shipment $shipment, TrackingEvent $event, string $source): void
    {
        $attributes = $event->attributes($shipment->getKey(), $source);

        $row = $shipment->trackingEvents()->firstOrCreate(
            [
                'status' => $attributes['status'],
                'event_at' => $attributes['event_at'],
            ],
            $attributes,
        );

        if ($row->wasRecentlyCreated) {
            $this->appendedEvents++;
        }
    }

    /**
     * The columns that change when a parcel moves forward.
     *
     * `failure_reason` is cleared on a move that is not a failure, because a
     * parcel that failed one delivery attempt and arrived on the next is not
     * carrying a failure any more — and a stale reason beside a delivered
     * parcel is a support call.
     *
     * @return array<string, mixed>
     */
    private function advanceTo(Shipment $shipment, ShipmentStatus $status): array
    {
        $changes = ['status' => $status];

        if ($status->completesDelivery()) {
            $changes['delivered_at'] = $shipment->delivered_at ?? now();
            $changes['failure_reason'] = null;

            return $changes;
        }

        if (in_array($status, [ShipmentStatus::Failed, ShipmentStatus::Returned], strict: true)) {
            $changes['failure_reason'] = $this->latestDescriptionFor($shipment, $status);
        }

        return $changes;
    }

    /**
     * What the timeline says about this status, as the row's own summary.
     *
     * Read back out of the events that were just written rather than passed
     * down, so the summary column and the timeline cannot disagree about why a
     * parcel came back.
     */
    private function latestDescriptionFor(Shipment $shipment, ShipmentStatus $status): ?string
    {
        $event = $shipment->trackingEvents()
            ->where('status', $status->value)
            ->orderByDesc('event_at')
            ->first();

        return $event?->description;
    }

    /**
     * Move the order to `delivered`, if it is somewhere it can move from.
     *
     * An order that is not `shipped` is one somebody has already moved on —
     * completed by hand, or cancelled — and a courier's scan arriving
     * afterwards is news about the parcel, not an instruction to rewind the
     * order. So the refusal is logged and swallowed: the parcel's own status is
     * the truth being recorded here, and throwing would lose it to a rollback
     * over something that is not an error.
     */
    private function markOrderDelivered(Shipment $shipment, ?User $actor): void
    {
        $order = $shipment->order;

        if ($order->status !== OrderStatus::Shipped) {
            Log::info('A delivered parcel did not move its order, which had already moved on.', [
                'shipment_id' => $shipment->getKey(),
                'order_number' => $order->order_number,
                'order_status' => $order->status->value,
            ]);

            return;
        }

        $this->transitionOrderStatus->handle(
            $order,
            OrderStatus::Delivered,
            $actor,
            "Paket diterima. Resi {$shipment->tracking_number} ({$shipment->courier_name}).",
        );
    }

    /**
     * The shipment, re-read and locked for this transaction.
     *
     * Read across branches on purpose: the sweep in `P2-E2-T6` runs with no
     * branch context to narrow by, and the key already identifies the row.
     */
    private function lock(Shipment $shipment): Shipment
    {
        return Shipment::withoutGlobalScope(BranchScope::class)
            ->whereKey($shipment->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
