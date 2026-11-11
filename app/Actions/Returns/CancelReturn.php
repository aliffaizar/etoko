<?php

namespace App\Actions\Returns;

use App\Enums\ReturnStatus;
use App\Models\OrderReturn;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Scopes\BranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Withdraws a return request (P2-E4-T2).
 *
 * The customer's own undo, and narrower than what a branch may do on purpose:
 * only a request nobody has acted on yet may be withdrawn with a button
 * (`OrderReturn::customerCancellationBlockedReason()`). Once the goods are at
 * the branch, calling it off is a conversation — the same line
 * `Order::isCancellableByCustomer()` draws for orders.
 *
 * Like a rejection, this releases the units the request had committed, because
 * a cancelled request stops counting towards `OrderItem::returnedQuantity()`.
 * There is nothing to undo in the ledger: a request that was never received
 * never moved a figure.
 */
final class CancelReturn
{
    /**
     * @throws ValidationException when the request may no longer be withdrawn.
     */
    public function handle(OrderReturn $return, ?User $actor = null): OrderReturn
    {
        $cancelled = DB::transaction(function () use ($return, $actor): OrderReturn {
            $locked = $this->lock($return);

            $blocked = $locked->customerCancellationBlockedReason();

            if ($blocked !== null) {
                throw ValidationException::withMessages(['status' => $blocked]);
            }

            $locked->update(['status' => ReturnStatus::Cancelled]);

            OrderStatusHistory::create([
                'order_id' => $locked->order_id,
                'from_status' => $locked->order->status,
                'to_status' => $locked->order->status,
                'note' => "Retur {$locked->code} dibatalkan oleh pelanggan.",
                'changed_by' => $actor?->getKey(),
                'created_at' => now(),
            ]);

            return $locked;
        });

        $return->refresh();

        return $cancelled;
    }

    /**
     * The request, re-read and locked for this transaction.
     */
    private function lock(OrderReturn $return): OrderReturn
    {
        return OrderReturn::withoutGlobalScope(BranchScope::class)
            ->with('order:id,order_number,status')
            ->whereKey($return->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
