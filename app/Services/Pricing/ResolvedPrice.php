<?php

namespace App\Services\Pricing;

/**
 * The price one buyer pays for one variant at one branch, and where it came
 * from (P1-E3-T10, BR-8).
 *
 * The source travels with the amount on purpose. A cart line snapshots the
 * resolved price (ADR-007), and when a customer later asks why they were
 * charged what they were, "harga khusus cabang" is the answer — not a number
 * that can no longer be traced because the branch price has since changed.
 *
 * Money is whole rupiah integers (ADR-008, BR-10).
 */
final class ResolvedPrice
{
    public function __construct(
        public int $amount,
        public PriceSource $source,
        public ?int $compareAtPrice = null,
    ) {}

    /**
     * How much is struck through, as a saving — null when there is no
     * advertised discount, and never a negative number.
     */
    public function discountAmount(): ?int
    {
        if ($this->compareAtPrice === null || $this->compareAtPrice <= $this->amount) {
            return null;
        }

        return $this->compareAtPrice - $this->amount;
    }

    /**
     * Whether a strikethrough price should be shown next to this one.
     */
    public function isDiscounted(): bool
    {
        return $this->discountAmount() !== null;
    }
}
