<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Branch\StorefrontBranch;
use App\Services\Pricing\EffectivePriceResolver;
use App\Services\Pricing\ResolvedPrice;
use App\Support\StorefrontCatalogQuery;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One product, as a shopper sees it (P1-E5-T6, P1-E5-T7).
 *
 * Found through `StorefrontCatalogQuery`, not through the slug alone, so the
 * same gates that keep a product out of the catalogue keep it out of here: a
 * draft and a product in a switched-off category both answer 404 rather than
 * rendering a page nobody can buy from. A 404 and not a 403 — the shopper is
 * not being refused, the shop simply does not have it.
 *
 * A product the chosen branch does not stock **does** render (ADR-031): the
 * catalogue is company-wide, so the page says "tidak dijual di cabang ini"
 * instead of pretending the product does not exist.
 *
 * Each variant carries the **catalogue** price — the same at every branch —
 * and its own availability at **this** branch (ADR-031, BR-2, BR-6), because
 * that is what the option picker switches between: choosing "XL" changes
 * whether it can be bought here, not what it costs.
 */
class ProductController extends Controller
{
    public function show(
        string $slug,
        StorefrontBranch $storefront,
        EffectivePriceResolver $prices,
    ): Response {
        $branch = $storefront->currentOrFail();
        $product = $this->findOfferedProduct($slug, $branch);
        // No branch: the storefront charges the catalogue price (ADR-031).
        $resolved = $prices->forMany($product->variants, null);

        $user = auth()->user();
        $isWishlisted = $user instanceof User
            && $user->wishlists()->where('product_id', $product->getKey())->exists();

        return Inertia::render('shop/product', [
            'product' => $this->productProps($product, $resolved),
            'variants' => $this->variantProps($product, $resolved),
            'images' => $this->imageProps($product),
            'attributes' => $this->attributeProps($product),
            'breadcrumbs' => $this->categoryTrail($product),
            'branch_availability' => $this->availabilityProps($product),
            'is_wishlisted' => $isWishlisted,
        ]);
    }

    /**
     * The product, or a 404.
     *
     * `preventLazyLoading()` is on outside production, so everything the page
     * touches is named here — including the pivot attribute values the option
     * picker is built from, and the branch's own stock rows.
     */
    private function findOfferedProduct(string $slug, Branch $stockBranch): Product
    {
        /** @var Product $product */
        $product = StorefrontCatalogQuery::offered()
            ->where('slug', $slug)
            ->with([
                'category:id,parent_id,name,slug',
                'branchSettings' => fn (HasMany $settings) => $settings
                    ->where('branch_id', $stockBranch->getKey()),
                'images' => fn (HasMany $images) => $images->ordered(),
                'variants' => fn (HasMany $variants) => $variants
                    ->active()
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->with([
                        'attributeValues' => fn (BelongsToMany $values) => $values
                            ->with('attribute')
                            ->ordered(),
                        'stocks' => fn (HasMany $stocks) => $stocks
                            ->where('branch_id', $stockBranch->getKey()),
                    ]),
            ])
            ->firstOrFail();

        // The price resolver falls back to the product's own price, and the
        // variants were loaded through the product — so hand each of them the
        // instance already in memory rather than letting it reach for a lazy
        // load that `preventLazyLoading()` would refuse.
        $product->variants->each(
            fn (ProductVariant $variant) => $variant->setRelation('product', $product),
        );

        return $product;
    }

