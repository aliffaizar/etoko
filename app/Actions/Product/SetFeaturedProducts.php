<?php

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Decides which products are featured and in what order (P2-E8-T4).
 *
 * The screen sends the whole list in the order it should appear, so adding,
 * removing and reordering are one decision with one answer. Products that drop
 * off the list are unflagged and lose their place; products that stay or join
 * are written through the model, so each change is audited like any other
 * product edit.
 */
final class SetFeaturedProducts
{
    /**
     * @param  array<int, int>  $productIds  Featured products, first shown first.
     */
    public function handle(array $productIds): void
    {
        $ordered = array_values(array_unique(array_map('intval', $productIds)));

        DB::transaction(function () use ($ordered): void {
            $currentlyFeatured = Product::query()
                ->where('is_featured', true)
                ->get();

            foreach ($currentlyFeatured as $product) {
                if (! in_array($product->getKey(), $ordered, strict: true)) {
                    $product->update(['is_featured' => false, 'featured_position' => null]);
                }
            }

            foreach ($ordered as $index => $productId) {
                $product = Product::query()->findOrFail($productId);
                $position = $index + 1;

                if (! $product->is_featured || $product->featured_position !== $position) {
                    $product->update(['is_featured' => true, 'featured_position' => $position]);
                }
            }
        });
    }
}
