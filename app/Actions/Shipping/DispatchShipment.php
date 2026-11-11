<?php

namespace App\Actions\Shipping;

use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\IllegalOrderTransitionException;
use App\Exceptions\ShippingFailedException;
use App\Models\CourierService;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentTrackingEvent;
use App\Models\User;
use App\Scopes\BranchScope;
use App\Services\Shipping\Data\ShipmentResult;
use App\Services\Shipping\ShippingManager;
use Illuminate\Support\Facades\DB;

/**
 * Hands an order's goods to a courier (P2-E2-T4).
 *
 * Three steps in **three phases**, and the phases are the design — the same
 * shape `InitiatePayment` has, for the same reason:
 *
 * 1. **Record the choice, in a transaction.** The shipment row is written or
 *    updated with the courier the branch is actually using and the waybill
 *    number they typed, while the parcel is still `pending`. It is written
 *    *before* the provider is called because a provider has to be given a row
 *    to read — and because a booking that succeeds against a row that was
 *    never saved is a parcel in the world and no record of it.
 * 2. **Call the provider, outside the transaction.** For an aggregator this is
 *    an HTTP request to somebody else's server (`P2-E2-T6`); inside a
 *    transaction it would hold the order's row lock for as long as they took to
 *    answer, and every dispatch in the branch would queue behind it. For the
 *    manual driver it is a no-op that hands the typed number straight back.
 * 3. **Apply what came back, and follow the consequence.** The waybill number,
 *    the status, the timeline's first row and the order's move to `shipped` all
 *    land in one transaction, because an order marked shipped with no waybill
 *    is an order nobody can trace, and a waybill on an order still "being
 *    processed" is goods that have left with nothing saying so.
 *
 * **The delivery charge is not re-quoted here.** `shipments.cost` is what the
 * customer was charged and agreed to; a branch that sends the parcel with a
 * dearer courier than the one chosen at checkout has made the shop's own
 * decision, and silently re-pricing the order afterwards would change a total
 * somebody has already paid. The change of courier is recorded on the row and
 * on the timeline, which is where a question about it gets answered.
 */
final class DispatchShipment
{
    public function __construct(
        private readonly ShippingManager $manager,
        private readonly TransitionOrderStatus $transitionOrderStatus,
    ) {}

    /**
     * Send `$order` with `$service`, recording `$trackingNumber`.
     *
     * @param  string|null  $trackingNumber  The waybill the branch brought back; null when a provider will issue one.
     *
     * @throws ShippingFailedException when the order or the service cannot carry this.
     * @throws IllegalOrderTransitionException when the order has moved on since the screen was drawn.
     */
    public function handle(
        Order $order,
        CourierService $service,
        ?string $trackingNumber,
        ?User $actor = null,
        ?string $notes = null,
    ): Shipment {
        $blocked = $order->dispatchBlockedReason();

        if ($blocked !== null) {
            throw ShippingFailedException::unsupported($blocked);
        }

        $service->loadMissing('courier');

        if (! $service->is_active || ! $service->courier->is_active) {
            throw ShippingFailedException::serviceUnavailable($service);
        }

        $shipment = $this->prepare($order, $service, $this->cleaned($trackingNumber), $notes);

        $result = $this->manager->for($service->courier)->createShipment($shipment);

        return $this->settle($order, $shipment, $result, $actor);
    }

    /**
     * Phase 1 — the shipment row the provider will be shown.
     *
     * Found or created under the order's row lock, so two packers pressing
     * "Kirim" at the same moment cannot open two parcels for one order: the
     * second finds the row the first made and updates it rather than adding to
     * it.
     *
     * An order that reached here with no shipment is one whose delivery was
     * arranged by hand — a POS sale, an order typed in by an admin
     * (`PlaceOrder::quoteDelivery()`). Its charge is whatever the order was
     * billed, which is the only figure anybody agreed to.
     */
    private function prepare(Order $order, CourierService $service, ?string $trackingNumber, ?string $notes): Shipment
    {
        return DB::transaction(function () use ($order, $service, $trackingNumber, $notes): Shipment {
            $locked = $this->lockOrder($order);
            $shipment = $locked->activeShipment();

            $attributes = [
                'courier_id' => $service->courier_id,
                'courier_service_id' => $service->getKey(),
                'courier_name' => $service->courier->name,
                'service_name' => $service->name,
                'etd_min_day' => $service->etd_min_day,
                'etd_max_day' => $service->etd_max_day,
                'tracking_number' => $trackingNumber,
                'notes' => $notes,
            ];

            if ($shipment instanceof Shipment) {
                if ($shipment->isDispatched()) {
                    throw ShippingFailedException::unsupported(
                        (string) $shipment->dispatchBlockedReason(),
                    );
                }

                $shipment->fill($attributes)->save();

                return $shipment;
            }

            /** @var Shipment $created */
            $created = $locked->shipments()->create([
                'branch_id' => $locked->branch_id,
                ...$attributes,
                'cost' => $locked->shipping_cost,
                'weight_gram' => $locked->total_weight_gram,
                'status' => ShipmentStatus::Pending,
            ]);

            return $created;
        });
    }

