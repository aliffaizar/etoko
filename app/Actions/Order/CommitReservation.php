<?php

namespace App\Actions\Order;

use App\Enums\ReservationStatus;
use App\Enums\StockMovementType;
use App\Models\Order;
use App\Models\StockReservation;
use App\Scopes\BranchScope;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * Turns a hold into a sale (P1-E7-T2, BR-6).
 *
 * The goods leave the shelf: on-hand and the hold go down **together**, in one
 * `StockLedger` call, typed `sale_out`. Doing it in one step is the point —
 * lowering on-hand first would momentarily leave on-hand below what is
 * reserved, which `StockLedger` refuses outright, and lowering the hold first
 * would momentarily offer the units to somebody else.
 *
 * Called when an order is paid for (`TransitionOrderStatus`). A committed hold
 * is final; stock coming back is a return, which is a document of its own
 * (Phase 2) and an inbound `sale_return_in` movement.
 */
final class CommitReservation
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * Commit every hold an order is still carrying, in one transaction.
     *
     * All or nothing: if the last line cannot be taken off the shelf, none of
     * them are, because an order half-shipped out of the ledger is a figure
     * nobody can explain.
     *
     * Returns how many holds were committed.
     */
    public function handleForOrder(Order $order): int
    {
        return DB::transaction(function () use ($order): int {
            $ids = StockReservation::withoutGlobalScope(BranchScope::class)
                ->where('order_id', $order->getKey())
                ->active()
                ->orderBy('warehouse_id')
                ->orderBy('product_variant_id')
                ->pluck('id')
                ->all();

            $committed = 0;

            foreach ($ids as $id) {
                $committed += $this->commit($id, $order) ? 1 : 0;
            }

            return $committed;
        });
    }

    /**
     * Commit one hold, re-read under its lock.
     *
     * Returns false when there was nothing to commit — already committed, or
     * released by the expiry sweep a moment earlier.
     */
    private function commit(int $reservationId, Order $order): bool
    {
        $reservation = StockReservation::withoutGlobalScope(BranchScope::class)
            ->whereKey($reservationId)
            ->lockForUpdate()
            ->first();

        if (! $reservation instanceof StockReservation || ! $reservation->isHolding()) {
            return false;
        }

        $reservation->loadMissing(['warehouse', 'variant']);

        $this->ledger->apply(
            warehouse: $reservation->warehouse,
            variant: $reservation->variant,
            type: StockMovementType::SaleOut,
            quantityChange: -$reservation->quantity,
            reference: $order,
            notes: "Penjualan pesanan {$order->order_number}.",
            reservedChange: -$reservation->quantity,
        );

        $reservation->update([
            'status' => ReservationStatus::Committed,
            'expires_at' => null,
        ]);

        return true;
    }
}
