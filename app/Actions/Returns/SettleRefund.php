<?php

namespace App\Actions\Returns;

use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\RefundStatus;
use App\Enums\ReturnStatus;
use App\Exceptions\IllegalOrderTransitionException;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Scopes\BranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records that the money has actually gone back (P2-E4-T4, T5).
 *
 * The one place a refund becomes real, whoever says so: a gateway that
 * confirmed its reversal, or a staff member who has made the transfer and is
 * typing in the reference. Both mean the same thing and must have the same
 * consequences, which is why there is one action rather than one per screen —
 * the same arrangement `VerifyPayment` has for money coming in.
 *
 * What happens, together or not at all:
 *
 * 1. **The refund is settled** — `processed`, with its reference and with
 *    `processed_by` naming whoever decided when a person did.
 * 2. **`orders.payment_status` follows.** `refunded` once the settled refunds
 *    cover what the order was charged, `partially_refunded` while they do not.
 *    The figure is recomputed from the table rather than accumulated, so two
 *    refunds settled at the same moment cannot both read the old sum.
 * 3. **`orders.status` follows only for a full refund** (ADR-048). A partial
 *    refund deliberately leaves the order where it is: `partially_refunded` is
 *    a *final* status in the state machine, and moving a delivered order into
 *    it would make it uncompletable and unreturnable — a customer who sent one
 *    item back could never send a second. The order's standing with respect to
 *    money is what changed, and `payment_status` is the column that says so.
 * 4. **The attempt the money came in on is marked `refunded`** once the whole
 *    of it has gone back, so a payment and the refunds against it cannot
 *    disagree about whether it still stands.
 * 5. **The return it belongs to is closed** when the money covers the goods on
 *    it. A refund smaller than the goods — a restocking fee withheld, a part
 *    payment — leaves it `received`, which is correct: there is still
 *    something to do, and `CompleteReturn` is the button for saying there is
 *    not.
 *
 * **The order's own totals are never touched** (ADR-047): `subtotal`,
 * `grand_total` and every line's `subtotal` stay as they were sold. A refund
 * is a second movement of money, not a correction of the first, and rewriting
 * the order would quietly restate last month's sales figures.
 *
 * Idempotent by return value rather than by exception: `handle()` answers false
 * when the refund had already been settled or failed, which is the normal
 * outcome of a gateway confirming a reversal it has already confirmed.
 */
final class SettleRefund
{
    public function __construct(private readonly TransitionOrderStatus $transitionOrderStatus) {}

    /**
     * Settle the refund and take its order with it.
     *
     * @param  string|null  $reference  The gateway's reversal id, or the transfer reference a person read off the bank's screen.
     */
    public function handle(Refund $refund, ?User $actor = null, ?string $reference = null, ?string $notes = null): bool
    {
        $settled = DB::transaction(function () use ($refund, $actor, $reference, $notes): ?Refund {
            $locked = $this->lock($refund);

            if (! $locked->isOpen()) {
                return null;
            }

            $locked->update([
                'status' => RefundStatus::Processed,
                'reference' => $reference ?? $locked->reference,
                'notes' => $notes ?? $locked->notes,
                'failure_reason' => null,
                'processed_by' => $actor?->getKey(),
                'processed_at' => now(),
            ]);

            $order = $this->lockOrder($locked->order_id);

            $this->advanceOrder($order, $locked, $actor);
            $this->closeSourcePayment($locked);
            $this->closeReturn($locked, $actor);

            return $locked;
        });

        if (! $settled instanceof Refund) {
            return false;
        }

        Log::info('Settled a refund.', [
            'refund_id' => $settled->getKey(),
            'code' => $settled->code,
            'order_id' => $settled->order_id,
            'return_id' => $settled->return_id,
            'amount' => $settled->amount,
            'method' => $settled->method->value,
            'actor_id' => $actor?->getKey(),
        ]);

        $refund->refresh();

        return true;
    }

