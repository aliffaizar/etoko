<?php

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
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

it('gives every seeded product exactly one default variant', function () {
    $this->seed(CatalogDemoSeeder::class);

    $products = Product::withCount(['variants'])->get();

    expect($products->every(fn (Product $product): bool => $product->variants()->where('is_default', true)->count() === 1))
        ->toBeTrue()
        ->and($products->every(fn (Product $product): bool => $product->variants_count >= 1))
        ->toBeTrue();
});

it('leaves a simple product with its single variant', function () {
    $this->seed(CatalogDemoSeeder::class);

    $simple = Product::query()->where('type', ProductType::Simple)->withCount(['variants'])->get();

    expect($simple)->toHaveCount(7)
        ->and($simple->pluck('variants_count')->unique()->all())->toBe([1]);
});

it('generates the variant grid of every product that has options', function () {
    $this->seed(CatalogDemoSeeder::class);

    // Kaos: 3 colours x 4 sizes. Sepatu: 4 sizes.
    expect(Product::query()->where('slug', 'kaos-polos-katun-combed')->firstOrFail()->variants()->count())->toBe(12)
        ->and(Product::query()->where('slug', 'sepatu-lari-ringan')->firstOrFail()->variants()->count())->toBe(4);
});

it('gives every variant its own sku', function () {
    $this->seed(CatalogDemoSeeder::class);

    $skus = Product::query()->with('variants:id,product_id,sku')->get()
        ->flatMap(fn (Product $product): array => $product->variants->pluck('sku')->all());

    expect($skus->unique())->toHaveCount($skus->count());
});

it('seeds the shared attributes with their swatches', function () {
    $this->seed(CatalogDemoSeeder::class);

    $colour = ProductAttribute::query()->where('name', 'Warna')->firstOrFail();

    expect($colour->type->usesColorSwatch())->toBeTrue()
        ->and($colour->values)->toHaveCount(3)
        ->and($colour->values->every(fn ($value): bool => $value->color_hex !== null))->toBeTrue()
        ->and(ProductAttribute::query()->where('name', 'Ukuran')->firstOrFail()->values)->toHaveCount(4);
});

it('can run twice without duplicating attributes or variants', function () {
    $this->seed(CatalogDemoSeeder::class);

    $attributeCount = ProductAttribute::count();
    $variantCount = Product::query()->where('slug', 'kaos-polos-katun-combed')->firstOrFail()->variants()->count();

    $this->seed(CatalogDemoSeeder::class);

    expect(ProductAttribute::count())->toBe($attributeCount)
        ->and(ProductAttribute::query()->where('name', 'Warna')->firstOrFail()->values()->count())->toBe(3)
        ->and(Product::query()->where('slug', 'kaos-polos-katun-combed')->firstOrFail()->variants()->count())
        ->toBe($variantCount);
});

it('publishes every seeded product so the storefront has something to show', function () {
    $this->seed(CatalogDemoSeeder::class);

    expect(Product::published()->count())->toBe(9)
        ->and(Product::featured()->count())->toBeGreaterThan(0);
});
