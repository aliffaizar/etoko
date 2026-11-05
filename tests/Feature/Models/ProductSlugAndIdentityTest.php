<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;

/*
 * Catalogue identity: slug, SKU and barcode (P1-E3-T8).
 *
 * The rules here are enforced by observers rather than by the form requests, so
 * they hold for a seeder, a CSV import and tinker as much as for the panel.
 * These tests go through the models directly for exactly that reason.
 */

it('derives a product slug from its name when none is given', function () {
    $product = Product::factory()->create(['name' => 'Kipas Angin Berdiri', 'slug' => '']);

    expect($product->slug)->toBe('kipas-angin-berdiri');
});

it('suffixes a product slug that is already taken', function () {
    Product::factory()->create(['name' => 'Setrika Uap', 'slug' => 'setrika-uap']);

    $second = Product::factory()->create(['name' => 'Setrika Uap', 'slug' => '']);

    expect($second->slug)->toBe('setrika-uap-2');
});

it('counts a soft-deleted product as still holding its slug', function () {
    Product::factory()->create(['name' => 'Blender Kaca', 'slug' => 'blender-kaca'])->delete();

    expect(Product::factory()->create(['name' => 'Blender Kaca', 'slug' => ''])->slug)
        ->toBe('blender-kaca-2');
});

it('leaves a free slug the caller supplied alone', function () {
    $product = Product::factory()->create(['name' => 'Rice Cooker Digital', 'slug' => 'rc-digital-2026']);

    expect($product->slug)->toBe('rc-digital-2026');
});

it('lets a product keep its own slug across an update', function () {
    $product = Product::factory()->create(['slug' => 'tumbler-stainless']);

    $product->update(['name' => 'Tumbler Stainless 750 ml']);

    expect($product->fresh()->slug)->toBe('tumbler-stainless');
});

it('suffixes a slug an update would collide with', function () {
    Product::factory()->create(['slug' => 'lampu-meja-led']);
    $product = Product::factory()->create(['slug' => 'lampu-gantung']);

    $product->update(['slug' => 'lampu-meja-led']);

    expect($product->fresh()->slug)->toBe('lampu-meja-led-2');
});

it('falls back to a usable slug when the name has no slug characters', function () {
    expect(Product::factory()->create(['name' => '???', 'slug' => ''])->slug)->toBe('item');
});

it('derives a category slug the same way', function () {
    Category::factory()->create(['name' => 'Elektronik', 'slug' => 'elektronik']);

    $second = Category::factory()->create(['name' => 'Elektronik', 'slug' => '']);

    expect($second->slug)->toBe('elektronik-2');
});

it('upper-cases a variant sku so case alone cannot duplicate one', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create(['sku' => ' kps-16-putih ']);

    expect($variant->sku)->toBe('KPS-16-PUTIH');

    expect(fn () => ProductVariant::factory()->for($product)->create(['sku' => 'kps-16-putih']))
        ->toThrow(QueryException::class);
});

it('refuses a second variant with the same barcode', function () {
    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->create(['barcode' => '8991102000123']);

    expect(fn () => ProductVariant::factory()->for($product)->create(['barcode' => '8991102000123']))
        ->toThrow(QueryException::class);
});

it('stores a blank barcode as null so every variant may be without one', function () {
    $product = Product::factory()->create();

    $first = ProductVariant::factory()->for($product)->create(['barcode' => '']);
    $second = ProductVariant::factory()->for($product)->create(['barcode' => '   ']);

    expect($first->barcode)->toBeNull()
        ->and($second->barcode)->toBeNull();
});

it('stores a blank variant name as null so it falls back to the product name', function () {
    $product = Product::factory()->create(['name' => 'Kaos Polos Katun']);
    $variant = ProductVariant::factory()->for($product)->create(['name' => '  ']);

    expect($variant->name)->toBeNull()
        ->and($variant->displayName())->toBe('Kaos Polos Katun');
});
