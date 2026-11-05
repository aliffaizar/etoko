<?php

namespace App\Enums;

/**
 * Why stock left the warehouse without being sold (P1-E4-T6).
 *
 * Required on every goods-out document, because "the figure went down by
 * four" is not an answer anybody can act on. The reasons are a closed list so
 * that shrinkage can be counted by cause later, rather than read out of free
 * text.
 *
 * A sale is **not** on this list: stock sold leaves through an order, as a
 * `sale_out` movement, so it never needs a goods-out document.
 */
enum StockIssueReason: string
{
    case Damage = 'damage';
    case Loss = 'loss';
    case InternalUse = 'internal_use';
    case Sample = 'sample';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Damage => 'Rusak',
            self::Loss => 'Hilang',
            self::InternalUse => 'Pemakaian internal',
            self::Sample => 'Sampel',
            self::Other => 'Lainnya',
        };
    }

    /**
     * Whether the person has to say more than the reason itself.
     *
     * "Lainnya" explains nothing on its own, so the document's notes become
     * required alongside it.
     */
    public function requiresNotes(): bool
    {
        return $this === self::Other;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
            self::cases(),
        );
    }
}
