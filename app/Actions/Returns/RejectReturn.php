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
 * Turns a return request down (P2-E4-T3).
 *
 * The reason is required because **the customer reads it**: a request that
 * disappears without a sentence is a support call. Nothing else moves — the
 * goods never left the customer, so there is no stock to put back and no money
 * to send.
 *
 * Rejecting also releases the units the request had committed: a rejected
 * request stops counting towards `OrderItem::returnedQuantity()`, so the
 * customer may ask again — for the right quantity, or with the photos the
 * branch asked for. That is a property of the status rather than a write here,
 * which is why there is nothing to undo.
 *
 * Legal from `requested` **and** from `approved`: a branch that agreed and
 * then found the parcel never arrived, or arrived with somebody else's goods
 * in it, has to be able to say so.
 */
final class RejectReturn
{
    /**
     * @throws ValidationException when the request may no longer be turned down.
     */
    public function handle(OrderReturn $return, string $reason, ?User $actor = null): OrderReturn
    {
        $rejected = DB::transaction(function () use ($return, $reason, $actor): OrderReturn {
            $locked = $this->lock($return);

            $blocked = $locked->rejectionBlockedReason();

            if ($blocked !== null) {
                throw ValidationException::withMessages(['status' => $blocked]);
            }

            $locked->update([
                'status' => ReturnStatus::Rejected,
                'rejection_reason' => $reason,
            ]);

            OrderStatusHistory::create([
                'order_id' => $locked->order_id,
                'from_status' => $locked->order->status,
                'to_status' => $locked->order->status,
                'note' => "Retur {$locked->code} ditolak: {$reason}",
                'changed_by' => $actor?->getKey(),
                'created_at' => now(),
            ]);

            return $locked;
        });

        $return->refresh();

        return $rejected;
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
