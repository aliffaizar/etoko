<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Events\StockRanLow;
use App\Exceptions\InsufficientStockException;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The one place that changes on-hand stock (P1-E4-T2).
 *
 * Every figure in `stocks` must be explainable by the `stock_movements` rows
 * that produced it, so the two are written together, here, and nowhere else.
 * Goods in, goods out, opname differences, transfers and sales all end up in
 * this method; what differs between them is the movement type and the document
 * they point at.
 *
 * Three invariants it holds, in the order they matter:
 *
 * 1. **It runs inside a transaction.** It refuses to run outside one rather
 *    than writing half a change, because the `stocks` update and the ledger row
 *    must land together or not at all.
 * 2. **It holds the row lock before it reads.** The check "is there enough?"
 *    and the write that consumes it happen inside one `SELECT ... FOR UPDATE`,
 *    which is what stops two concurrent requests from both passing the check
 *    (BR-6).
 * 3. **On-hand never goes negative, and never drops below what is reserved.**
 *    Stock that checkout has already promised a buyer cannot also be issued to
 *    a shelf. A caller that wants to consume a reservation says so with
 *    `reservedChange`, which moves the two together.
 */
final class StockLedger
{
    /**
     * Record one change to a (warehouse, variant) pair and return the ledger
     * row it produced.
     *
     * @param  int  $quantityChange  Signed: positive for stock arriving, negative for stock leaving.
     * @param  Model|null  $reference  The document or order that caused it; null for a manual adjustment.
     * @param  int  $reservedChange  Signed change to `reserved_quantity`, for checkout holding and releasing stock (P1-E7).
     *
     * @throws InsufficientStockException When the warehouse does not hold enough.
     * @throws LogicException When called outside a transaction, or with no change at all.
     */
    public function apply(
        Warehouse $warehouse,
        ProductVariant $variant,
        StockMovementType $type,
        int $quantityChange,
        ?Model $reference = null,
        ?int $unitCost = null,
        ?string $notes = null,
        ?User $actor = null,
        int $reservedChange = 0,
    ): StockMovement {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'StockLedger::apply() must run inside a transaction: the stocks row and its ledger row have to land together.',
            );
        }

        if ($quantityChange === 0 && $reservedChange === 0) {
            throw new LogicException('A stock movement of zero would record nothing. Refusing to write it.');
        }

        $stock = $this->lockedStock($warehouse, $variant);

        $quantityBefore = $stock->quantity;
        $quantityAfter = $quantityBefore + $quantityChange;
        $reservedAfter = $stock->reserved_quantity + $reservedChange;

        $this->refuseImpossibleResult($warehouse, $variant, $quantityChange, $quantityAfter, $reservedAfter, $stock);

        $wasLow = $stock->isLow();

        $stock->quantity = $quantityAfter;
        $stock->reserved_quantity = $reservedAfter;
        $stock->save();

        $movement = StockMovement::create([
            'warehouse_id' => $warehouse->getKey(),
            'branch_id' => $warehouse->branch_id,
            'product_variant_id' => $variant->getKey(),
            'type' => $type,
            'quantity_change' => $quantityChange,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'unit_cost' => $unitCost,
            'notes' => $notes,
            'created_by' => $actor?->getKey(),
        ]);

        $this->announceCrossingIntoLow($stock, $wasLow);

        return $movement;
    }

    /**
     * Tell the application a row has just become low, once the figures are
     * committed (P1-E4-T9).
     *
     * Deferred to after the commit, because a listener that read the row
     * mid-transaction — or sent a notification for a transaction that then
     * rolled back — would be announcing a figure that never existed.
     *
     * Only the movement that *crosses* the threshold announces anything, so
     * selling the last five units one at a time is one alert, not five.
     */
    public function announceCrossingIntoLow(Stock $stock, bool $wasLow): void
    {
        if ($wasLow || ! $stock->isLow()) {
            return;
        }

        DB::afterCommit(fn () => StockRanLow::dispatch($stock));
    }

    /**
     * The `stocks` row for the pair, locked for this transaction, created if
     * this is the first thing ever said about it.
     *
     * Read across branches on purpose: the ledger is also driven by jobs,
     * commands and central screens that have no branch context to narrow by,
     * and the (warehouse, variant) pair already identifies the row exactly.
     */
    private function lockedStock(Warehouse $warehouse, ProductVariant $variant): Stock
    {
        $existing = $this->findLocked($warehouse, $variant);

        if ($existing instanceof Stock) {
            return $existing;
        }

        try {
            return Stock::create([
                'warehouse_id' => $warehouse->getKey(),
                'branch_id' => $warehouse->branch_id,
                'product_variant_id' => $variant->getKey(),
                'quantity' => 0,
                'reserved_quantity' => 0,
                'min_quantity' => 0,
            ]);
        } catch (UniqueConstraintViolationException $collision) {
            // Another transaction created the row between the select and the
            // insert. The unique index is what caught it; re-read under the
            // lock and carry on with theirs.
            $raced = $this->findLocked($warehouse, $variant);

            if ($raced instanceof Stock) {
                return $raced;
            }

            throw $collision;
        }
    }

    private function findLocked(Warehouse $warehouse, ProductVariant $variant): ?Stock
    {
        return Stock::acrossBranches()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->lockForUpdate()
            ->first();
    }

    /**
     * Refuse a result the warehouse cannot actually be in.
     *
     * @throws InsufficientStockException
     */
    private function refuseImpossibleResult(
        Warehouse $warehouse,
        ProductVariant $variant,
        int $quantityChange,
        int $quantityAfter,
        int $reservedAfter,
        Stock $stock,
    ): void {
        if ($quantityAfter < 0) {
            throw InsufficientStockException::for(
                $warehouse,
                $variant,
                abs($quantityChange),
                $stock->quantity,
            );
        }

        if ($reservedAfter < 0) {
            throw new LogicException(
                'A stock movement would leave reserved_quantity negative, which means a reservation was released twice.',
            );
        }

        if ($quantityAfter < $reservedAfter) {
            throw InsufficientStockException::for(
                $warehouse,
                $variant,
                abs($quantityChange),
                max($stock->quantity - $reservedAfter, 0),
            );
        }
    }
}
