<?php

namespace App\Actions\Order;

use App\Enums\ReservationStatus;
use App\Enums\StockMovementType;
use App\Exceptions\ReservationMissingException;
use App\Models\Order;
use App\Models\StockReservation;
use App\Scopes\BranchScope;
use App\Services\Inventory\StockLedger;
use Illuminate\Database\Eloquent\Builder;
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
     * An order whose holds have been **released** is refused rather than
     * quietly committing nothing (HIGH-03). That state means its stock went
     * back on the shelf while it was still payable, and marking it sold anyway
     * would take money for goods that never left `stocks` — the ledger would
     * then disagree with reality for ever, which is the one thing ADR-024 is
     * for. It is the silence that let the expiry sweep's batching bug end as
     * unexplainable stock, so the silence is what goes.
     *
     * Two neighbouring states are **not** that, and both pass quietly:
     *
     * - **Already committed.** The goods are off the shelf and the ledger says
     *   so; running twice has nothing left to do, which is what makes this
     *   action safe to retry.
     * - **No holds at all.** Nothing was ever reserved for this order — every
     *   line's variant has since been hard-deleted from the catalogue, or it
     *   is a Phase 2 order that reserves nothing — so there is no figure to
     *   disagree with.
     *
     * Returns how many holds were committed.
     *
     * @throws ReservationMissingException
     */
    public function handleForOrder(Order $order): int
    {
        return DB::transaction(function () use ($order): int {
            $holds = StockReservation::withoutGlobalScope(BranchScope::class)
                ->where('order_id', $order->getKey());

            $ids = (clone $holds)
                ->active()
                ->orderBy('warehouse_id')
                ->orderBy('product_variant_id')
                ->pluck('id')
                ->all();

            if ($ids === [] && $this->holdsWereGivenBack(clone $holds)) {
                throw ReservationMissingException::for($order);
            }

            $committed = 0;

            foreach ($ids as $id) {
                $committed += $this->commit($id, $order) ? 1 : 0;
            }

            return $committed;
        });
    }

    /**
     * Whether any of this order's holds was given back to the shelf.
     *
     * Only ever asked when none of them is active any more, so a row that is
     * not `Committed` can only be a released one: the goods are back on the
     * shelf and nothing in the ledger says this order ever got them. Called
     * with the holds in either of the two states this can be in — all
     * released, or a mixture with some committed — because a half-released
     * order is no more explainable than a fully released one.
     *
     * @param  Builder<StockReservation>  $holds
     */
    private function holdsWereGivenBack(Builder $holds): bool
    {
        return $holds
            ->whereNot('status', ReservationStatus::Committed)
            ->exists();
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
