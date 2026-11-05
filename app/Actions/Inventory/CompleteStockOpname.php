<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Enums\StockOpnameStatus;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a stock count, writing its differences to the ledger (P1-E4-T7).
 *
 * **It posts the variance, not the counted figure.** Each line remembers what
 * the system believed when the line was added (`system_quantity`), and what
 * was found on the shelf (`counted_quantity`); the difference between those
 * two is the adjustment. The alternative — setting on-hand to the counted
 * figure — would silently undo every sale and delivery that happened while the
 * shelves were being walked, which on a large count is hours.
 *
 * So a line that counted 9 where the system said 12 posts −3. If two units
 * sold during the count, the warehouse ends on 7, and that is right: nine were
 * on the shelf when counted and two have left since.
 *
 * Lines nobody counted post nothing. That is why `counted_quantity` is
 * nullable: "not counted" and "counted zero" are different findings, and only
 * the second one is an adjustment. Lines that counted exactly what was
 * expected also post nothing — a ledger row of zero would explain nothing.
 */
final class CompleteStockOpname
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @throws ValidationException When the session is not in a state that may be completed.
     */
    public function handle(StockOpname $opname, ?User $actor = null): StockOpname
    {
        return DB::transaction(function () use ($opname, $actor): StockOpname {
            $locked = StockOpname::acrossBranches()
                ->with(['items.productVariant', 'warehouse'])
                ->whereKey($opname->getKey())
                ->lockForUpdate()
                ->sole();

            $blockedReason = $locked->completionBlockedReason();

            if ($blockedReason !== null) {
                throw ValidationException::withMessages(['status' => $blockedReason]);
            }

            foreach ($locked->items as $item) {
                $difference = $item->difference();

                if ($difference === null || $difference === 0) {
                    continue;
                }

                $this->ledger->apply(
                    warehouse: $locked->warehouse,
                    variant: $item->productVariant,
                    type: StockMovementType::OpnameAdjustment,
                    quantityChange: $difference,
                    reference: $locked,
                    notes: $this->varianceNote($item),
                    actor: $actor,
                );
            }

            $locked->update([
                'status' => StockOpnameStatus::Completed,
                'approved_by' => $actor?->getKey(),
                'completed_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * What the ledger row says about the count that produced it.
     *
     * The figures are spelled out because the ledger is read long after the
     * session has scrolled out of sight, and "−3" on its own does not say
     * whether three went missing or three were found.
     */
    private function varianceNote(StockOpnameItem $item): string
    {
        return "Opname: sistem {$item->system_quantity}, hasil hitung {$item->counted_quantity}";
    }
}
