<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Pricing\EffectivePriceResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The only writer of `products.lowest_price` (P2-E8-T2, ADR-066).
 *
 * The figure is the cheapest price the storefront charges for the product:
 * `EffectivePriceResolver` with no branch, over the product's active variants.
 * Asking the resolver rather than repeating its rules in SQL is the point — the
 * sort and the price filter then agree with the card and the cart by
 * construction, and a new step in the resolver reaches the catalogue without
 * a second change.
 *
 * Callers are the places a price can move: a variant or product saved or
 * deleted (observers), a promotion saved, switched or dated (`SavePromotion`,
 * `SyncPromotionPrices`). Branch prices are not among them: the storefront
 * ignores them (ADR-031), so they never move the catalogue figure.
 *
 * The update is a query-builder write, so it fires no model events and does not
 * touch `updated_at`: a price refresh is not an edit to the product.
 */
final class RefreshLowestPrice
{
    public function __construct(private readonly EffectivePriceResolver $prices) {}

    /**
     * @param  Collection<int, int>|array<int, int>|int  $productIds
     */
    public function handle(Collection|array|int $productIds, ?Carbon $moment = null): void
    {
        $ids = collect(is_iterable($productIds) ? $productIds : [$productIds])
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $moment ??= Carbon::now();

        $variants = ProductVariant::query()
            ->whereIn('product_id', $ids)
            ->where('is_active', true)
            ->with(['product' => fn ($products) => $products->withTrashed()])
            ->get();

        $resolved = $this->prices->forMany($variants, null, $moment);

        $lowest = [];

        foreach ($variants as $variant) {
            $amount = $resolved[$variant->getKey()]->amount;
            $lowest[$variant->product_id] = min($lowest[$variant->product_id] ?? $amount, $amount);
        }

        foreach ($ids as $productId) {
            Product::query()
                ->withTrashed()
                ->whereKey($productId)
                ->toBase()
                ->update(['lowest_price' => $lowest[$productId] ?? null]);
        }
    }
}
