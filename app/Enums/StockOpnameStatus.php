<?php

namespace App\Enums;

/**
 * Where a stock count stands (P1-E4-T7).
 *
 * A count has one more state than a goods document, because counting takes
 * time: the session is **drafted** (which variants are we counting?), then
 * **in progress** while the shelves are walked and figures typed in, and only
 * **completed** when the differences are written to the ledger.
 *
 * The step matters because an in-progress count has figures in it that have
 * not moved any stock yet, and the person counting needs to be able to stop
 * for the day without that being mistaken for a finished count.
 *
 * **Cancelled** abandons the session without touching stock, and is reachable
 * from both of the unfinished states.
 */
enum StockOpnameStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::InProgress => 'Sedang dihitung',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /**
     * Whether counted figures may still be typed in.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::InProgress;
    }

    /**
     * Whether the differences have already been written to the ledger.
     */
    public function hasMovedStock(): bool
    {
        return $this === self::Completed;
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
