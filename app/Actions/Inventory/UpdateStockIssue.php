<?php

namespace App\Actions\Inventory;

use App\Models\StockIssue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a drafted goods-out document (P1-E4-T6).
 *
 * Only a draft, for the same reason `UpdateStockEntry` refuses a posted one:
 * the ledger rows a posted document wrote explain the figures it produced.
 */
final class UpdateStockIssue
{
    /**
     * @param  array<string, mixed>  $attributes  Validated reason, reference_no, issue_date, notes and items.
     *
     * @throws ValidationException When the document has already been posted or cancelled.
     */
    public function handle(StockIssue $issue, array $attributes): StockIssue
    {
        if (! $issue->isEditable()) {
            throw ValidationException::withMessages([
                'status' => "Dokumen {$issue->code} berstatus {$issue->status->label()} dan tidak bisa diubah lagi.",
            ]);
        }

        return DB::transaction(function () use ($issue, $attributes): StockIssue {
            $issue->update([
                'reason' => $attributes['reason'],
                'reference_no' => $attributes['reference_no'] ?? null,
                'issue_date' => $attributes['issue_date'],
                'notes' => $attributes['notes'] ?? null,
            ]);

            StockEntryLines::replace($issue, $attributes['items'] ?? []);

            return $issue;
        });
    }
}
