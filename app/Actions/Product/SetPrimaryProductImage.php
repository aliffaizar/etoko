<?php

namespace App\Actions\Product;

use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;

/**
 * Makes one photo the product's primary one, demoting the previous (P1-E3-T7).
 *
 * Exactly one photo per product carries the flag: it is the thumbnail every
 * listing, cart line and order line shows. Promotion and demotion happen in
 * one transaction so that stays true, the same way `SetDefaultProductVariant`
 * keeps one default variant.
 */
final class SetPrimaryProductImage
{
    public function handle(ProductImage $image): ProductImage
    {
        return DB::transaction(function () use ($image): ProductImage {
            ProductImage::query()
                ->where('product_id', $image->product_id)
                ->whereKeyNot($image->getKey())
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            $image->fill(['is_primary' => true])->save();

            return $image;
        });
    }
}
