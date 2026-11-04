<?php

use App\Actions\Product\UpdateProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\UniqueConstraintViolationException;

/*
 * UpdateProduct (P1-E3-T5).
 *
 * The product screen owns the identity of the default variant (ADR-020); every
 * other variant belongs to the variant editor (P1-E3-T6).
 */

it('updates the product fields it is given', function () {
    $product = Product::factory()->withDefaultVariant()->create(['name' => 'Lama', 'base_price' => 100000]);

    app(UpdateProduct::class)->handle($product, ['name' => 'Baru', 'base_price' => 150000]);

    expect($product->refresh()->name)->toBe('Baru')
        ->and($product->base_price)->toBe(150000);
});

it('writes the sku and barcode onto the default variant', function () {
    $product = Product::factory()->create();
    $default = ProductVariant::factory()->for($product)->default()->create(['sku' => 'OLD']);

    app(UpdateProduct::class)->handle($product, ['sku' => 'NEW', 'barcode' => '8991102000123']);

    expect($default->refresh()->sku)->toBe('NEW')
        ->and($default->barcode)->toBe('8991102000123');
});

it('leaves non-default variants untouched', function () {
    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->default()->create(['sku' => 'DEFAULT']);
    $other = ProductVariant::factory()->for($product)->create(['sku' => 'OTHER']);

    app(UpdateProduct::class)->handle($product, ['sku' => 'NEW-DEFAULT']);

    expect($other->refresh()->sku)->toBe('OTHER');
});

it('clears an emptied barcode rather than storing a blank string', function () {
    $product = Product::factory()->create();
    $default = ProductVariant::factory()->for($product)->default()->create(['barcode' => '8991102000123']);

    app(UpdateProduct::class)->handle($product, ['sku' => $default->sku, 'barcode' => '']);

    expect($default->refresh()->barcode)->toBeNull();
});

it('opens a default variant for a product that somehow has none', function () {
    $product = Product::factory()->create();

    app(UpdateProduct::class)->handle($product, ['sku' => 'RECOVERED']);

    $variant = $product->variants()->firstOrFail();

    expect($variant->sku)->toBe('RECOVERED')->and($variant->is_default)->toBeTrue();
});

it('leaves the variant alone when no sku was submitted', function () {
    $product = Product::factory()->create();
    $default = ProductVariant::factory()->for($product)->default()->create(['sku' => 'KEEP']);

    app(UpdateProduct::class)->handle($product, ['name' => 'Baru']);

    expect($default->refresh()->sku)->toBe('KEEP')
        ->and($product->refresh()->name)->toBe('Baru');
});

it('changes nothing when the variant sku collides', function () {
    ProductVariant::factory()->create(['sku' => 'TAKEN']);
    $product = Product::factory()->create(['name' => 'Lama']);
    ProductVariant::factory()->for($product)->default()->create(['sku' => 'MINE']);

    expect(fn () => app(UpdateProduct::class)->handle($product, ['name' => 'Baru', 'sku' => 'TAKEN']))
        ->toThrow(UniqueConstraintViolationException::class);

    expect($product->refresh()->name)->toBe('Lama');
});
