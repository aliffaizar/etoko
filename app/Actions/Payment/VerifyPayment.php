<?php

namespace App\Actions\Payment;

use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\IllegalOrderTransitionException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PaymentFailedException;
use App\Exceptions\ReservationMissingException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use App\Scopes\BranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records that the money arrived (P2-E1-T3, T4, T6).
 *
 * The one place money becomes real, whoever says so: a staff member who has
 * checked the bank statement against an uploaded receipt, a gateway's status answer,
 * or a branch recording the cash a courier brought back from a COD. All three
 * mean the same thing and must have the same consequences, which is why there
 * is one action rather than one per screen.
 *
 * What happens, together or not at all:
 *
 * 1. **The attempt is settled** — `success`, with `paid_at`, and with
 *    `verified_by` naming whoever decided when a person did.
 * 2. **The order follows.** An order still in `pending_payment` is moved to
 *    `paid` through `TransitionOrderStatus`, which is what commits its stock
 *    holds into a sale (`sale_out`) and stamps `paid_at`. Writing
 *    `payment_status` by hand instead would mark an order paid whose goods
 *    were never taken off the shelf.
 * 3. **A COD order, already past `paid`, only has its `payment_status`
 *    moved** (ADR-040). Its goods went out when it was confirmed; this is the
 *    cash catching up, and asking for a transition would be asking for an edge
 *    the state machine rightly refuses.
 *
 * **An order that has expired or been cancelled cannot be settled**, and the
 * refusal is the point: its holds went back on sale, so there is nothing left
 * to commit and marking it paid would sell goods the ledger never moved. The
 * money is real, so the answer is a refund, not a status change — which is why
 * the message says so instead of failing silently.
 *
 * **All of it in one transaction.** The money being recorded and the goods
 * leaving the shelf are one event, so a branch whose shelf can no longer cover
 * the order gets a refusal with *nothing written* — the attempt is still
 * pending, its receipt is still attached, and whoever fixes the stock presses
 * the button again. The alternative, settling the attempt and then failing to
 * move the order, would leave a payment marked successful against an order
 * still marked unpaid, which is a pair of rows nobody can act on.
 *
 * Idempotent by return value rather than by exception: `handle()` answers false
 * when the attempt had already been settled, which is the normal outcome of a
 * gateway resending a `settlement` it has already sent.
 */
final class VerifyPayment
{
    public function __construct(private readonly TransitionOrderStatus $transitionOrderStatus) {}

    /**
     * Settle the attempt and take its order with it.
     *
     * @param  string|null  $note  Shown on the order's timeline; what the verifier saw.
     *
     * @throws PaymentFailedException when the order can no longer accept the money.
     * @throws InsufficientStockException when the branch's shelf can no longer cover the order.
     */
    public function handle(Payment $payment, ?User $actor = null, ?string $note = null): bool
    {
        $settled = DB::transaction(function () use ($payment, $actor, $note): ?Payment {
            $locked = $this->lock($payment);

            if (! $locked->isOpen()) {
                return null;
            }

            $order = $this->lockOrder($locked->order_id);

            $this->assertOrderCanAccept($order);

            $locked->update([
                'status' => PaymentTransactionStatus::Success,
                'paid_at' => now(),
                'verified_by' => $actor?->getKey(),
                'verified_at' => now(),
                'failure_reason' => null,
            ]);

            $locked->setRelation('order', $order);

            $this->advanceOrder($locked, $actor, $note);

            return $locked;
        });

        if (! $settled instanceof Payment) {
            return false;
        }

        Log::info('Settled a payment.', [
            'payment_id' => $settled->getKey(),
            'order_id' => $settled->order_id,
            'reference' => $settled->reference,
            'amount' => $settled->amount,
            'actor_id' => $actor?->getKey(),
        ]);

        $payment->refresh();

        return true;
    }

    /**
     * Settle the attempt, throwing when there was nothing to settle.
     *
     * For the panel endpoint, which has a staff member in front of it who
     * should be told why their button did nothing.
     *
     * @throws PaymentFailedException
     */
    public function handleOrFail(Payment $payment, ?User $actor = null, ?string $note = null): void
    {
        if (! $this->handle($payment, $actor, $note)) {
            throw PaymentFailedException::unsupported(
                $payment->verificationBlockedReason() ?? 'Pembayaran ini sudah tidak dapat diubah.',
            );
        }
    }

    /**
     * Refuse an order whose goods are no longer waiting for this money.
     *
     * @throws PaymentFailedException
     */
    private function assertOrderCanAccept(Order $order): void
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            throw PaymentFailedException::alreadySettled($order);
        }

        if (! $order->status->isFinal()) {
            return;
        }

        throw PaymentFailedException::unsupported(
            "Pesanan {$order->order_number} sudah berstatus \"{$order->status->label()}\", "
                .'sehingga pembayaran ini tidak dapat dicatat sebagai lunas — stoknya sudah dilepas. '
                .'Dana yang sudah masuk harus dikembalikan lewat dokumen refund.',
        );
    }

    /**
     * Move the order to where this payment leaves it.
     *
     * Called from **inside** the settling transaction, so a refusal here —
     * a shelf that can no longer cover the order, a hold that was swept —
     * rolls the settlement back with it. `TransitionOrderStatus` opens a
     * transaction of its own, which nests as a savepoint; the exception
     * propagating out of this one rolls the whole thing back.
     *
     * @throws InsufficientStockException
     * @throws PaymentFailedException
     */
    private function advanceOrder(Payment $payment, ?User $actor, ?string $note): void
    {
        $order = $payment->order;

        if ($order->status === OrderStatus::PendingPayment) {
            try {
                $this->transitionOrderStatus->handle(
                    $order,
                    OrderStatus::Paid,
                    $actor,
                    $note ?? "Pembayaran {$payment->method_name} diterima.",
                );
            } catch (IllegalOrderTransitionException $moved) {
                throw PaymentFailedException::orderNotPayable($order);
            } catch (ReservationMissingException $noHold) {
                throw PaymentFailedException::unsupported(
                    'Stok yang ditahan untuk pesanan ini sudah dilepas, jadi pembayarannya tidak dapat '
                        .'diselesaikan otomatis. Periksa stok cabang lalu proses pesanan secara manual. '
                        .$noHold->getMessage(),
                );
            }

            return;
        }

        // Already past `paid` — a COD whose goods went out on confirmation
        // (ADR-040). Only the money moves, so there is no transition to make;
        // the timeline still gets a row, because "the cash came back from the
        // courier, and who said so" is exactly the kind of thing somebody will
        // need to look up. A row whose `from_status` equals its `to_status` is
        // read as a note rather than a status change (P1-E7-T10).
        $order->update([
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => $order->paid_at ?? now(),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order->getKey(),
            'from_status' => $order->status,
            'to_status' => $order->status,
            'note' => $note ?? "Pembayaran {$payment->method_name} diterima.",
            'changed_by' => $actor?->getKey(),
            'created_at' => now(),
        ]);
    }

    /**
     * The attempt, re-read and locked for this transaction.
     *
     * Read across branches: the queue worker has no branch context, and the
     * key already identifies the row.
     */
    private function lock(Payment $payment): Payment
    {
        return Payment::withoutGlobalScope(BranchScope::class)
            ->whereKey($payment->getKey())
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
