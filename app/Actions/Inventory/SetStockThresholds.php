<?php

namespace App\Actions\Inventory;

use App\Events\StockRanLow;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Sets the reorder thresholds of one or more variants in one warehouse
 * (P1-E4-T4).
 *
 * Thresholds are settings, not stock: nothing moves, so nothing is written to
 * the ledger. The row still has to exist to carry them, which is why a
 * variant that has never been stocked gets a row at zero here — saying "tell
 * me when this drops below 5" is a legitimate thing to say before the first
 * delivery arrives.
 *
 * Done in bulk because that is how it is used: a warehouse sets minimums for a
 * whole category in one sitting, and one row at a time would mean one
 * round-trip each.
 *
 * Raising a minimum above what is on the shelf makes the row low just as
 * surely as selling the stock did, so this announces the crossing too.
 */
final class SetStockThresholds
{
    /**
     * @param  array<int, ProductVariant>  $variants
     * @param  array{min_quantity?: int|null, max_quantity?: int|null, shelf_location?: string|null}  $thresholds
     * @return int How many rows were touched.
     */
    public function handle(Warehouse $warehouse, array $variants, array $thresholds): int
    {
        if ($variants === [] || $thresholds === []) {
            return 0;
        }

        /** @var array<int, Stock> $newlyLow */
        $newlyLow = [];

        $touched = DB::transaction(function () use ($warehouse, $variants, $thresholds, &$newlyLow): int {
            foreach ($variants as $variant) {
                $stock = $this->lockedStock($warehouse, $variant);
                $wasLow = $stock->isLow();

                $stock->fill($thresholds)->save();

                if (! $wasLow && $stock->isLow()) {
                    $newlyLow[] = $stock;
                }
            }

            return count($variants);
        });

        foreach ($newlyLow as $stock) {
            StockRanLow::dispatch($stock);
        }

        return $touched;
    }

    /**
     * The row for the pair, created at zero when nothing has been said about
     * it yet.
     *
     * Read across branches on purpose: the (warehouse, variant) pair already
     * identifies the row exactly, and a central actor setting a branch's
     * thresholds has no branch restriction to read through.
     */
    private function lockedStock(Warehouse $warehouse, ProductVariant $variant): Stock
    {
        $stock = Stock::acrossBranches()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->lockForUpdate()
            ->first();

        if ($stock instanceof Stock) {
            return $stock;
        }

        return Stock::create([
            'warehouse_id' => $warehouse->getKey(),
            'branch_id' => $warehouse->branch_id,
            'product_variant_id' => $variant->getKey(),
        ]);
    }
}
