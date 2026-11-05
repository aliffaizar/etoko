<?php

namespace App\Actions\Inventory;

use App\Enums\StockDocumentStatus;
use App\Enums\StockOpnameStatus;
use App\Models\StockEntry;
use App\Models\StockIssue;
use App\Models\StockOpname;
use Illuminate\Validation\ValidationException;

/**
 * Abandons an unfinished stock document (P1-E4-T5, T6, T7).
 *
 * Cancelling is "never mind", not "undo". It is only reachable while the
 * document has not moved any stock, because a document that has is explained
 * by ledger rows that cannot be taken back — the way to reverse posted goods
 * is the opposite document, which leaves both halves on the record.
 *
 * One action for all three kinds, because the rule does not differ between
 * them; only the enum does, and `isEditable()` is the question both enums
 * answer the same way.
 */
final class CancelStockDocument
{
    /**
     * @throws ValidationException When the document has already moved stock.
     */
    public function handle(StockEntry|StockIssue|StockOpname $document): StockEntry|StockIssue|StockOpname
    {
        if (! $document->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => "Dokumen {$document->code} berstatus {$document->status->label()} dan tidak bisa dibatalkan. "
                    .'Untuk membalik stok yang sudah berpindah, buat dokumen kebalikannya.',
            ]);
        }

        $document->update([
            'status' => $document instanceof StockOpname
                ? StockOpnameStatus::Cancelled
                : StockDocumentStatus::Cancelled,
        ]);

        return $document;
    }
}
