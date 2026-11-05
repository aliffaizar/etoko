<?php

namespace App\Support;

use App\Enums\StockDocumentStatus;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockIssue;
use App\Models\StockIssueItem;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * How stock documents reach the screens (P1-E4-T5, T6, T7).
 *
 * Three documents with nearly the same listing and nearly the same form, so
 * their payloads live together: a column added to one listing is a column the
 * other two can have for free, and three copies of "status, label, totals"
 * would be three chances to label the same state differently.
 *
 * The shapes mirror the TypeScript types in `resources/js/types/stock.ts`.
 */
final class StockDocumentPresenter
{
    /**
     * One row of the goods-in listing.
     *
     * @return array<string, mixed>
     */
    public static function entryRow(StockEntry $entry): array
    {
        return [
            ...self::sharedRow($entry),
            'date' => $entry->entry_date->toDateString(),
            'supplier_name' => $entry->supplier_name,
            'reference_no' => $entry->reference_no,
        ];
    }

    /**
     * One row of the goods-out listing.
     *
     * @return array<string, mixed>
     */
    public static function issueRow(StockIssue $issue): array
    {
        return [
            ...self::sharedRow($issue),
            'date' => $issue->issue_date->toDateString(),
            'reason' => $issue->reason->value,
            'reason_label' => $issue->reason->label(),
            'reference_no' => $issue->reference_no,
        ];
    }

    /**
     * The whole goods-in document, as its form reads it.
     *
     * @return array<string, mixed>
     */
    public static function entry(StockEntry $entry): array
    {
        return [
            ...self::sharedDocument($entry),
            'entry_date' => $entry->entry_date->toDateString(),
            'supplier_name' => $entry->supplier_name,
            'reference_no' => $entry->reference_no,
            'posting_blocked_reason' => $entry->postingBlockedReason(),
            'items' => $entry->items
                ->map(fn (StockEntryItem $item): array => [
                    'id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'sku' => $item->productVariant->sku,
                    'display_name' => $item->productVariant->displayName(),
                    'product_name' => $item->productVariant->product->name,
                    'quantity' => $item->quantity,
                    'unit_cost' => $item->unit_cost,
                ])
                ->all(),
        ];
    }

    /**
     * The whole goods-out document, as its form reads it.
     *
     * @return array<string, mixed>
     */
    public static function issue(StockIssue $issue): array
    {
        return [
            ...self::sharedDocument($issue),
            'issue_date' => $issue->issue_date->toDateString(),
            'reason' => $issue->reason->value,
            'reason_label' => $issue->reason->label(),
            'reference_no' => $issue->reference_no,
            'posting_blocked_reason' => $issue->postingBlockedReason(),
            'items' => $issue->items
                ->map(fn (StockIssueItem $item): array => [
                    'id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'sku' => $item->productVariant->sku,
                    'display_name' => $item->productVariant->displayName(),
                    'product_name' => $item->productVariant->product->name,
                    'quantity' => $item->quantity,
                    'unit_cost' => $item->unit_cost,
                ])
                ->all(),
        ];
    }

    /**
     * One row of the opname listing.
     *
     * @return array<string, mixed>
     */
    public static function opnameRow(StockOpname $opname): array
    {
        return [
            'id' => $opname->id,
            'code' => $opname->code,
            'status' => $opname->status->value,
            'status_label' => $opname->status->label(),
            'is_editable' => $opname->isEditable(),
            'counted_at' => $opname->counted_at->toDateString(),
            'item_count' => (int) $opname->getAttribute('items_count'),
            'counted_count' => (int) $opname->getAttribute('counted_items_count'),
            'created_by' => $opname->createdBy?->name,
            'approved_by' => $opname->approvedBy?->name,
            'completed_at' => $opname->completed_at?->toIso8601String(),
        ];
    }

    /**
     * The whole counting session, as its screen reads it.
     *
     * @return array<string, mixed>
     */
    public static function opname(StockOpname $opname): array
    {
        return [
            'id' => $opname->id,
            'code' => $opname->code,
            'status' => $opname->status->value,
            'status_label' => $opname->status->label(),
            'is_editable' => $opname->isEditable(),
            'counted_at' => $opname->counted_at->toDateString(),
            'notes' => $opname->notes,
            'created_by' => $opname->createdBy?->name,
            'approved_by' => $opname->approvedBy?->name,
            'completed_at' => $opname->completed_at?->toIso8601String(),
            'completion_blocked_reason' => $opname->completionBlockedReason(),
            'items' => $opname->items
                ->map(fn (StockOpnameItem $item): array => [
                    'id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'sku' => $item->productVariant->sku,
                    'display_name' => $item->productVariant->displayName(),
                    'product_name' => $item->productVariant->product->name,
                    'system_quantity' => $item->system_quantity,
                    'counted_quantity' => $item->counted_quantity,
                    'difference' => $item->difference(),
                    'notes' => $item->notes,
                ])
                ->all(),
        ];
    }

    /**
     * How many documents sit in each state, for the listing's stat cards.
     *
     * Counted in one grouped query rather than one per state, because a
     * listing that cost four extra queries to show four numbers would be
     * paying for them on every keystroke of the search box.
     *
     * @param  Builder<StockEntry>|Builder<StockIssue>  $query
     * @return array{draft: int, posted: int, cancelled: int, total: int}
     */
    public static function summaryFor(Builder $query): array
    {
        $counts = $query->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $draft = (int) ($counts[StockDocumentStatus::Draft->value] ?? 0);
        $posted = (int) ($counts[StockDocumentStatus::Posted->value] ?? 0);
        $cancelled = (int) ($counts[StockDocumentStatus::Cancelled->value] ?? 0);

        return [
            'draft' => $draft,
            'posted' => $posted,
            'cancelled' => $cancelled,
            'total' => $draft + $posted + $cancelled,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sharedRow(StockEntry|StockIssue $document): array
    {
        return [
            'id' => $document->id,
            'code' => $document->code,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'is_editable' => $document->isEditable(),
            'item_count' => (int) $document->getAttribute('items_count'),
            'total_quantity' => $document->totalQuantity(),
            'created_by' => $document->createdBy?->name,
            'posted_by' => $document->postedBy?->name,
            'posted_at' => $document->posted_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sharedDocument(StockEntry|StockIssue $document): array
    {
        return [
            'id' => $document->id,
            'code' => $document->code,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'is_editable' => $document->isEditable(),
            'notes' => $document->notes,
            'created_by' => $document->createdBy?->name,
            'posted_by' => $document->postedBy?->name,
            'posted_at' => $document->posted_at?->toIso8601String(),
            'total_quantity' => $document->totalQuantity(),
        ];
    }
}
