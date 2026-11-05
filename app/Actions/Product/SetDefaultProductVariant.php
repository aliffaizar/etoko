<?php

namespace App\Actions\Product;

use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Makes one variant the product's default, demoting the previous one
 * (P1-E3-T6).
 *
 * Exactly one variant per product carries the flag: it is the price a listing
 * shows before the buyer has picked anything, and the variant a POS screen
 * reaches for when a barcode scan matches the product rather than a specific
 * variant. Promotion and demotion happen in one transaction so that stays
 * true, the same way `SetDefaultWarehouse` keeps one default warehouse.
 */
final class SetDefaultProductVariant
{
    public function handle(ProductVariant $variant): ProductVariant
    {
        return DB::transaction(function () use ($variant): ProductVariant {
            ProductVariant::query()
                ->where('product_id', $variant->product_id)
                ->whereKeyNot($variant->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);

            // The default has to stay sellable: it is what a listing prices and
            // what a scan without a variant lands on.
            $variant->fill(['is_default' => true, 'is_active' => true])->save();

            return $variant;
        });
    }
}
