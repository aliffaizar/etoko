<?php

use App\Actions\Product\CreateProduct;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\UniqueConstraintViolationException;

/*
 * CreateProduct (P1-E3-T5).
 *
 * ADR-020: every product owns exactly one default variant from the moment it
 * exists, because stock and order lines point at a variant and never at a
 * product.
 */

function createProductAttributes(array $overrides = []): array
{
    return [
        'category_id' => Category::factory()->create()->id,
        'name' => 'Kipas Angin Berdiri',
        'type' => ProductType::Simple,
        'base_price' => 250000,
        'weight_gram' => 3500,
        'sku' => 'KPS-16',
        ...$overrides,
    ];
}

it('opens a default variant for the product', function () {
    $product = app(CreateProduct::class)->handle(createProductAttributes());

    $variant = $product->variants()->firstOrFail();

    expect($variant->sku)->toBe('KPS-16')
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->is_active)->toBeTrue();
});

it('leaves the variant price and weight null so they follow the product', function () {
    $product = app(CreateProduct::class)->handle(createProductAttributes());

    $variant = $product->variants()->firstOrFail();

    expect($variant->price)->toBeNull()
        ->and($variant->weight_gram)->toBeNull()
        ->and($variant->effectivePrice())->toBe(250000)
        ->and($variant->effectiveWeightGram())->toBe(3500);
});

it('derives a unique slug from the name', function () {
    app(CreateProduct::class)->handle(createProductAttributes());
    app(CreateProduct::class)->handle(createProductAttributes(['sku' => 'KPS-17']));

    expect(Product::pluck('slug')->all())->toBe(['kipas-angin-berdiri', 'kipas-angin-berdiri-2']);
});

it('respects a slug that was handed in', function () {
    $product = app(CreateProduct::class)->handle(createProductAttributes(['slug' => 'kipas-khusus']));

    expect($product->slug)->toBe('kipas-khusus');
});

it('does not reuse the slug of a soft deleted product', function () {
    app(CreateProduct::class)->handle(createProductAttributes())->delete();

    $second = app(CreateProduct::class)->handle(createProductAttributes(['sku' => 'KPS-17']));

    expect($second->slug)->toBe('kipas-angin-berdiri-2');
});

it('stores an empty barcode as null, so many products can go without one', function () {
    app(CreateProduct::class)->handle(createProductAttributes(['barcode' => '']));
    app(CreateProduct::class)->handle(createProductAttributes(['sku' => 'KPS-17', 'barcode' => '']));

    expect(ProductVariant::whereNull('barcode')->count())->toBe(2);
});

it('creates nothing at all when the variant cannot be saved', function () {
    ProductVariant::factory()->create(['sku' => 'KPS-16']);

    expect(fn () => app(CreateProduct::class)->handle(createProductAttributes()))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Product::count())->toBe(1);
});
