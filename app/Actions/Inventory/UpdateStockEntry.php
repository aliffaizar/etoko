<?php

namespace App\Actions\Inventory;

use App\Models\StockEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a drafted goods-in document (P1-E4-T5).
 *
 * Only a draft. A posted document is history: the ledger rows it wrote explain
 * the figures it produced, and editing it would leave those rows pointing at a
 * document that no longer says what they say it does. The refusal lives here
 * as well as in the controller, so a job or a command cannot get round it.
 */
final class UpdateStockEntry
{
    /**
     * @param  array<string, mixed>  $attributes  Validated supplier_name, reference_no, entry_date, notes and items.
     *
     * @throws ValidationException When the document has already been posted or cancelled.
     */
    public function handle(StockEntry $entry, array $attributes): StockEntry
    {
        if (! $entry->isEditable()) {
            throw ValidationException::withMessages([
                'status' => "Dokumen {$entry->code} berstatus {$entry->status->label()} dan tidak bisa diubah lagi.",
            ]);
        }

        return DB::transaction(function () use ($entry, $attributes): StockEntry {
            $entry->update([
                'supplier_name' => $attributes['supplier_name'] ?? null,
                'reference_no' => $attributes['reference_no'] ?? null,
                'entry_date' => $attributes['entry_date'],
                'notes' => $attributes['notes'] ?? null,
            ]);

            StockEntryLines::replace($entry, $attributes['items'] ?? []);

            return $entry;
        });
    }
}
