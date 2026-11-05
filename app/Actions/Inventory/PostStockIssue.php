<?php

namespace App\Actions\Inventory;

use App\Enums\StockDocumentStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\StockIssue;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Commits a goods-out document, moving the stock it describes (P1-E4-T6).
 *
 * The mirror of `PostStockEntry`, with one thing that cannot happen on the way
 * in: the warehouse may not hold enough. That answer changes between the
 * moment the screen is drawn and the moment "Post" is clicked, so it is
 * `StockLedger`'s to give while it holds the row lock — not the form's to
 * guess earlier (BR-6).
 *
 * When one line is short, **nothing** is issued: the transaction rolls back
 * and the shortfall is reported against the line that caused it, so the person
 * can correct that line rather than work out which of twelve went through.
 */
final class PostStockIssue
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @throws ValidationException When the document may not be posted, or the warehouse is short.
     */
    public function handle(StockIssue $issue, ?User $actor = null): StockIssue
    {
        return DB::transaction(function () use ($issue, $actor): StockIssue {
            $locked = StockIssue::acrossBranches()
                ->with(['items.productVariant', 'warehouse'])
                ->whereKey($issue->getKey())
                ->lockForUpdate()
                ->sole();

            $blockedReason = $locked->postingBlockedReason();

            if ($blockedReason !== null) {
                throw ValidationException::withMessages(['status' => $blockedReason]);
            }

            foreach ($locked->items as $item) {
                try {
                    $this->ledger->apply(
                        warehouse: $locked->warehouse,
                        variant: $item->productVariant,
                        type: StockMovementType::IssueOut,
                        // Positive on the line, negative in the ledger: the
                        // direction belongs to the document, not the line.
                        quantityChange: -$item->quantity,
                        reference: $locked,
                        unitCost: $item->unit_cost,
                        notes: $locked->reason->label(),
                        actor: $actor,
                    );
                } catch (InsufficientStockException $shortfall) {
                    // Reported against the lines as a whole rather than a line
                    // index: the message already names the SKU and the
                    // warehouse, and an index into the submitted array would
                    // only line up as long as the form's order happened to
                    // match the saved order.
                    throw ValidationException::withMessages([
                        'items' => $shortfall->getMessage(),
                    ]);
                }
            }

            $locked->update([
                'status' => StockDocumentStatus::Posted,
                'posted_by' => $actor?->getKey(),
                'posted_at' => now(),
            ]);

            return $locked;
        });
    }
}
