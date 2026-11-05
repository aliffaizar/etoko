<?php

use App\Actions\Product\GenerateProductVariants;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;

/*
 * Generating a product's variants from its options (P1-E3-T6).
 *
 * The rule that matters most here is that generating twice is safe: a price
 * someone typed into a variant must survive another run of the generator.
 */

function colourAndSize(): array
{
    $colour = ProductAttribute::factory()->withValues(['Merah', 'Biru'])->create(['name' => 'Warna '.fake()->unique()->numerify('###')]);
    $size = ProductAttribute::factory()->withValues(['M', 'L'])->create(['name' => 'Ukuran '.fake()->unique()->numerify('###')]);

    return [
        $colour->values()->ordered()->pluck('id')->all(),
        $size->values()->ordered()->pluck('id')->all(),
    ];
}

it('creates one variant per combination', function () {
    $product = Product::factory()->variable()->withDefaultVariant()->create();

    $created = app(GenerateProductVariants::class)->handle($product, colourAndSize());

    expect($created)->toHaveCount(4)
        ->and($product->variants()->count())->toBe(4);
});

it('names a variant after the options that define it', function () {
    $product = Product::factory()->variable()->withDefaultVariant()->create();

    app(GenerateProductVariants::class)->handle($product, colourAndSize());

    expect($product->variants()->pluck('name')->sort()->values()->all())
        ->toBe(['Biru / L', 'Biru / M', 'Merah / L', 'Merah / M']);
});

it('builds each sku from the product sku and the option labels', function () {
    $product = Product::factory()->variable()->create();
    ProductVariant::factory()->for($product)->default()->create(['sku' => 'KMJ-FLN']);
    // A second variant means nothing is adoptable, so every combination below
    // gets a freshly derived SKU rather than inheriting the product's.
    ProductVariant::factory()->for($product)->create(['sku' => 'KMJ-FLN-LAMA']);

    app(GenerateProductVariants::class)->handle($product, colourAndSize());

    expect($product->variants()->whereNotNull('name')->pluck('sku')->sort()->values()->all())->toBe([
        'KMJ-FLN-BIRU-L', 'KMJ-FLN-BIRU-M', 'KMJ-FLN-MERAH-L', 'KMJ-FLN-MERAH-M',
    ]);
});

it('takes over the bare default variant as the first combination, keeping its sku', function () {
    $product = Product::factory()->variable()->create();
    ProductVariant::factory()->for($product)->default()->create(['sku' => 'KMJ-FLN']);

    app(GenerateProductVariants::class)->handle($product, colourAndSize());

    $adopted = $product->variants()->where('sku', 'KMJ-FLN')->sole();

    expect($product->variants()->count())->toBe(4)
        ->and($adopted->name)->toBe('Merah / M')
        ->and($adopted->is_default)->toBeTrue()
        ->and($adopted->attributeValues)->toHaveCount(2);
});

it('leaves prices and weights null so they follow the product', function () {
    $product = Product::factory()->variable()->withDefaultVariant()->create(['base_price' => 180000, 'weight_gram' => 400]);

    $created = app(GenerateProductVariants::class)->handle($product, colourAndSize());

    expect($created->every(fn (ProductVariant $variant): bool => $variant->price === null))->toBeTrue()
        ->and($created->first()->effectivePrice())->toBe(180000)
        ->and($created->first()->effectiveWeightGram())->toBe(400);
});

it('adds only the missing combinations when it runs again', function () {
    $product = Product::factory()->variable()->withDefaultVariant()->create();
    $colour = ProductAttribute::factory()->withValues(['Merah', 'Biru'])->create();
    $groups = [$colour->values()->ordered()->pluck('id')->all()];

    app(GenerateProductVariants::class)->handle($product, $groups);

    $colour->values()->create(['value' => 'Hijau', 'sort_order' => 2]);
    $created = app(GenerateProductVariants::class)->handle(
        $product,
        [$colour->values()->ordered()->pluck('id')->all()],
    );

    expect($created)->toHaveCount(1)
        ->and($created->first()->name)->toBe('Hijau')
        ->and($product->variants()->count())->toBe(3);
});

it('keeps a price typed into a variant when it runs again', function () {
    $product = Product::factory()->variable()->withDefaultVariant()->create();
    $groups = colourAndSize();

    app(GenerateProductVariants::class)->handle($product, $groups);

    $variant = $product->variants()->where('name', 'Biru / L')->sole();
    $variant->update(['price' => 275000, 'barcode' => '8991102000123']);

    app(GenerateProductVariants::class)->handle($product, $groups);

    expect($variant->fresh()->price)->toBe(275000)
        ->and($variant->fresh()->barcode)->toBe('8991102000123')
        ->and($product->variants()->count())->toBe(4);
});

it('creates nothing at all when every combination already exists', function () {
    $product = Product::factory()->variable()->withDefaultVariant()->create();
    $groups = colourAndSize();

    app(GenerateProductVariants::class)->handle($product, $groups);

    expect(app(GenerateProductVariants::class)->handle($product, $groups))->toBeEmpty();
});

it('counts a soft-deleted variant as still holding its combination', function () {
    $product = Product::factory()->variable()->withDefaultVariant()->create();
    $groups = colourAndSize();

    app(GenerateProductVariants::class)->handle($product, $groups);
    $product->variants()->where('name', 'Biru / L')->sole()->delete();

    expect(app(GenerateProductVariants::class)->handle($product, $groups))->toBeEmpty()
        ->and($product->variants()->count())->toBe(3);
});

it('gives the first generated variant the default flag when the product has none', function () {
    $product = Product::factory()->variable()->create();
    ProductVariant::factory()->for($product)->count(2)->create();

    $created = app(GenerateProductVariants::class)->handle($product, colourAndSize());

    expect($product->variants()->where('is_default', true)->count())->toBe(1)
        ->and($created->first()->is_default)->toBeTrue();
});

it('does not hand the default flag to a second generated variant', function () {
    $product = Product::factory()->variable()->create();
    ProductVariant::factory()->for($product)->count(2)->create();

    app(GenerateProductVariants::class)->handle($product, colourAndSize());

    expect($product->variants()->where('is_default', true)->count())->toBe(1);
});

it('suffixes a generated sku that another product already holds', function () {
    $other = Product::factory()->create();
    ProductVariant::factory()->for($other)->create(['sku' => 'KMJ-FLN-MERAH-M']);

    $product = Product::factory()->variable()->create();
    ProductVariant::factory()->for($product)->default()->create(['sku' => 'KMJ-FLN']);

    app(GenerateProductVariants::class)->handle($product, colourAndSize());

    expect($product->variants()->pluck('sku')->all())->not->toContain('KMJ-FLN-MERAH-M');
    expect($product->variants()->where('name', 'Merah / M')->value('sku'))->toBe('KMJ-FLN');
});

it('yields nothing when no options were given', function () {
    $product = Product::factory()->variable()->withDefaultVariant()->create();

    expect(app(GenerateProductVariants::class)->handle($product, []))->toBeEmpty()
        ->and($product->variants()->count())->toBe(1);
});
