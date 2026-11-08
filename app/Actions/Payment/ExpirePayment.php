<?php

namespace App\Actions\Payment;

use App\Enums\PaymentTransactionStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Scopes\BranchScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Closes an attempt whose deadline has passed (P2-E1-T3, T6).
 *
 * Reached three ways, and the difference between them matters:
 *
 * - **`handle()`** — one attempt, by itself. A gateway reporting `expire`, or
 *   a staff member clearing a stale virtual account off the queue.
 * - **`forOrder()`** — every open attempt of an order that has just left
 *   `pending_payment` for good. Called by `TransitionOrderStatus`, so an order
 *   that expires or is cancelled does not leave a live ask for money behind
 *   it. It deliberately does **not** touch `orders.payment_status`: the
 *   transition that called it is deciding that, and an expired *attempt* on a
 *   *cancelled* order would otherwise overwrite "dibatalkan" with
 *   "kedaluwarsa".
 * - **`sweep()`** — the overdue attempts across the shop, which
 *   `ExpireStockReservations` runs alongside the holds it is already sweeping.
 *
 * The attempts and the holds are swept by the same job on purpose: the
 * deadline on an attempt *is* the hold's deadline (ADR-041), so one clock and
 * one sweep means they cannot disagree about whether an order is still payable.
 */
final class ExpirePayment
{
    /**
     * How many attempts one sweep will close.
     *
     * The same bound, for the same reason, as `ExpireStockReservations`: a
     * backlog is worked off over several minutes rather than one run holding
     * locks across thousands of rows while live checkouts queue behind it.
     */
    public const BATCH_SIZE = 200;

    /** What an attempt that simply ran out of time is closed with. */
    public const DEADLINE_PASSED = 'Batas waktu pembayaran terlewat.';

    /**
     * Mark one attempt expired.
     *
     * `$reason` is written to `failure_reason`, which the customer reads, so
     * it has to describe what actually happened: an attempt closed because its
     * order was cancelled did not run out of time, and telling the customer it
     * did would be a false sentence in a financial record.
     *
     * Returns false when there was nothing to do, which is the normal outcome
     * of a second sweep over the same row or of a gateway resending `expire`.
     */
    public function handle(Payment $payment, bool $updateOrder = true, ?string $reason = null): bool
    {
        $closed = DB::transaction(function () use ($payment, $updateOrder, $reason): bool {
            $locked = $this->lock($payment);

            if (! $locked->isOpen()) {
                return false;
            }

            $locked->update([
                'status' => PaymentTransactionStatus::Expired,
                'failure_reason' => $reason ?? self::DEADLINE_PASSED,
            ]);

            if ($updateOrder) {
                $order = Order::withoutGlobalScope(BranchScope::class)
                    ->whereKey($locked->order_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $order->update([
                    'payment_status' => PaymentTransactionStatus::Expired->orderPaymentStatus(),
                ]);
            }

            return true;
        });

        $payment->refresh();

        return $closed;
    }

    /**
     * Close every open attempt of an order, leaving the order alone.
     *
     * Returns how many were closed, so the caller can say something true about
     * it — the pattern `TransitionOrderStatus::$releasedHolds` set.
     */
    public function forOrder(Order $order, ?string $reason = null): int
    {
        $closed = 0;

        foreach ($order->payments()->open()->get() as $payment) {
            if ($this->handle($payment, updateOrder: false, reason: $reason)) {
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Close the overdue attempts across the shop, newest-expired last.
     *
     * Read across branches because the queue worker running it has no branch
     * context, and bounded because a backlog is not an emergency.
     *
     * Returns how many were closed.
     */
    public function sweep(?CarbonInterface $asOf = null): int
    {
        $moment = $asOf ?? Carbon::now();

        $payments = Payment::withoutGlobalScope(BranchScope::class)
            ->overrun($moment)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        $closed = 0;

        foreach ($payments as $payment) {
            if ($this->handle($payment)) {
                $closed++;
            }
        }

        if ($closed > 0) {
            Log::info('Expired overdue payment attempts.', ['count' => $closed]);
        }

        return $closed;
    }

    /**
     * The attempt, re-read and locked for this transaction.
     */
    private function lock(Payment $payment): Payment
    {
        return Payment::withoutGlobalScope(BranchScope::class)
            ->whereKey($payment->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
