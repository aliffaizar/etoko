<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * Corrects one warehouse's figure for one variant by hand (P1-E4-T2).
 *
 * The change is **signed and relative**, not a new total: a person correcting
 * a figure knows "two more arrived than the paperwork said" or "one was
 * broken", and a relative change is exactly what the ledger records. Setting a
 * counted total is what a stock opname is for (P1-E4-T7), where the difference
 * is worked out against the figure the system held at the moment of counting.
 *
 * Goods arriving and leaving in the ordinary way belong to `PostStockEntry` and
 * `PostStockIssue`, which carry a document; this is the escape hatch for the
 * cases that have no paperwork, which is why a note is required.
 */
final class AdjustStock
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @throws InsufficientStockException When a negative adjustment would take the figure below zero.
     */
    public function handle(
        Warehouse $warehouse,
        ProductVariant $variant,
        int $quantityChange,
        string $notes,
        ?User $actor = null,
    ): StockMovement {
        return DB::transaction(fn (): StockMovement => $this->ledger->apply(
            warehouse: $warehouse,
            variant: $variant,
            type: StockMovementType::ManualAdjustment,
            quantityChange: $quantityChange,
            notes: $notes,
            actor: $actor,
        ));
    }
}
