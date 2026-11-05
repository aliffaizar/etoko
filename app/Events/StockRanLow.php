<?php

namespace App\Events;

use App\Models\Stock;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A stock row has just fallen to or below its own minimum (P1-E4-T9).
 *
 * Dispatched by `StockLedger` after the transaction commits, and only on the
 * movement that *crossed* the threshold — not on every movement that leaves
 * the row low. A branch that sells its last few units one at a time would
 * otherwise be told the same thing five times.
 *
 * `SetStockThresholds` dispatches it too: raising a minimum above what is on
 * the shelf makes the row low just as surely as selling the stock did.
 */
class StockRanLow
{
    use Dispatchable;

    public function __construct(public readonly Stock $stock) {}
}