    /**
     * @param  array<int, ResolvedPrice>  $resolved
     * @return array<string, mixed>
     */
    private function productProps(Product $product, array $resolved): array
    {
        $amounts = array_map(fn (ResolvedPrice $price): int => $price->amount, $resolved);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'type' => $product->type->value,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'category_name' => $product->category->name,
            'category_slug' => $product->category->slug,
            'weight_gram' => $product->weight_gram,
            'length_cm' => $product->length_cm,
            'width_cm' => $product->width_cm,
            'height_cm' => $product->height_cm,
            'rating_avg' => (float) $product->rating_avg,
            'rating_count' => $product->rating_count,
            'sold_count' => $product->sold_count,
            'is_featured' => $product->is_featured,
            // The lowest and highest a buyer could pay here, so the heading can
            // say "mulai dari" before any option has been picked.
            'price_from' => $amounts === [] ? null : min($amounts),
            'price_to' => $amounts === [] ? null : max($amounts),
            'meta_title' => $product->meta_title,
            'meta_description' => $product->meta_description,
        ];
    }

    /**
     * Each sellable option, with the price and the availability that belong to
     * it at this branch.
     *
     * @param  array<int, ResolvedPrice>  $resolved
     * @return array<int, array<string, mixed>>
     */
    private function variantProps(Product $product, array $resolved): array
    {
        return $product->variants
            ->map(function (ProductVariant $variant) use ($resolved): array {
                $price = $resolved[$variant->getKey()];
                $available = (int) $variant->stocks->sum('available_quantity');

                return [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->displayName(),
                    'price' => $price->amount,
                    'compare_at_price' => $price->compareAtPrice,
                    'discount_amount' => $price->discountAmount(),
                    // Kept so the page can name a price that *beats* the
                    // catalogue's — a flash sale or a promotion in `P1-E8`. A
                    // plain catalogue price needs no explanation and the screen
                    // shows none (ADR-031).
                    'price_source' => $price->source->value,
                    'price_source_label' => $price->source->label(),
                    'available_quantity' => $available,
                    'is_in_stock' => $available > 0,
                    'weight_gram' => $variant->effectiveWeightGram(),
                    'image_url' => $variant->imageUrl(),
                    /** Attribute value ids, which is what the picker matches on. */
                    'attribute_value_ids' => $variant->attributeValues->modelKeys(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, url: string, alt: string, is_primary: bool, variant_id: int|null}>
     */
    private function imageProps(Product $product): array
    {
        return $product->images
            ->map(fn (ProductImage $image): array => [
                'id' => $image->id,
                'url' => $image->url(),
                'alt' => $image->alt_text ?? $product->name,
                'is_primary' => $image->is_primary,
                // Set when the photo shows one option, so picking it swaps the
                // gallery rather than leaving a colour nobody chose on screen.
                'variant_id' => $image->product_variant_id,
            ])
            ->values()
            ->all();
    }

    /**
     * The option groups the picker is drawn from, in catalogue order.
     *
     * Built from the variants' own attribute values rather than from the whole
     * attribute catalogue, so a value no variant of this product carries is
     * never offered (ADR-021).
     *
     * @return array<int, array{id: int, name: string, type: string, values: array<int, array{id: int, label: string, color_hex: string|null}>}>
     */
    private function attributeProps(Product $product): array
    {
        /** @var array<int, ProductAttribute> $attributes */
        $attributes = [];
        /** @var array<int, array<int, ProductAttributeValue>> $valuesByAttribute */
        $valuesByAttribute = [];

        foreach ($product->variants as $variant) {
            foreach ($variant->attributeValues as $value) {
                $attribute = $value->attribute;
                $attributes[$attribute->getKey()] = $attribute;
                $valuesByAttribute[$attribute->getKey()][$value->getKey()] = $value;
            }
        }

        uasort(
            $attributes,
            fn (ProductAttribute $a, ProductAttribute $b): int => [$a->sort_order, $a->name] <=> [$b->sort_order, $b->name],
        );

        return array_values(array_map(
            fn (ProductAttribute $attribute): array => [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'type' => $attribute->type->value,
                'values' => $this->valueOptions($valuesByAttribute[$attribute->getKey()]),
            ],
            $attributes,
        ));
    }

    /**
     * @param  array<int, ProductAttributeValue>  $values
     * @return array<int, array{id: int, label: string, color_hex: string|null}>
     */
    private function valueOptions(array $values): array
    {
        uasort(
            $values,
            fn (ProductAttributeValue $a, ProductAttributeValue $b): int => [$a->sort_order, $a->value] <=> [$b->sort_order, $b->value],
        );

        return array_values(array_map(
            fn (ProductAttributeValue $value): array => [
                'id' => $value->id,
                'label' => $value->displayLabel(),
                'color_hex' => $value->color_hex,
            ],
            $values,
        ));
    }

    /**
     * What this branch holds of the product as a whole, for the summary above
     * the option picker.
     *
     * @return array{available_quantity: int, is_in_stock: bool, is_sold_at_branch: bool, variant_count: int, in_stock_variant_count: int}
     */
    private function availabilityProps(Product $product): array
    {
        $perVariant = $product->variants
            ->map(fn (ProductVariant $variant): int => (int) $variant->stocks->sum('available_quantity'));

        return [
            'available_quantity' => (int) $perVariant->sum(),
            'is_in_stock' => $perVariant->sum() > 0,
            // A branch that has switched the product off is saying it does not
            // stock it, which the page states rather than calling it "sold out"
            // — the two mean different things to somebody waiting for it
            // (ADR-031).
            'is_sold_at_branch' => BranchProduct::availabilityFor($product->branchSettings->first()),
            'variant_count' => $product->variants->count(),
            'in_stock_variant_count' => $perVariant->filter(fn (int $quantity): bool => $quantity > 0)->count(),
        ];
    }

    /**
     * The category trail above the product, nearest root first, ending in the
     * product's own category.
     *
     * @return array<int, array{name: string, slug: string}>
     */
    private function categoryTrail(Product $product): array
    {
        $categories = [...$product->category->ancestors(), $product->category];

        return array_map(
            fn (Category $category): array => ['name' => $category->name, 'slug' => $category->slug],
            $categories,
        );
    }
}
