<?php

use App\Enums\ProductAttributeType;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;

/*
 * The variant and attribute schema (P1-E3-T4).
 *
 * ADR-020: a variant's price, weight and dimension columns are overrides, so a
 * null there means "whatever the product says".
 */

it('falls back to the product for price, weight and dimensions', function () {
    $product = Product::factory()->create([
        'base_price' => 150000,
        'compare_at_price' => 200000,
        'cost_price' => 100000,
        'weight_gram' => 800,
    ]);
    $variant = ProductVariant::factory()->for($product)->create();

    expect($variant->effectivePrice())->toBe(150000)
        ->and($variant->effectiveCompareAtPrice())->toBe(200000)
        ->and($variant->effectiveCostPrice())->toBe(100000)
        ->and($variant->effectiveWeightGram())->toBe(800);
});

it('prefers its own value over the product when one is set', function () {
    $product = Product::factory()->create(['base_price' => 150000, 'weight_gram' => 800]);
    $variant = ProductVariant::factory()->for($product)->overriding(175000, 950)->create();

    expect($variant->effectivePrice())->toBe(175000)
        ->and($variant->effectiveWeightGram())->toBe(950);
});

it('reads as the product name until the variant has one of its own', function () {
    $product = Product::factory()->create(['name' => 'Kaos Polos']);

    expect(ProductVariant::factory()->for($product)->create()->displayName())->toBe('Kaos Polos')
        ->and(ProductVariant::factory()->for($product)->named('Merah / XL')->create()->displayName())
        ->toBe('Merah / XL');
});

it('requires a unique sku', function () {
    ProductVariant::factory()->create(['sku' => 'KAOS-RED-XL']);

    expect(fn () => ProductVariant::factory()->create(['sku' => 'KAOS-RED-XL']))
        ->toThrow(QueryException::class);
});

it('requires a unique barcode but allows many without one', function () {
    ProductVariant::factory()->create(['barcode' => '8991102000123']);
    ProductVariant::factory()->count(2)->create(['barcode' => null]);

    expect(fn () => ProductVariant::factory()->create(['barcode' => '8991102000123']))
        ->toThrow(QueryException::class);

    expect(ProductVariant::whereNull('barcode')->count())->toBe(2);
});

it('attaches the attribute values that define it', function () {
    $attribute = ProductAttribute::factory()->withValues(['Merah', 'Biru'])->create(['name' => 'Warna']);
    $size = ProductAttribute::factory()->withValues(['L', 'XL'])->create(['name' => 'Ukuran']);

    $variant = ProductVariant::factory()->named('Merah / XL')->create();
    $variant->attributeValues()->attach([
        $attribute->values()->where('value', 'Merah')->value('id'),
        $size->values()->where('value', 'XL')->value('id'),
    ]);

    $variant->load('attributeValues.attribute');

    expect($variant->attributeValues)->toHaveCount(2)
        ->and($variant->attributeValues->pluck('value')->sort()->values()->all())->toBe(['Merah', 'XL']);
});

it('refuses attaching the same attribute value twice', function () {
    $variant = ProductVariant::factory()->create();
    $value = ProductAttributeValue::factory()->create();

    $variant->attributeValues()->attach($value);

    expect(fn () => $variant->attributeValues()->attach($value))->toThrow(QueryException::class);
});

it('detaches the pivot rows when a variant is force deleted', function () {
    $variant = ProductVariant::factory()->create();
    $variant->attributeValues()->attach(ProductAttributeValue::factory()->create());

    $variant->forceDelete();

    expect(DB::table('product_variant_attribute_values')->count())->toBe(0);
});

it('requires a unique value per attribute, but allows the same value elsewhere', function () {
    $warna = ProductAttribute::factory()->create();
    $bahan = ProductAttribute::factory()->create();

    ProductAttributeValue::factory()->for($warna, 'attribute')->create(['value' => 'Merah']);
    ProductAttributeValue::factory()->for($bahan, 'attribute')->create(['value' => 'Merah']);

    expect(fn () => ProductAttributeValue::factory()->for($warna, 'attribute')->create(['value' => 'Merah']))
        ->toThrow(QueryException::class);
});

it('keeps colour swatches only on a colour attribute', function () {
    $colour = ProductAttribute::factory()->color()->create();
    $value = ProductAttributeValue::factory()->for($colour, 'attribute')->colored('#c81e1e')->create();

    expect($colour->type)->toBe(ProductAttributeType::Color)
        ->and($colour->type->usesColorSwatch())->toBeTrue()
        ->and($value->color_hex)->toBe('#c81e1e')
        ->and(ProductAttributeType::Select->usesColorSwatch())->toBeFalse();
});

it('reads a value by its label when one was given', function () {
    $plain = ProductAttributeValue::factory()->create(['value' => 'XL', 'label' => null]);
    $labelled = ProductAttributeValue::factory()->labelled('xl', 'Extra Large')->create();

    expect($plain->displayLabel())->toBe('XL')
        ->and($labelled->displayLabel())->toBe('Extra Large');
});

it('cascades the delete of an attribute to its values', function () {
    $attribute = ProductAttribute::factory()->withValues(['Merah', 'Biru'])->create();

    $attribute->delete();

    expect(ProductAttributeValue::count())->toBe(0);
});
