<?php

namespace App\Services\Pricing;

/**
 * Where a resolved price came from (P1-E3-T10, BR-8).
 *
 * The order of the cases is the priority order from
 * `docs/02-architecture.md` §3, highest first. The two cases above
 * `BranchPrice` have no tables yet — flash sales and promotions arrive in
 * `P1-E8` — and they are named here rather than added later so the resolver's
 * contract does not change when they do: a caller already handles "a source I
 * do not specifically know about".
 */
enum PriceSource: string
{
    case FlashSale = 'flash_sale';
    case Promotion = 'promotion';
    case BranchPrice = 'branch_price';
    case VariantPrice = 'variant_price';
    case ProductBasePrice = 'product_base_price';

    public function label(): string
    {
        return match ($this) {
            self::FlashSale => 'Flash sale',
            self::Promotion => 'Promo produk',
            self::BranchPrice => 'Harga khusus cabang',
            self::VariantPrice => 'Harga varian',
            self::ProductBasePrice => 'Harga dasar produk',
        };
    }
}