    /**
     * Move the order's standing with respect to money.
     *
     * The sum is read back from `refunds` under the order's lock rather than
     * added to a running total, so two refunds settled at the same moment
     * cannot both see the figure from before the other.
     */
    private function advanceOrder(Order $order, Refund $refund, ?User $actor): void
    {
        $refunded = (int) Refund::withoutGlobalScope(BranchScope::class)
            ->where('order_id', $order->getKey())
            ->settled()
            ->sum('amount');

        $isFull = $refunded >= $order->grand_total;

        if ($isFull && $order->status->canTransitionTo(OrderStatus::Refunded)) {
            try {
                $this->transitionOrderStatus->handle(
                    $order,
                    OrderStatus::Refunded,
                    $actor,
                    "Dana pesanan dikembalikan seluruhnya lewat {$refund->code}.",
                );

                return;
            } catch (IllegalOrderTransitionException) {
                // The order moved between the lock and here, which only a
                // concurrent writer could manage. Fall through and record the
                // money, because the money is real either way.
            }
        }

        // Only `payment_status` moves: a partial refund must not push a
        // delivered order into `partially_refunded`, which is final and would
        // leave it uncompletable and unreturnable (ADR-048). A full refund on
        // an order the state machine will not move — one already `refunded`,
        // or already `partially_refunded` from before — is recorded the same
        // way, with a note on the timeline instead of a transition.
        $order->update([
            'payment_status' => $isFull ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
        ]);

        OrderStatusHistory::create([
            'order_id' => $order->getKey(),
            'from_status' => $order->status,
            'to_status' => $order->status,
            'note' => "Dana dikembalikan lewat {$refund->code}: "
                .number_format($refund->amount, 0, ',', '.')
                .' rupiah'
                .($isFull ? ' (pengembalian penuh).' : ' (pengembalian sebagian).'),
            'changed_by' => $actor?->getKey(),
            'created_at' => now(),
        ]);
    }

    /**
     * Mark the attempt the money came in on as refunded, once all of it has
     * gone back.
     *
     * Only when the whole attempt has been returned: a payment half refunded
     * is still a payment that stands, and `PaymentTransactionStatus::Refunded`
     * is final — writing it early would say the shop no longer holds money it
     * does.
     */
    private function closeSourcePayment(Refund $refund): void
    {
        $payment = $refund->payment_id === null
            ? null
            : Payment::withoutGlobalScope(BranchScope::class)
                ->whereKey($refund->payment_id)
                ->lockForUpdate()
                ->first();

        if (! $payment instanceof Payment || $payment->status !== PaymentTransactionStatus::Success) {
            return;
        }

        $returnedAgainstPayment = (int) Refund::withoutGlobalScope(BranchScope::class)
            ->where('payment_id', $payment->getKey())
            ->settled()
            ->sum('amount');

        if ($returnedAgainstPayment < $payment->amount) {
            return;
        }

        $payment->update(['status' => PaymentTransactionStatus::Refunded]);
    }

    /**
     * Close the return this refund answers, when the money covers its goods.
     *
     * A smaller refund — a restocking fee withheld, a part payment — leaves
     * the return `received`, because there is still something to decide.
     * `CompleteReturn` is the button for saying there is not.
     */
    private function closeReturn(Refund $refund, ?User $actor): void
    {
        if ($refund->return_id === null) {
            return;
        }

        $return = OrderReturn::withoutGlobalScope(BranchScope::class)
            ->with(['items.orderItem', 'order:id,order_number,status'])
            ->whereKey($refund->return_id)
            ->lockForUpdate()
            ->first();

        if (! $return instanceof OrderReturn || $return->status !== ReturnStatus::Received) {
            return;
        }

        $settled = (int) Refund::withoutGlobalScope(BranchScope::class)
            ->where('return_id', $return->getKey())
            ->settled()
            ->sum('amount');

        if ($settled < $return->goodsValue()) {
            return;
        }

        $return->update([
            'status' => ReturnStatus::Completed,
            'completed_at' => now(),
        ]);

        OrderStatusHistory::create([
            'order_id' => $return->order_id,
            'from_status' => $return->order->status,
            'to_status' => $return->order->status,
            'note' => "Retur {$return->code} selesai.",
            'changed_by' => $actor?->getKey(),
            'created_at' => now(),
        ]);
    }

    /**
     * The refund, re-read and locked for this transaction.
     *
     * Read across branches on purpose: a gateway callback and a queue worker
     * have no branch context, and the key already identifies the row.
     */
    private function lock(Refund $refund): Refund
    {
        return Refund::withoutGlobalScope(BranchScope::class)
            ->whereKey($refund->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockOrder(int $orderId): Order
    {
        return Order::withoutGlobalScope(BranchScope::class)
            ->whereKey($orderId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
