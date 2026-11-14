<?php

namespace App\Services\Pricing;

use App\Models\Voucher;

/**
 * What `VoucherValidator` decided about one voucher on one basket (P2-E5-T3).
 *
 * Either a refusal with the sentence to show, or the discount split two ways:
 *
 * - `goodsDiscount` — off the goods (percentage / fixed), already shared out
 *   across the covered lines in `lineDiscounts` (keyed like the lines that
 *   were passed in), so `order_items.discount_amount` adds up to it exactly;
 * - `shippingDiscount` — off the delivery charge (free shipping). **Null**
 *   while the delivery charge is not known yet (the cart screen), which is
 *   not the same as zero.
 */
final readonly class VoucherOutcome
{
    /**
     * @param  array<int, int>  $lineDiscounts
     */
    public function __construct(
        public Voucher $voucher,
        public ?string $rejection = null,
        public int $eligibleSubtotal = 0,
        public int $goodsDiscount = 0,
        public ?int $shippingDiscount = null,
        public array $lineDiscounts = [],
    ) {}

    public static function rejected(Voucher $voucher, string $reason): self
    {
        return new self($voucher, rejection: $reason);
    }

    public function isAccepted(): bool
    {
        return $this->rejection === null;
    }

    /**
     * Everything the voucher takes off the order, as far as it is known.
     */
    public function totalDiscount(): int
    {
        return $this->goodsDiscount + ($this->shippingDiscount ?? 0);
    }

    public function lineDiscount(int $key): int
    {
        return $this->lineDiscounts[$key] ?? 0;
    }
}
