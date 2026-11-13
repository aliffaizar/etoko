<?php

namespace App\Events;

use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A stock transfer has just reached a new step (P2-E3-T6).
 *
 * Dispatched by each transfer action after its transaction commits, so a
 * listener never announces a step that rolled back. `actor` is who took the
 * step; they are not told about their own click.
 */
class StockTransferAdvanced
{
    use Dispatchable;

    public function __construct(
        public readonly StockTransfer $transfer,
        public readonly ?User $actor = null,
    ) {}
}
