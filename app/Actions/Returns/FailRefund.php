<?php

namespace App\Actions\Returns;

use App\Enums\RefundStatus;
use App\Models\Refund;
use App\Models\User;
use App\Scopes\BranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records that a refund did not happen (P2-E4-T4).
 *
 * For the transfer the bank bounced and the reversal the provider refused. The
 * reason is required because somebody will have to act on it — usually by
 * opening a second refund with the customer's corrected account details.
 *
 * **A failed refund counts for nothing.** `Order::refundedAmount()` sums only
 * `processed` rows, so failing one puts its amount straight back into what may
 * still be returned, and the order's `payment_status` was never moved by it in
 * the first place. That is why there is nothing here to undo — the mirror of
 * `FailPayment`, which leaves an order payable.
 *
 * Only a `pending` refund may be failed. Money that has gone back cannot be
 * un-sent by writing a column; that is a *second* movement, and it is a
 * payment rather than a refund.
 */
final class FailRefund
{
    /**
     * @throws ValidationException when the refund may no longer be changed.
     */
    public function handle(Refund $refund, string $reason, ?User $actor = null): Refund
    {
        $failed = DB::transaction(function () use ($refund, $reason, $actor): Refund {
            $locked = Refund::withoutGlobalScope(BranchScope::class)
                ->whereKey($refund->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $blocked = $locked->settlementBlockedReason();

            if ($blocked !== null) {
                throw ValidationException::withMessages(['status' => $blocked]);
            }

            $locked->update([
                'status' => RefundStatus::Failed,
                'failure_reason' => $reason,
                'processed_by' => $actor?->getKey(),
                'processed_at' => now(),
            ]);

            return $locked;
        });

        $refund->refresh();

        return $failed;
    }
}
