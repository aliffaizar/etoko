<?php

namespace App\Actions\Inventory;

use App\Enums\StockDocumentStatus;
use App\Enums\StockMovementType;
use App\Models\StockEntry;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Commits a goods-in document, moving the stock it describes (P1-E4-T5).
 *
 * Posting is the one moment a goods-in note touches the warehouse. Before it
 * the note is a draft that can be edited freely; after it the note is history,
 * because the ledger rows it wrote are what explain the figures it produced. A
 * mistake is undone by posting the opposite goods-out note, never by editing
 * this one.
 *
 * The whole document posts or none of it does. A note of twelve lines that
 * applied eight of them would leave the warehouse in a state no document
 * describes, so the transaction wraps every line — and the status change with
 * them.
 *
 * Posting twice is refused inside the transaction, on a locked row: two staff
 * clicking "Post" at the same moment would otherwise both read "draft" and
 * both add the goods.
 */
final class PostStockEntry
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @throws ValidationException When the document is not in a state that may be posted.
     */
    public function handle(StockEntry $entry, ?User $actor = null): StockEntry
    {
        return DB::transaction(function () use ($entry, $actor): StockEntry {
            $locked = StockEntry::acrossBranches()
                ->with(['items.productVariant', 'warehouse'])
                ->whereKey($entry->getKey())
                ->lockForUpdate()
                ->sole();

            $blockedReason = $locked->postingBlockedReason();

            if ($blockedReason !== null) {
                throw ValidationException::withMessages(['status' => $blockedReason]);
            }

            foreach ($locked->items as $item) {
                $this->ledger->apply(
                    warehouse: $locked->warehouse,
                    variant: $item->productVariant,
                    type: StockMovementType::EntryIn,
                    quantityChange: $item->quantity,
                    reference: $locked,
                    unitCost: $item->unit_cost,
                    notes: $this->supplierNote($locked),
                    actor: $actor,
                );
            }

            $locked->update([
                'status' => StockDocumentStatus::Posted,
                'posted_by' => $actor?->getKey(),
                'posted_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * What the ledger row says about where the goods came from.
     *
     * The movement already points at the document, but the history screen is
     * read far more often than it is clicked through, so the supplier's name
     * is worth carrying along.
     */
    private function supplierNote(StockEntry $entry): ?string
    {
        return $entry->supplier_name === null
            ? null
            : "Dari {$entry->supplier_name}";
    }
}
