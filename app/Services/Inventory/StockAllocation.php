<?php

namespace App\Services\Inventory;

use App\Models\Warehouse;

/**
 * "Take this many of this variant out of this warehouse" (P1-E7-T2).
 *
 * One line of the plan `StockAllocator` produces. A branch may keep its stock
 * in more than one warehouse, so an order line for five units can become two
 * allocations; each one is a separate `stocks` row to hold, and a separate
 * `stock_reservations` row to be able to give back.
 */
final readonly class StockAllocation
{
    public function __construct(
        public Warehouse $warehouse,
        public int $quantity,
    ) {}
}
