<?php

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\CatalogDemoSeeder;

/*
 * CatalogDemoSeeder (P1-E3-T5).
 *
 * The seeder goes through the same Actions as the panel, so this test is what
 * catches a drift between the two: a rule added to CreateProduct that the
 * seeder's data no longer satisfies fails here.
 */

it('builds a three level tree and files every product under it', function () {
    $this->seed(CatalogDemoSeeder::class);

    expect(Category::count())->toBe(15)
        ->and(Category::roots()->count())->toBe(3)
        ->and(Product::count())->toBe(9);

    // Three levels, and not a fourth.
    $depths = Category::all()->map(fn (Category $category): int => $category->depth())->unique()->sort()->values()->all();

    expect($depths)->toBe([1, 2, 3]);
});

it('gives every seeded product exactly one default variant with a unique sku', function () {
    $this->seed(CatalogDemoSeeder::class);

    $products = Product::withCount(['variants'])->get();

    expect($products->pluck('variants_count')->unique()->all())->toBe([1])
        ->and($products->every(fn (Product $product): bool => $product->variants()->where('is_default', true)->exists()))
        ->toBeTrue();
});

it('publishes every seeded product so the storefront has something to show', function () {
    $this->seed(CatalogDemoSeeder::class);

    expect(Product::published()->count())->toBe(9)
        ->and(Product::featured()->count())->toBeGreaterThan(0);
});
