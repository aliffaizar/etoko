<?php

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;

/*
 * The product schema and its models (P1-E3-T3).
 */

it('belongs to a category and keeps its own variants and images', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create();
    $variant = ProductVariant::factory()->for($product)->default()->create();
    ProductImage::factory()->for($product)->primary()->create();

    $product->load(['category', 'variants', 'images', 'defaultVariant', 'primaryImage']);

    expect($product->category->id)->toBe($category->id)
        ->and($product->variants)->toHaveCount(1)
        ->and($product->images)->toHaveCount(1)
        ->and($product->defaultVariant->id)->toBe($variant->id)
        ->and($product->primaryImage)->not->toBeNull();
});

it('refuses deleting a category a product still points at', function () {
    $category = Category::factory()->create();
    Product::factory()->for($category)->create();

    expect(fn () => $category->forceDelete())->toThrow(QueryException::class);
});

it('casts money to integers and the type to its enum', function () {
    $product = Product::factory()->create(['base_price' => 125000, 'cost_price' => 90000]);

    expect($product->refresh()->base_price)->toBe(125000)
        ->and($product->cost_price)->toBe(90000)
        ->and($product->type)->toBe(ProductType::Simple);
});

it('counts as published only when it is active and already released', function () {
    expect(Product::factory()->create()->isPublished())->toBeTrue()
        ->and(Product::factory()->draft()->create()->isPublished())->toBeFalse()
        ->and(Product::factory()->inactive()->create()->isPublished())->toBeFalse()
        ->and(Product::factory()->publishedAt(now()->addDay())->create()->isPublished())->toBeFalse();
});

it('scopes the catalogue to published products', function () {
    Product::factory()->create();
    Product::factory()->draft()->create();
    Product::factory()->inactive()->create();
    Product::factory()->publishedAt(now()->addDay())->create();

    expect(Product::published()->count())->toBe(1);
});

it('cascades the delete of a product to its variants and images', function () {
    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->create();
    ProductImage::factory()->for($product)->create();

    $product->forceDelete();

    expect(ProductVariant::withTrashed()->count())->toBe(0)
        ->and(ProductImage::count())->toBe(0);
});

it('keeps variants and images when a product is only soft deleted', function () {
    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->create();

    $product->delete();

    expect($product->refresh()->trashed())->toBeTrue()
        ->and(ProductVariant::where('product_id', $product->id)->count())->toBe(1);
});

it('leaves a variant photo in the product gallery when the variant goes away', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $image = ProductImage::factory()->forVariant($variant)->create();

    $variant->forceDelete();

    expect($image->refresh()->product_variant_id)->toBeNull()
        ->and($image->product_id)->toBe($product->id);
});

it('records product changes in the audit log', function () {
    $product = Product::factory()->create();

    $product->update(['name' => 'Nama Baru']);

    expect($product->auditLogs()->where('event', 'updated')->exists())->toBeTrue();
});

it('keeps the denormalised counters out of the audit log', function () {
    $product = Product::factory()->create();

    $product->update(['view_count' => 10, 'name' => 'Nama Baru']);

    $log = $product->auditLogs()->where('event', 'updated')->firstOrFail();

    expect($log->new_values)->toHaveKey('name')
        ->and($log->new_values)->not->toHaveKey('view_count');
});

it('requires a unique slug', function () {
    Product::factory()->create(['slug' => 'kipas-angin']);

    // Through a model, `ProductObserver` suffixes a taken slug rather than
    // letting the insert fail (P1-E3-T8, covered in ProductSlugAndIdentityTest).
    // The index underneath it is what this asserts, so the insert is raw.
    expect(fn () => Product::query()->insert([
        'category_id' => Category::factory()->create()->id,
        'name' => 'Kipas Angin',
        'slug' => 'kipas-angin',
        'type' => 'simple',
        'base_price' => 150000,
        'weight_gram' => 500,
    ]))->toThrow(QueryException::class);
});
