<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;

/**
 * Writes a new order for a product's gallery (P1-E3-T7).
 *
 * The whole order arrives at once rather than one "move up" per request: a
 * drag that lands a photo five places along changes five rows, and sending
 * them separately would leave the gallery half-reordered if one request
 * failed.
 *
 * Ids that do not belong to the product are ignored rather than refused — the
 * form request has already rejected those, and this keeps the Action usable
 * from a seeder or an import.
 */
final class ReorderProductImages
{
    /**
     * @param  array<int, int>  $imageIds  Image ids in the order they should appear.
     */
    public function handle(Product $product, array $imageIds): void
    {
        DB::transaction(function () use ($product, $imageIds): void {
            $owned = $product->images()->pluck('id')->all();
            $position = 0;

            foreach ($imageIds as $imageId) {
                if (! in_array((int) $imageId, $owned, true)) {
                    continue;
                }

                ProductImage::query()
                    ->whereKey($imageId)
                    ->update(['sort_order' => $position++]);
            }

            // Photos the caller did not mention keep their relative order and
            // land after the ones it did, so a stale page cannot silently drop
            // a photo that was uploaded meanwhile out of the sequence.
            $product->images()
                ->whereNotIn('id', array_map('intval', $imageIds))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->each(function (ProductImage $image) use (&$position): void {
                    $image->fill(['sort_order' => $position++])->save();
                });
        });
    }
}
