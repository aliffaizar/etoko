<?php

namespace App\Services\Pricing;

use App\Models\CartItem;
use App\Models\ProductVariant;

/**
 * One basket line as `VoucherValidator` sees it: which product, which
 * category, and what it comes to before any voucher (P2-E5-T3).
 *
 * Built from a cart line on the cart and checkout screens and from a priced
 * line inside `PlaceOrder`, so all three ask the validator the same question.
 */
final readonly class VoucherLine
{
    public function __construct(
        public int $productId,
        public int $categoryId,
        public int $subtotal,
    ) {}

    /**
     * The variant's product must be loaded.
     */
    public static function fromVariant(ProductVariant $variant, int $subtotal): self
    {
        return new self(
            productId: $variant->product->getKey(),
            categoryId: $variant->product->category_id,
            subtotal: $subtotal,
        );
    }

    /**
     * The line's variant and product must be loaded.
     */
    public static function fromCartItem(CartItem $item): self
    {
        return self::fromVariant($item->variant, $item->lineTotal());
    }
}
