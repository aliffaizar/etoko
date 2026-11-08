<?php

namespace App\Actions\Payment;

use App\Enums\PaymentTransactionStatus;
use App\Exceptions\PaymentFailedException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Scopes\BranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Closes an attempt that will never bring money in (P2-E1-T4, T6).
 *
 * Used from three places that all mean the same thing: a staff member
 * rejecting a receipt that does not add up, a gateway reporting a denied or
 * cancelled transaction, and `InitiatePayment` abandoning an attempt whose
 * charge was refused.
 *
 * **The order stays payable.** A failed attempt is one attempt, not a verdict
 * on the order: the customer may pick another method and try again, their
 * stock is still held, and the deadline is still the one the hold imposes.
 * What changes on the order is `payment_status`, which becomes `failed` so the
 * panel can see at a glance which orders have a problem rather than merely an
 * unpaid balance.
 *
 * Nothing here touches stock. A pending attempt never moved any — the hold was
 * taken at checkout and is released by the order expiring or being cancelled,
 * which is `TransitionOrderStatus`'s business and not a payment's.
 */
final class FailPayment
{
    /**
     * Mark the attempt failed.
     *
     * Returns false when there was nothing to do because the attempt had
     * already finished — a gateway retrying a `deny` it has already sent, or
     * two staff members pressing the button together. The caller is told
     * rather than being thrown at, because "already handled" is a normal
     * outcome of an idempotent endpoint.
     *
     * @param  string  $reason  Shown to the customer and the staff member; say what was wrong with it.
     */
    public function handle(Payment $payment, string $reason, ?User $actor = null): bool
    {
        $closed = DB::transaction(function () use ($payment, $reason, $actor): bool {
            $locked = $this->lock($payment);

            if (! $locked->isOpen()) {
                return false;
            }

            $locked->update([
                'status' => PaymentTransactionStatus::Failed,
                'failure_reason' => $reason,
                'verified_by' => $actor?->getKey(),
                'verified_at' => $actor instanceof User ? now() : null,
            ]);

            // Updated as a model rather than through the relation's query so
            // the change reaches the audit trail BR-9 requires; a mass update
            // fires no model events.
            $order = Order::withoutGlobalScope(BranchScope::class)
                ->whereKey($locked->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $order->update(['payment_status' => PaymentTransactionStatus::Failed->orderPaymentStatus()]);

            return true;
        });

        if ($closed) {
            Log::info('Marked a payment attempt as failed.', [
                'payment_id' => $payment->getKey(),
                'reference' => $payment->reference,
                'reason' => $reason,
                'actor_id' => $actor?->getKey(),
            ]);
        }

        $payment->refresh();

        return $closed;
    }

    /**
     * Refuse the attempt, throwing when there is nothing to refuse.
     *
     * For the panel endpoint, which has a staff member in front of it who
     * should be told why their button did nothing.
     *
     * @throws PaymentFailedException
     */
    public function handleOrFail(Payment $payment, string $reason, ?User $actor = null): void
    {
        if (! $this->handle($payment, $reason, $actor)) {
            throw PaymentFailedException::unsupported(
                $payment->verificationBlockedReason() ?? 'Pembayaran ini sudah tidak dapat diubah.',
            );
        }
    }

    /**
     * The attempt, re-read and locked for this transaction.
     *
     * Read across branches: the webhook worker has no branch context, and the
     * key already identifies the row.
     */
    private function lock(Payment $payment): Payment
    {
        return Payment::withoutGlobalScope(BranchScope::class)
            ->whereKey($payment->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
