<?php

namespace App\Actions\Shipping;

use App\Enums\ShipmentStatus;
use App\Exceptions\ShippingFailedException;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Shipping\Data\ShippingQuote;

/**
 * Records which courier an order's goods are going with (P2-E2-T3).
 *
 * Called by `PlaceOrder` inside its transaction, because the choice is part of
 * the order: the figure in `orders.shipping_cost` and the row that explains it
 * have to land together or the order has a delivery charge nobody can account
 * for.
 *
 * **It books nothing.** Writing this row is a record of a decision, not a
 * conversation with a courier, which is why it is safe inside a transaction
 * that also holds the cart's and the stock's row locks. Handing the parcel
 * over — the HTTP call to an aggregator, the waybill number — is
 * `DispatchShipment`, which runs when the branch actually sends it and
 * deliberately calls the provider **outside** its transaction. That split is
 * the same one `PlaceOrder` and `InitiatePayment` make, and for the same
 * reason: a foreground request to somebody else's server must never be holding
 * a row lock.
 *
 * The row starts at `pending` — chosen, not yet collected — which is what puts
 * it in the branch's dispatch queue.
 */
final class CreateShipment
{
    /**
     * Open a shipment for `$order` from the quote the customer accepted.
     *
     * @throws ShippingFailedException when the order cannot have one.
     */
    public function handle(Order $order, ShippingQuote $quote): Shipment
    {
        if (! $order->requiresShipping()) {
            throw ShippingFailedException::unsupported(
                'Pesanan yang diambil sendiri di cabang tidak memerlukan pengiriman.',
            );
        }

        // An order with a parcel still in play must not get a second one:
        // two open shipments mean two waybills for one set of goods, and
        // nothing on either row to say which is real. A *returned* parcel
        // leaves none open, which is exactly what makes re-sending it work.
        if ($order->activeShipment() instanceof Shipment) {
            throw ShippingFailedException::unsupported(
                "Pesanan {$order->order_number} sudah memiliki pengiriman yang berjalan.",
            );
        }

        /** @var Shipment $shipment */
        $shipment = $order->shipments()->create([
            // Copied from the order (ADR-038) so this table can carry the
            // branch scope like every other transactional one. `AssignOrderBranch`
            // moves it along with the order, so the copy cannot drift.
            'branch_id' => $order->branch_id,
            ...$quote->shipmentAttributes(),
            // The weight the quote was made against, kept beside the price it
            // produced, so a dispute about the charge can be reconstructed
            // from the two numbers that caused it.
            'weight_gram' => $order->total_weight_gram,
            'status' => ShipmentStatus::Pending,
        ]);

        return $shipment;
    }
}
