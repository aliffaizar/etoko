<?php

namespace App\Services\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Decides which of a branch's warehouses an order line comes out of
 * (P1-E7-T2).
 *
 * The storefront promises availability at the level of a **branch** — a card
 * that says "5 tersedia" has summed every warehouse the branch keeps (BR-2,
 * BR-8). Reservations, though, are per `stocks` row, which is per warehouse.
 * This class is the bridge: it turns "five of this variant at this branch"
 * into the per-warehouse picks that `ReserveStock` can hold, so a customer is
 * never shown stock that checkout then refuses to find.
 *
 * It **must run inside the caller's transaction**, and it takes the row locks
 * itself before deciding. Reading availability without the lock would let a
 * concurrent checkout change the figures between the decision and the hold,
 * turning a valid plan into a refusal (BR-6). Locking here means the plan
 * `ReserveStock` receives is still true when it acts on it.
 *
 * Rows are locked in `warehouse_id` order, and `ReserveStock` walks its lines
 * in `product_variant_id` order, so two concurrent checkouts that want the
 * same goods always queue in the same direction rather than deadlocking.
 *
 * The default warehouse is drawn down first, so a branch's main shelf empties
 * before its overflow and the picking list stays in one place when it can.
 */
final class StockAllocator
{
    /**
     * Plan where one order line's units come from.
     *
     * @return array<int, StockAllocation>
     *
     * @throws InsufficientStockException When the branch as a whole does not hold enough.
     * @throws LogicException When called outside a transaction, or for a non-positive quantity.
     */
    public function allocate(Branch $branch, ProductVariant $variant, int $quantity): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'StockAllocator::allocate() must run inside a transaction: it locks the rows its plan is based on.',
            );
        }

        if ($quantity < 1) {
            throw new LogicException('An allocation of zero units would hold nothing. Refusing to plan it.');
        }

        $rows = $this->lockedRows($branch, $variant);
        $warehouses = $this->warehousesFor($branch);

        $plan = [];
        $outstanding = $quantity;

        foreach ($this->drawOrder($rows, $warehouses) as $row) {
            if ($outstanding === 0) {
                break;
            }

            $take = min($row->available_quantity, $outstanding);

            if ($take < 1) {
                continue;
            }

            $plan[] = new StockAllocation($warehouses[$row->warehouse_id], $take);
            $outstanding -= $take;
        }

        if ($outstanding > 0) {
            throw InsufficientStockException::for(
                $this->blameWarehouse($branch, $warehouses),
                $variant,
                $quantity,
                $quantity - $outstanding,
            );
        }

        return $plan;
    }

    /**
     * How much of a variant a branch could sell right now, every warehouse
     * together.
     *
     * The same figure the storefront shows, so the answer checkout gives and
     * the answer a product page gave are the same answer. Needs no lock: it is
     * a reading, not a decision.
     */
    public function availableAtBranch(Branch $branch, ProductVariant $variant): int
    {
        return (int) $this->stockQuery($branch, $variant)
            ->get()
            ->sum(fn (Stock $stock): int => $stock->available_quantity);
    }

    /**
     * The branch's `stocks` rows for this variant, locked for this
     * transaction, in a fixed order.
     *
     * @return array<int, Stock>
     */
    private function lockedRows(Branch $branch, ProductVariant $variant): array
    {
        return $this->stockQuery($branch, $variant)
            ->orderBy('warehouse_id')
            ->lockForUpdate()
            ->get()
            ->all();
    }

    /**
     * The branch's active warehouses, keyed by id.
     *
     * Read across branches on purpose: checkout runs for the branch the
     * customer chose, which is not the actor's branch — a customer has none
     * (ADR-028).
     *
     * @return array<int, Warehouse>
     */
    private function warehousesFor(Branch $branch): array
    {
        return Warehouse::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branch->getKey())
            ->orderBy('id')
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * The rows in the order they should be drawn down: the default warehouse
     * first, then the rest by warehouse id.
     *
     * Every warehouse the branch has counts, **including inactive ones**, for
     * one reason: the figure the storefront showed the customer is the sum of
     * the branch's `stocks` rows with no warehouse filter at all
     * (`ProductCardPresenter`), and the sum checkout is willing to honour has
     * to be the same sum. Stock sitting in a deactivated warehouse is a
     * condition for the inventory screens to resolve — not grounds for
     * refusing an order the shop already promised.
     *
     * @param  array<int, Stock>  $rows
     * @param  array<int, Warehouse>  $warehouses
     * @return array<int, Stock>
     */
    private function drawOrder(array $rows, array $warehouses): array
    {
        $usable = array_values(array_filter(
            $rows,
            fn (Stock $row): bool => isset($warehouses[$row->warehouse_id]),
        ));

        usort($usable, function (Stock $a, Stock $b) use ($warehouses): int {
            $aDefault = $warehouses[$a->warehouse_id]->is_default ? 0 : 1;
            $bDefault = $warehouses[$b->warehouse_id]->is_default ? 0 : 1;

            return [$aDefault, $a->warehouse_id] <=> [$bDefault, $b->warehouse_id];
        });

        return $usable;
    }

    /**
     * @return Builder<Stock>
     */
    private function stockQuery(Branch $branch, ProductVariant $variant): Builder
    {
        return Stock::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branch->getKey())
            ->where('product_variant_id', $variant->getKey());
    }

    /**
     * The warehouse to name when the branch cannot cover a line.
     *
     * `InsufficientStockException` is about a warehouse, because that is where
     * a stock figure lives; when it is the branch as a whole that falls short
     * there is no single culprit, so its main warehouse stands for it. A
     * branch with no warehouse at all cannot sell, and saying so plainly beats
     * a null-pointer further down.
     *
     * @param  array<int, Warehouse>  $warehouses
     */
    private function blameWarehouse(Branch $branch, array $warehouses): Warehouse
    {
        foreach ($warehouses as $warehouse) {
            if ($warehouse->is_default) {
                return $warehouse;
            }
        }

        $first = reset($warehouses);

        if ($first instanceof Warehouse) {
            return $first;
        }

        throw new LogicException(
            "Cabang {$branch->name} tidak memiliki gudang, jadi tidak ada stok yang bisa dialokasikan.",
        );
    }
}
