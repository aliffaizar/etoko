<?php

namespace App\Actions\Review;

use App\Enums\ReviewStatus;
use App\Models\Product;
use App\Models\Review;

/**
 * The only writer of `products.rating_avg` and `products.rating_count`
 * (P2-E8-T3, ADR-068).
 *
 * Both are recomputed from the approved reviews rather than adjusted by a
 * delta, so a review moved in or out of view, twice, still leaves the figure
 * right. Called by the moderation and reply actions after the status changes.
 *
 * Written through the query builder: a rating refresh is not an edit to the
 * product and must not fire the product observers.
 */
final class RefreshProductRating
{
    /**
     * @param  int|array<int, int>  $productIds
     */
    public function handle(int|array $productIds): void
    {
        foreach (array_unique((array) $productIds) as $productId) {
            $figures = Review::query()
                ->where('product_id', $productId)
                ->where('status', ReviewStatus::Approved)
                ->selectRaw('COUNT(*) AS total, AVG(rating) AS average')
                ->toBase()
                ->first();

            Product::query()
                ->withTrashed()
                ->whereKey($productId)
                ->toBase()
                ->update([
                    'rating_count' => (int) ($figures->total ?? 0),
                    'rating_avg' => round((float) ($figures->average ?? 0), 2),
                ]);
        }
    }
}
