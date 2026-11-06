<?php

use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Pricing\CatalogPriceQuery;
use App\Services\Pricing\EffectivePriceResolver;

/*
 * The SQL price expression the catalogue sorts and filters by (P1-E5-T3, BR-8).
 *
 * `EffectivePriceResolver` is the authority on what a buyer pays;
 * `CatalogPriceQuery` says the same thing where the database can order by it.
 * These tests exist to make the two fail together: if a step is added to the
 * resolver's priority order (flash sales and promotions arrive in P1-E8) and
 * not to the expression, the catalogue would start sorting by a price nobody is
 * charged — and that is the kind of bug nothing else notices.
 */

/** The expression's answer for one product, read back as an integer. */
function lowestPriceOf(Product $product, ?Branch $branch): int
{
    $row = Product::query()
        ->whereKey($product->getKey())
        ->selectSub(CatalogPriceQuery::lowestPrice($branch), 'lowest')
        ->firstOrFail();

    return (int) $row->getAttribute('lowest');
}

/** The resolver's answer for the same product's cheapest variant. */
function resolvedLowestPriceOf(Product $product, ?Branch $branch): int
{
    $variants = $product->variants()->active()->with('product')->get();

    return min(array_map(
        fn ($price): int => $price->amount,
        app(EffectivePriceResolver::class)->forMany($variants, $branch),
    ));
}

it('agrees with the resolver on a product priced by its base price', function () {
    $branch = Branch::factory()->default()->create();
    $product = Product::factory()->withDefaultVariant()->create(['base_price' => 150_000]);

    expect(lowestPriceOf($product, $branch))->toBe(150_000)
        ->and(lowestPriceOf($product, $branch))->toBe(resolvedLowestPriceOf($product, $branch));
});

it('agrees with the resolver when a variant overrides the price', function () {
    $branch = Branch::factory()->default()->create();
    $product = Product::factory()->create(['base_price' => 150_000]);
    ProductVariant::factory()->for($product)->default()->create();
    ProductVariant::factory()->for($product)->overriding(120_000)->create();

    expect(lowestPriceOf($product, $branch))->toBe(120_000)
        ->and(lowestPriceOf($product, $branch))->toBe(resolvedLowestPriceOf($product, $branch));
});

it('agrees with the resolver when the branch charges its own price', function () {
    $branch = Branch::factory()->default()->create();
    $product = Product::factory()->create(['base_price' => 150_000]);
    $variant = ProductVariant::factory()->for($product)->default()->create();

    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')->at(99_000)->create();

    expect(lowestPriceOf($product, $branch))->toBe(99_000)
        ->and(lowestPriceOf($product, $branch))->toBe(resolvedLowestPriceOf($product, $branch));
});

it('prefers the branch price over a variant override, as the resolver does', function () {
    $branch = Branch::factory()->default()->create();
    $product = Product::factory()->create(['base_price' => 150_000]);
    $variant = ProductVariant::factory()->for($product)->default()->overriding(120_000)->create();

    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')->at(99_000)->create();

    expect(lowestPriceOf($product, $branch))->toBe(99_000)
        ->and(lowestPriceOf($product, $branch))->toBe(resolvedLowestPriceOf($product, $branch));
});

it('ignores a branch price that is switched off', function () {
    $branch = Branch::factory()->default()->create();
    $product = Product::factory()->create(['base_price' => 150_000]);
    $variant = ProductVariant::factory()->for($product)->default()->create();

    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')->at(99_000)->inactive()->create();

    expect(lowestPriceOf($product, $branch))->toBe(150_000)
        ->and(lowestPriceOf($product, $branch))->toBe(resolvedLowestPriceOf($product, $branch));
});

it('ignores a branch price that has not started yet', function () {
    $branch = Branch::factory()->default()->create();
    $product = Product::factory()->create(['base_price' => 150_000]);
    $variant = ProductVariant::factory()->for($product)->default()->create();

    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')
        ->at(99_000)
        ->startingAt(now()->addDay())
        ->create();

    expect(lowestPriceOf($product, $branch))->toBe(150_000)
        ->and(lowestPriceOf($product, $branch))->toBe(resolvedLowestPriceOf($product, $branch));
});

it('ignores a branch price that has already finished', function () {
    $branch = Branch::factory()->default()->create();
    $product = Product::factory()->create(['base_price' => 150_000]);
    $variant = ProductVariant::factory()->for($product)->default()->create();

    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')
        ->at(99_000)
        ->endingAt(now()->subDay())
        ->create();

    expect(lowestPriceOf($product, $branch))->toBe(150_000);
});

it('ignores another branch price', function () {
    $here = Branch::factory()->default()->create();
    $elsewhere = Branch::factory()->create();
    $product = Product::factory()->create(['base_price' => 150_000]);
    $variant = ProductVariant::factory()->for($product)->default()->create();

    BranchProductPrice::factory()->for($elsewhere)->for($variant, 'variant')->at(50_000)->create();

    expect(lowestPriceOf($product, $here))->toBe(150_000);
});

it('ignores an inactive variant when picking the lowest price', function () {
    $branch = Branch::factory()->default()->create();
    $product = Product::factory()->create(['base_price' => 150_000]);
    ProductVariant::factory()->for($product)->default()->create();
    ProductVariant::factory()->for($product)->inactive()->overriding(10_000)->create();

    expect(lowestPriceOf($product, $branch))->toBe(150_000)
        ->and(lowestPriceOf($product, $branch))->toBe(resolvedLowestPriceOf($product, $branch));
});

it('falls back to the catalogue price when there is no branch at all', function () {
    $product = Product::factory()->create(['base_price' => 150_000]);
    ProductVariant::factory()->for($product)->default()->overriding(120_000)->create();

    expect(lowestPriceOf($product, null))->toBe(120_000)
        ->and(lowestPriceOf($product, null))->toBe(resolvedLowestPriceOf($product, null));
});
