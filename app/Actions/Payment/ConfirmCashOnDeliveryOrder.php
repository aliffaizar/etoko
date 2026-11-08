<?php

namespace App\Actions\Payment;

use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Exceptions\IllegalOrderTransitionException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PaymentFailedException;
use App\Exceptions\ReservationMissingException;
use App\Models\Payment;
use App\Models\User;

/**
 * Confirms a COD order so its branch can start packing it (P2-E1-T7, ADR-040).
 *
 * A COD order is the one case where the goods go out before the money comes
 * in, and the schema already has the two columns needed to say so: `status`
 * tracks where the **goods** are, `payment_status` tracks where the **money**
 * is. So a COD order is moved to `paid` at the moment it is placed — which
 * commits its holds, takes the units off the shelf and puts the order in the
 * branch's queue — while `TransitionOrderStatus` leaves its `payment_status`
 * at `pending`, because nobody has handed over any cash.
 *
 * The cash itself is recorded later by `VerifyPayment`, when the courier
 * reports back. That is what turns `payment_status` into `paid`.
 *
 * Why this is its own action rather than three lines inside
 * `InitiatePayment`: the transition it asks for can be refused for reasons
 * that are nothing to do with payment — the branch's shelf having been emptied
 * by a stock issue while the order waited, or the order's holds having been
 * swept. Those refusals have to be turned into a sentence about *the order*
 * rather than about the gateway, and keeping them here keeps `InitiatePayment`
 * about asking for money.
 */
final class ConfirmCashOnDeliveryOrder
{
    public function __construct(private readonly TransitionOrderStatus $transitionOrderStatus) {}

    /**
     * Move the payment's order to `paid`, leaving the money outstanding.
     *
     * @throws PaymentFailedException when the order's goods can no longer be committed.
     */
    public function handle(Payment $payment, ?User $actor = null): void
    {
        if (! $payment->method_type->isCollectedOnDelivery()) {
            throw PaymentFailedException::unsupported(
                'Konfirmasi tanpa pembayaran hanya untuk metode bayar di tempat (COD).',
            );
        }

        $order = $payment->order;

        if ($order->status !== OrderStatus::PendingPayment) {
            // Already confirmed — a double-submitted checkout, or a retry of a
            // COD order whose first attempt had already moved it. Nothing to do.
            return;
        }

        try {
            $this->transitionOrderStatus->handle(
                $order,
                OrderStatus::Paid,
                $actor,
                "Pesanan COD dikonfirmasi; pembayaran {$payment->method_name} ditagih saat barang diantar.",
            );
        } catch (IllegalOrderTransitionException $moved) {
            throw PaymentFailedException::orderNotPayable($order);
        } catch (InsufficientStockException|ReservationMissingException $short) {
            throw PaymentFailedException::unsupported(
                'Pesanan COD tidak dapat dikonfirmasi karena stoknya tidak lagi tersedia: '
                    .$short->getMessage(),
            );
        }
    }
}
