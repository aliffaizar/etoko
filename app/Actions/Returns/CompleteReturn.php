<?php

namespace App\Actions\Returns;

use App\Enums\ReturnStatus;
use App\Models\OrderReturn;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Scopes\BranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Closes a return by hand (P2-E4-T3, T4).
 *
 * `SettleRefund` closes a return on its own when the money covers the goods on
 * it, which is the ordinary case and needs no button. This is for the two
 * cases it cannot decide:
 *
 * - **An exchange.** No money goes back at all, so nothing will ever close it
 *   automatically; what finishes it is the replacement reaching the customer,
 *   which only the branch knows about.
 * - **A refund the shop deliberately made smaller than the goods** — a
 *   restocking fee withheld, a part payment agreed on the phone. Leaving it
 *   `received` keeps it in the queue, and pretending the shortfall was an
 *   accident would be worse than asking somebody to confirm it.
 *
 * It refuses a refund-type return with **no settled refund at all**, because
 * that is not a decision anybody makes on purpose: closing it would hide a
 * customer who is still owed their money.
 */
final class CompleteReturn
{
    /**
     * @throws ValidationException when the request may not be closed yet.
     */
    public function handle(OrderReturn $return, ?User $actor = null, ?string $notes = null): OrderReturn
    {
        $completed = DB::transaction(function () use ($return, $actor, $notes): OrderReturn {
            $locked = $this->lock($return);

            if (! $locked->status->canTransitionTo(ReturnStatus::Completed)) {
                throw ValidationException::withMessages([
                    'status' => $locked->status === ReturnStatus::Completed
                        ? 'Retur ini sudah selesai.'
                        : "Retur berstatus \"{$locked->status->label()}\" belum dapat diselesaikan. "
                            .'Catat penerimaan barang terlebih dahulu.',
                ]);
            }

            if ($locked->type->endsInRefund() && $locked->refundedAmount() === 0) {
                throw ValidationException::withMessages([
                    'status' => 'Belum ada dana yang dikembalikan untuk retur ini, jadi belum dapat '
                        .'ditandai selesai. Proses pengembalian dana terlebih dahulu.',
                ]);
            }

            $locked->update([
                'status' => ReturnStatus::Completed,
                'completed_at' => now(),
                'notes' => $notes ?? $locked->notes,
            ]);

            OrderStatusHistory::create([
                'order_id' => $locked->order_id,
                'from_status' => $locked->order->status,
                'to_status' => $locked->order->status,
                'note' => "Retur {$locked->code} ditandai selesai."
                    .($notes === null ? '' : " {$notes}"),
                'changed_by' => $actor?->getKey(),
                'created_at' => now(),
            ]);

            return $locked;
        });

        Log::info('Completed a customer return.', [
            'return_id' => $completed->getKey(),
            'code' => $completed->code,
            'order_id' => $completed->order_id,
            'actor_id' => $actor?->getKey(),
        ]);

        $return->refresh();

        return $completed;
    }

    /**
     * The request, re-read and locked for this transaction.
     */
    private function lock(OrderReturn $return): OrderReturn
    {
        return OrderReturn::withoutGlobalScope(BranchScope::class)
            ->with(['order:id,order_number,status', 'refunds'])
            ->whereKey($return->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
