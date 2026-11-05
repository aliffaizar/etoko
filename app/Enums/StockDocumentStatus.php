<?php

namespace App\Enums;

/**
 * Where a goods-in or goods-out document stands (P1-E4-T5, T6).
 *
 * A document is a **draft** while it is being typed: lines come and go and
 * nothing has touched the warehouse. **Posting** is the moment the figures
 * move, and it happens once — a posted document is never edited and never
 * deleted, because its ledger rows explain the figure it produced. A mistake
 * is undone by posting the opposite document, not by rewriting the mistake.
 *
 * **Cancelled** is therefore only reachable from draft: it is "never mind",
 * not "undo".
 */
enum StockDocumentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Posted => 'Diposting',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /**
     * Whether the document's lines may still be changed.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the document has already moved stock.
     */
    public function hasMovedStock(): bool
    {
        return $this === self::Posted;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
