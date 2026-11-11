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
 * Agrees to take the goods back (P2-E4-T3).
 *
 * An approval is a **promise**, and deliberately nothing more: no stock moves,
 * no money moves, and the order is untouched. The goods are still with the
 * customer, and a parcel that never arrives must not have put units back on a
 * shelf. What moves the figures is `ReceiveReturn`, when somebody opens the
 * box.
 *
 * The row is re-read under its lock, so two staff pressing "Setujui" at the
 * same moment queue: the second finds the request already decided and is
 * refused, instead of writing the same approval twice over somebody else's
 * rejection.
 *
 * The order's timeline gets a note — a history row whose `from_status` equals
 * its `to_status` (P1-E7-T10) — because "we agreed to take two of these back,
 * and who agreed" is exactly the kind of thing somebody will need to look up
 * from the order rather than from the return.
 */
final class ApproveReturn
{
    /**
     * @throws ValidationException when the request may no longer be approved.
     */
    public function handle(OrderReturn $return, ?User $actor = null, ?string $notes = null): OrderReturn
    {
        $approved = DB::transaction(function () use ($return, $actor, $notes): OrderReturn {
            $locked = $this->lock($return);

            $blocked = $locked->approvalBlockedReason();

            if ($blocked !== null) {
                throw ValidationException::withMessages(['status' => $blocked]);
            }

            $locked->update([
                'status' => ReturnStatus::Approved,
                'approved_by' => $actor?->getKey(),
                'approved_at' => now(),
                'notes' => $notes ?? $locked->notes,
                'rejection_reason' => null,
            ]);

            OrderStatusHistory::create([
                'order_id' => $locked->order_id,
                'from_status' => $locked->order->status,
                'to_status' => $locked->order->status,
                'note' => "Retur {$locked->code} disetujui."
                    .($notes === null ? '' : " {$notes}"),
                'changed_by' => $actor?->getKey(),
                'created_at' => now(),
            ]);

            return $locked;
        });

        $return->refresh();

        return $approved;
    }

    /**
     * The request, re-read and locked for this transaction.
     *
     * Read across branches because the key already identifies the row, and the
     * branch check belongs to `OrderReturnPolicy` — which has already run by
     * the time an action is reached, and which is also what makes another
     * branch's id answer 404 rather than 403 (ADR-027).
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
