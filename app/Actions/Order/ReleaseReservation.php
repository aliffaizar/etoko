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
 * Gives held stock back to the shelf (P1-E7-T2, BR-6).
 *
 * The counterpart of `ReserveStock`: on-hand never moved, so releasing only
 * lowers `stocks.reserved_quantity`, and what everyone else may buy goes back
 * up. Called when an order is cancelled or expires (`TransitionOrderStatus`),
 * and once a minute by `ExpireStockReservations` for holds nobody finished
 * paying for.
 *
 * Only `Active` rows are touched, and the row is re-read under its own lock
 * before it is changed. That is what makes a double release unwritable: the
 * second caller finds the row already `Released` and does nothing, rather than
 * driving `reserved_quantity` below zero — which `StockLedger` would refuse
 * anyway, but as a crash rather than a no-op.
 */
final class ReleaseReservation
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * Release every hold an order is still carrying.
     *
     * Returns how many holds it actually released, so a caller can tell "there
     * was nothing left to give back" from "I gave three back".
     */
    public function handleForOrder(Order $order, ?string $reason = null): int
    {
        $ids = StockReservation::withoutGlobalScope(BranchScope::class)
            ->where('order_id', $order->getKey())
            ->active()
            ->orderBy('warehouse_id')
            ->orderBy('product_variant_id')
            ->pluck('id')
            ->all();

        $released = 0;

        foreach ($ids as $id) {
            $released += $this->handle($id, $reason) ? 1 : 0;
        }

        return $released;
    }

    /**
     * Release one hold, identified by id so it can be re-read under its lock.
     *
     * Returns false when there was nothing to release — the hold had already
     * been committed or released. That is a normal outcome, not a failure: the
     * expiry sweep and a customer cancelling can reach the same row.
     */
    public function handle(int $reservationId, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($reservationId, $reason): bool {
            $reservation = StockReservation::withoutGlobalScope(BranchScope::class)
                ->whereKey($reservationId)
                ->lockForUpdate()
                ->first();

            if (! $reservation instanceof StockReservation || ! $reservation->isHolding()) {
                return false;
            }

            $reservation->loadMissing(['warehouse', 'variant', 'order']);

            $this->ledger->apply(
                warehouse: $reservation->warehouse,
                variant: $reservation->variant,
                type: StockMovementType::SaleReservationRelease,
                quantityChange: 0,
                reference: $reservation->order,
                notes: $reason ?? $this->defaultReason($reservation),
                reservedChange: -$reservation->quantity,
            );

            $reservation->update(['status' => ReservationStatus::Released]);

            return true;
        });
    }

    private function defaultReason(StockReservation $reservation): string
    {
        $number = $reservation->order?->order_number;

        return $number === null
            ? 'Penahanan stok dilepas.'
            : "Penahanan stok pesanan {$number} dilepas.";
    }
}
