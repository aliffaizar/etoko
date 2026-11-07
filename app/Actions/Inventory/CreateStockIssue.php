<?php

namespace App\Actions\Inventory;

use App\Enums\StockDocumentStatus;
use App\Models\StockIssue;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Drafts a goods-out document (P1-E4-T6).
 *
 * A draft only; `PostStockIssue` is what takes the stock out. Availability is
 * deliberately not checked here: between drafting and posting the figure can
 * move either way, so the check belongs where the lock is held (BR-6).
 */
final class CreateStockIssue
{
    /**
     * @param  array<string, mixed>  $attributes  Validated reason, reference_no, issue_date, notes and items.
     */
    public function handle(Warehouse $warehouse, array $attributes, ?User $actor = null): StockIssue
    {
        // Taken before the transaction opens (ADR-037): `DocumentNumber` runs
        // a transaction of its own, which nested inside this one would be a
        // savepoint — the counter's row lock would then be held for the whole
        // length of this document's work, and every draft in the company would
        // queue behind one row. A number burned by a draft that fails
        // afterwards costs nothing.
        $code = DocumentNumber::next(DocumentNumber::PREFIX_STOCK_ISSUE);

        return DB::transaction(function () use ($warehouse, $attributes, $actor, $code): StockIssue {
            $issue = StockIssue::create([
                'code' => $code,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->getKey(),
                'reason' => $attributes['reason'],
                'reference_no' => $attributes['reference_no'] ?? null,
                'issue_date' => $attributes['issue_date'],
                'status' => StockDocumentStatus::Draft,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $actor?->getKey(),
            ]);

            StockEntryLines::replace($issue, $attributes['items'] ?? []);

            return $issue;
        });
    }
}
