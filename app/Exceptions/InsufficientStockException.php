<?php

namespace App\Exceptions;

use App\Models\ProductVariant;
use App\Models\Warehouse;
use RuntimeException;

/**
 * A stock movement was refused because the warehouse does not hold enough
 * (P1-E4-T2).
 *
 * Thrown by `StockLedger` while it holds the row lock, so it is the last word
 * on the matter: a caller that checked availability a moment earlier and lost
 * the race lands here rather than driving the figure negative (BR-6).
 *
 * Callers that submit a form turn this into a `ValidationException` on the
 * offending line, so the person sees it next to the quantity they typed.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly Warehouse $warehouse,
        public readonly ProductVariant $variant,
        public readonly int $requested,
        public readonly int $available,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(
        Warehouse $warehouse,
        ProductVariant $variant,
        int $requested,
        int $available,
    ): self {
        return new self(
            $warehouse,
            $variant,
            $requested,
            $available,
            "Stok {$variant->sku} di gudang {$warehouse->name} tinggal {$available} unit, "
                ."sedangkan yang diminta {$requested} unit.",
        );
    }
}
