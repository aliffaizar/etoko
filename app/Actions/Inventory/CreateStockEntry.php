<?php

namespace App\Actions\Inventory;

use App\Enums\StockDocumentStatus;
use App\Models\StockEntry;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Drafts a goods-in document (P1-E4-T5).
 *
 * A draft only, always: nothing reaches the warehouse until `PostStockEntry`
 * runs. That separation is the point of having documents at all — a delivery
 * is typed from the note in hand, checked against the goods, and only then
 * committed.
 *
 * The document number comes from `DocumentNumber`, which hands out
 * `IN-20261105-0001` under a row lock, so two staff drafting at once cannot
 * receive the same one.
 */
final class CreateStockEntry
{
    /**
     * @param  array<string, mixed>  $attributes  Validated supplier_name, reference_no, entry_date, notes and items.
     */
    public function handle(Warehouse $warehouse, array $attributes, ?User $actor = null): StockEntry
    {
        // Taken before the transaction opens (ADR-037): `DocumentNumber` runs
        // a transaction of its own, which nested inside this one would be a
        // savepoint — the counter's row lock would then be held for the whole
        // length of this document's work, and every draft in the company would
        // queue behind one row. A number burned by a draft that fails
        // afterwards costs nothing.
        $code = DocumentNumber::next(DocumentNumber::PREFIX_STOCK_ENTRY);

        return DB::transaction(function () use ($warehouse, $attributes, $actor, $code): StockEntry {
            $entry = StockEntry::create([
                'code' => $code,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->getKey(),
                'supplier_name' => $attributes['supplier_name'] ?? null,
                'reference_no' => $attributes['reference_no'] ?? null,
                'entry_date' => $attributes['entry_date'],
                'status' => StockDocumentStatus::Draft,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $actor?->getKey(),
            ]);

            StockEntryLines::replace($entry, $attributes['items'] ?? []);

            return $entry;
        });
    }
}