    /**
     * Phase 3 — the waybill, the timeline and the order, together.
     *
     * The order is moved through `TransitionOrderStatus` rather than by
     * writing `status`: that is what stamps `shipped_at`, writes the order's
     * own timeline row and keeps the state machine the only authority on legal
     * moves. An order that somebody else moved on in the meantime refuses here
     * with nothing written.
     *
     * The waybill number is required by the time this runs, whoever produced
     * it. A dispatched parcel with no number is one the customer cannot track
     * and the branch cannot chase, so the refusal happens before the order is
     * marked shipped rather than being discovered a day later.
     */
    private function settle(Order $order, Shipment $shipment, ShipmentResult $result, ?User $actor): Shipment
    {
        return DB::transaction(function () use ($order, $shipment, $result, $actor): Shipment {
            $locked = Shipment::withoutGlobalScope(BranchScope::class)
                ->whereKey($shipment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $attributes = $result->attributes();
            $trackingNumber = $attributes['tracking_number'] ?? $locked->tracking_number;

            if (! is_string($trackingNumber) || trim($trackingNumber) === '') {
                throw ShippingFailedException::unsupported(
                    'Nomor resi belum ada, jadi pengiriman ini belum dapat ditandai terkirim. '
                        .'Masukkan nomor resi dari kurir.',
                );
            }

            $locked->fill([
                ...$attributes,
                'tracking_number' => $trackingNumber,
                'status' => ShipmentStatus::PickedUp,
                'shipped_at' => $locked->shipped_at ?? now(),
                'dispatched_by' => $actor?->getKey(),
            ])->save();

            $this->recordFirstEvent($locked, $result);

            $this->transitionOrderStatus->handle(
                $order,
                OrderStatus::Shipped,
                $actor,
                "Dikirim via {$locked->courier_name} — {$locked->service_name}, resi {$trackingNumber}.",
            );

            return $locked;
        });
    }

    /**
     * The first row of the parcel's timeline.
     *
     * `firstOrCreate` on the natural key rather than `create`, because the
     * unique index on `(shipment_id, status, event_at)` is what makes the
     * tracking sweep idempotent and this row has to play by the same rule: a
     * courier's own "picked up" scan arriving a minute later must not collide
     * with ours.
     *
     * `source` tells the customer who said it — the branch typed this one, and
     * a timeline that attributed it to the courier would be claiming a scan
     * that never happened.
     */
    private function recordFirstEvent(Shipment $shipment, ShipmentResult $result): void
    {
        $moment = $shipment->shipped_at ?? now();

        $shipment->trackingEvents()->firstOrCreate(
            [
                'status' => ShipmentStatus::PickedUp,
                'event_at' => $moment,
            ],
            [
                'description' => $result->wasBooked()
                    ? "Pesanan dibuat di {$shipment->courier_name}, menunggu penjemputan kurir."
                    : "Paket diserahkan ke {$shipment->courier_name}.",
                'location' => $shipment->order->branch->city_name,
                'source' => ShipmentTrackingEvent::SOURCE_BRANCH,
            ],
        );
    }

    /**
     * The order, re-read and locked for this transaction.
     *
     * Read across branches on purpose: the key already identifies the row, and
     * the caller has been through `OrderPolicy` and the branch scope to get
     * here. A scope applied again would only make this fail for a central
     * account acting on a branch's order.
     */
    private function lockOrder(Order $order): Order
    {
        return Order::withoutGlobalScope(BranchScope::class)
            ->whereKey($order->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * A waybill number as a courier wrote it, or null when none was given.
     *
     * Upper-cased and stripped of the spaces and dashes people copy out of a
     * receipt, so the same parcel is not entered twice under two spellings.
     */
    private function cleaned(?string $trackingNumber): ?string
    {
        if ($trackingNumber === null) {
            return null;
        }

        $cleaned = mb_strtoupper(preg_replace('/[\s\-]+/', '', trim($trackingNumber)) ?? '');

        return $cleaned === '' ? null : $cleaned;
    }
}
