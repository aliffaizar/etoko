<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Pricing\EffectivePriceResolver;
use App\Services\Pricing\ResolvedPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product as the storefront draws it on a card (P1-E5-T1, T3, T7).
 *
 * The homepage rails and the catalogue grid show the same card, so they share
 * this rather than each assembling its own payload — two copies would be two
 * chances for one of them to show a price the other does not.
 *
 * Two figures, two owners (ADR-031, BR-8):
 *
 * - **Price is the catalogue's**, the same at every branch. It still comes from
 *   `EffectivePriceResolver` — never straight from `products.base_price` —
 *   because the resolver is what will apply flash sales and promotions in
 *   `P1-E8`; it is simply asked **without a branch**, so a branch's own price
 *   list never reaches the storefront.
 * - **Stock is the branch's**, because inventory is genuinely independent
 *   (BR-2). It is read from `stocks` rows of the chosen branch only.
 *
 * A branch that has switched a product off (`branch_products`) no longer hides
 * it — the catalogue is company-wide — so the card says so instead:
 * `is_sold_at_branch` is false and the shop reads "tidak dijual di cabang ini"
 * rather than the "stok habis" it would otherwise imply.
 *
 * Prices are resolved for a whole page of products in one pass, so the grid
 * costs one price query rather than one per card.
 */
final class ProductCardPresenter
{
    /**
     * Eager-loads everything a card reads.
     *
     * `Model::preventLazyLoading()` is on outside production, so a relation
     * missing here is an exception rather than a silent N+1 — which is exactly
     * why this lives next to the presenter that reads them instead of being
     * repeated at each call site.
     *
     * The variants are narrowed to active ones, because an inactive variant is
     * not for sale and its price must not become the "mulai dari" figure; the
     * stock rows and the branch setting are narrowed to the chosen branch,
     * because another branch's shelves are not this shopper's (BR-2).
     *
     * `branchSettings` is cheap to load: only deviations are stored, so the
     * table holds a handful of rows for the whole company (P1-E3-T9).
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public static function withCardRelations(Builder $query, Branch $stockBranch): Builder
    {
        return $query->with([
            'category:id,name,slug',
            'primaryImage',
            'branchSettings' => fn (HasMany $settings) => $settings
                ->where('branch_id', $stockBranch->getKey()),
            'variants' => fn (HasMany $variants) => $variants->active()->with([
                'stocks' => fn (HasMany $stocks) => $stocks
                    ->where('branch_id', $stockBranch->getKey()),
            ]),
        ]);
    }

    public function __construct(private EffectivePriceResolver $prices) {}

    /**
     * @param  Collection<int, Product>  $products  Loaded through `withCardRelations()`.
     * @return array<int, array<string, mixed>>
     */
    public function cards(Collection $products): array
    {
        // No branch: the storefront charges the catalogue price (ADR-031).
        $resolved = $this->prices->forMany($this->variantsOf($products), null);

        return $products
            ->map(fn (Product $product): array => $this->card($product, $resolved))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, ResolvedPrice>  $resolved  Keyed by variant id.
     * @return array<string, mixed>
     */
    private function card(Product $product, array $resolved): array
    {
        $cheapest = $this->cheapestVariant($product, $resolved);
        $price = $cheapest instanceof ProductVariant ? $resolved[$cheapest->getKey()] : null;
        $available = $this->availableQuantity($product);
        $image = $product->primaryImage;
        $isSoldAtBranch = BranchProduct::availabilityFor($product->branchSettings->first());

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'short_description' => $product->short_description,
            'category_name' => $product->category->name,
            'category_slug' => $product->category->slug,
            'image_url' => $image?->url(),
            // Null-coalescing already reads a missing image as absent, so the
            // property access needs no nullsafe operator of its own.
            'image_alt' => $image->alt_text ?? $product->name,
            'price' => $price?->amount,
            'compare_at_price' => $price?->compareAtPrice,
            'price_source' => $price?->source->value,
            // True when the options are not all the same price, which is what
            // turns the card's figure into a "mulai dari".
            'has_price_range' => $this->hasPriceRange($product, $resolved),
            'variant_count' => $product->variants->count(),
            'available_quantity' => $available,
            'is_in_stock' => $available > 0,
            // False only when the branch has said it does not stock this
            // product, which is a different statement from "sold out".
            'is_sold_at_branch' => $isSoldAtBranch,
            'is_featured' => $product->is_featured,
            'rating_avg' => (float) $product->rating_avg,
            'rating_count' => $product->rating_count,
            'sold_count' => $product->sold_count,
        ];
    }

    /**
     * Every active variant of every product, as one collection for the
     * resolver's batch call.
     *
     * @param  Collection<int, Product>  $products
     * @return Collection<int, ProductVariant>
     */
    private function variantsOf(Collection $products): Collection
    {
        /** @var Collection<int, ProductVariant> $variants */
        $variants = new Collection;

        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                // The resolver falls back to the product's own price, so the
                // relation it reads has to be the instance already in memory.
                $variant->setRelation('product', $product);
                $variants->push($variant);
            }
        }

        return $variants;
    }

    /**
     * The variant whose resolved price the card shows: the cheapest one a
     * buyer could pick.
     *
     * @param  array<int, ResolvedPrice>  $resolved
     */
    private function cheapestVariant(Product $product, array $resolved): ?ProductVariant
    {
        return $product->variants
            ->sortBy(fn (ProductVariant $variant): int => $resolved[$variant->getKey()]->amount)
            ->first();
    }

    /**
     * @param  array<int, ResolvedPrice>  $resolved
     */
    private function hasPriceRange(Product $product, array $resolved): bool
    {
        $amounts = $product->variants
            ->map(fn (ProductVariant $variant): int => $resolved[$variant->getKey()]->amount)
            ->unique();

        return $amounts->count() > 1;
    }

    /**
     * What the branch can still sell of this product (BR-6).
     *
     * Summed from the `stocks` rows loaded for the chosen branch, so a product
     * the branch has never stocked reads as zero rather than as unknown
     * (ADR-023).
     */
    private function availableQuantity(Product $product): int
    {
        return (int) $product->variants
            ->flatMap(fn (ProductVariant $variant) => $variant->stocks)
            ->sum('available_quantity');
    }
}
