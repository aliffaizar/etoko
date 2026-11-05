<?php

namespace App\Actions\Inventory;

use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockIssue;
use App\Models\StockIssueItem;

/**
 * Writes the lines of a draft goods document (P1-E4-T5, T6).
 *
 * Shared by the create and update actions of both document kinds, because the
 * rule is the same for all four: the submitted lines **are** the document's
 * lines. Lines are replaced rather than merged, so removing a row in the form
 * removes it here — a merge would leave a line nobody can see on a document
 * that is about to be posted.
 *
 * Replacing is safe precisely because it only ever happens on a draft: a
 * posted document's lines are never touched again, since its ledger rows
 * explain the figures it produced.
 */
final class StockEntryLines
{
    /**
     * @param  array<int, array{product_variant_id: int, quantity: int, unit_cost?: int|null}>  $items
     */
    public static function replace(StockEntry|StockIssue $document, array $items): void
    {
        $document->items()->delete();

        if ($items === []) {
            return;
        }

        $foreignKey = $document instanceof StockEntry ? 'stock_entry_id' : 'stock_issue_id';

        $rows = array_map(fn (array $item): array => [
            $foreignKey => $document->getKey(),
            'product_variant_id' => $item['product_variant_id'],
            'quantity' => $item['quantity'],
            'unit_cost' => $item['unit_cost'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $items);

        // Inserted in one statement rather than one model at a time: the lines
        // of a draft are not audited individually (the document is), so there
        // is nothing a per-model save would add.
        $document instanceof StockEntry
            ? StockEntryItem::insert($rows)
            : StockIssueItem::insert($rows);
    }
}
