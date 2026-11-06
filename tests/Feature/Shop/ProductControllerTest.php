<?php

use App\Enums\ProductAttributeType;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchProductPrice;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\Branch\StorefrontBranch;

/*
 * The product detail page (P1-E5-T6, P1-E5-T7).
 *
 * The page is found through the catalogue query, so anything the catalogue
 * hides answers 404 here — a shopper is not being refused, the shop simply does
 * not have it.
 *
 * What it shows splits in two (ADR-031): the **price** is the catalogue's and
 * is the same at every branch, while the **stock** is the chosen branch's, per
 * variant, because that is what the option picker switches between (BR-2,
 * BR-6).
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
});

it('is open to guests', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->get(route('shop.products.show', $product->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('shop/product')
            ->where('product.id', $product->getKey())
            ->has('variants', 1)
            ->has('breadcrumbs'));
});

it('answers 404 for a product that is not published', function () {
    $draft = Product::factory()->withDefaultVariant()->draft()->create();

    $this->get(route('shop.products.show', $draft->slug))->assertNotFound();
});

it('renders a product the chosen branch does not stock, saying so', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    BranchProduct::factory()->for($this->branch)->for($product)->create(['is_available' => false]);

    // The catalogue is company-wide (ADR-031), so pretending the product does
    // not exist would be the wrong answer to "does this shop sell it?".
    $this->get(route('shop.products.show', $product->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('product.id', $product->getKey())
            ->where('branch_availability.is_sold_at_branch', false));
});

it('reports the product as stocked once the shopper moves to a branch that carries it', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $elsewhere = Branch::factory()->create();

    BranchProduct::factory()->for($this->branch)->for($product)->create(['is_available' => false]);

    // Same page, same price — only the per-branch answer changes.
    $this->withSession([StorefrontBranch::SESSION_KEY => $elsewhere->getKey()])
        ->get(route('shop.products.show', $product->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('branch_availability.is_sold_at_branch', true));
});

it('reports a product as stocked when the branch has said nothing about it', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->get(route('shop.products.show', $product->slug))
        ->assertInertia(fn ($page) => $page
            ->where('branch_availability.is_sold_at_branch', true));
});

it('answers 404 for a product in a category that has been switched off', function () {
    $product = Product::factory()
        ->withDefaultVariant()
        ->for(Category::factory()->inactive())
        ->create();

    $this->get(route('shop.products.show', $product->slug))->assertNotFound();
});

it('answers 404 for a slug nobody has', function () {
    $this->get(route('shop.products.show', 'produk-yang-tidak-ada'))->assertNotFound();
});

it('prices each variant at the catalogue price, ignoring a branch price list', function () {
    $product = Product::factory()->create([
        'base_price' => 200_000,
        'compare_at_price' => 250_000,
    ]);
    $variant = ProductVariant::factory()->for($product)->default()->create();

    // Stored, in force, and deliberately not used by the website (ADR-031).
    BranchProductPrice::factory()
        ->for($this->branch)
        ->for($variant, 'variant')
        ->at(150_000)
        ->create();

    $this->get(route('shop.products.show', $product->slug))
        ->assertInertia(fn ($page) => $page
            ->where('variants.0.price', 200_000)
            ->where('variants.0.compare_at_price', 250_000)
            ->where('variants.0.discount_amount', 50_000)
            ->where('variants.0.price_source', 'product_base_price')
            ->where('product.price_from', 200_000));
});

it('keeps the catalogue price identical whichever branch the shopper is in', function () {
    $product = Product::factory()->create(['base_price' => 200_000]);
    $variant = ProductVariant::factory()->for($product)->default()->create();
    $elsewhere = Branch::factory()->create();

    BranchProductPrice::factory()->for($this->branch)->for($variant, 'variant')->at(150_000)->create();
    BranchProductPrice::factory()->for($elsewhere)->for($variant, 'variant')->at(120_000)->create();

    foreach ([$this->branch, $elsewhere] as $branch) {
        $this->withSession([StorefrontBranch::SESSION_KEY => $branch->getKey()])
            ->get(route('shop.products.show', $product->slug))
            ->assertInertia(fn ($page) => $page->where('variants.0.price', 200_000));
    }
});

it('reports each variant stock from the chosen branch only', function () {
    $product = Product::factory()->create();
    $here = ProductVariant::factory()->for($product)->default()->create();
    $elsewhere = Branch::factory()->create();
    $elsewhereWarehouse = Warehouse::factory()->for($elsewhere)->default()->create();

    Stock::factory()->inWarehouse($this->warehouse)->forVariant($here)->quantity(7)->create();
    Stock::factory()->inWarehouse($elsewhereWarehouse)->forVariant($here)->quantity(99)->create();

    $this->get(route('shop.products.show', $product->slug))
        ->assertInertia(fn ($page) => $page
            ->where('variants.0.available_quantity', 7)
            ->where('variants.0.is_in_stock', true)
            ->where('branch_availability.available_quantity', 7));
});

it('counts stock held by other shoppers checkout as unavailable', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->default()->create();

    Stock::factory()
        ->inWarehouse($this->warehouse)
        ->forVariant($variant)
        ->quantity(5)
        ->reserved(5)
        ->create();

    $this->get(route('shop.products.show', $product->slug))
        ->assertInertia(fn ($page) => $page
            ->where('variants.0.available_quantity', 0)
            ->where('variants.0.is_in_stock', false)
            ->where('branch_availability.is_in_stock', false));
});

it('sums a branch stock across its warehouses', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->default()->create();
    $second = Warehouse::factory()->for($this->branch)->create();

    Stock::factory()->inWarehouse($this->warehouse)->forVariant($variant)->quantity(4)->create();
    Stock::factory()->inWarehouse($second)->forVariant($variant)->quantity(6)->create();

    $this->get(route('shop.products.show', $product->slug))
        ->assertInertia(fn ($page) => $page->where('variants.0.available_quantity', 10));
});

it('leaves an inactive variant off the page', function () {
    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->default()->create();
    ProductVariant::factory()->for($product)->inactive()->create();

    $this->get(route('shop.products.show', $product->slug))
        ->assertInertia(fn ($page) => $page
            ->has('variants', 1)
            ->where('branch_availability.variant_count', 1));
});

it('builds the option groups from the variants own attribute values', function () {
    $colour = ProductAttribute::factory()->create([
        'name' => 'Warna',
        'type' => ProductAttributeType::Color,
    ]);
    $black = ProductAttributeValue::factory()->for($colour, 'attribute')->create([
        'value' => 'Hitam',
        'color_hex' => '#1f2937',
    ]);
    // Offered by the catalogue but carried by no variant of this product, so
    // it must not reach the picker (ADR-021).
    ProductAttributeValue::factory()->for($colour, 'attribute')->create(['value' => 'Putih']);

    $product = Product::factory()->variable()->create();
    $variant = ProductVariant::factory()->for($product)->default()->named('Hitam')->create();
    $variant->attributeValues()->attach($black);

    $this->get(route('shop.products.show', $product->slug))
        ->assertInertia(fn ($page) => $page
            ->has('attributes', 1)
            ->where('attributes.0.name', 'Warna')
            ->where('attributes.0.type', 'color')
            ->has('attributes.0.values', 1)
            ->where('attributes.0.values.0.label', 'Hitam')
            ->where('attributes.0.values.0.color_hex', '#1f2937')
            ->where('variants.0.attribute_value_ids.0', $black->getKey()));
});

it('has no option groups for a simple product', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->get(route('shop.products.show', $product->slug))
        ->assertInertia(fn ($page) => $page->has('attributes', 0));
});

it('sends the gallery with the primary photo first', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $secondary = ProductImage::factory()->for($product)->sortedAt(1)->create();
    $primary = ProductImage::factory()->for($product)->primary()->sortedAt(0)->create();

    $this->get(route('shop.products.show', $product->slug))
        ->assertInertia(fn ($page) => $page
            ->has('images', 2)
            ->where('images.0.id', $primary->getKey())
            ->where('images.0.is_primary', true)
            ->where('images.1.id', $secondary->getKey()));
});

it('tags a photo with the variant it depicts', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->default()->create();
    ProductImage::factory()->for($product)->primary()->forVariant($variant)->create();

    $this->get(route('shop.products.show', $product->slug))
        ->assertInertia(fn ($page) => $page->where('images.0.variant_id', $variant->getKey()));
});

it('shows the category trail above the product', function () {
    $root = Category::factory()->create(['name' => 'Elektronik']);
    $mid = Category::factory()->childOf($root)->create(['name' => 'Audio']);
    $leaf = Category::factory()->childOf($mid)->create(['name' => 'Headphone']);
    $product = Product::factory()->withDefaultVariant()->for($leaf)->create();

    $this->get(route('shop.products.show', $product->slug))
        ->assertInertia(fn ($page) => $page
            ->has('breadcrumbs', 3)
            ->where('breadcrumbs.0.name', 'Elektronik')
            ->where('breadcrumbs.1.name', 'Audio')
            ->where('breadcrumbs.2.name', 'Headphone'));
});

it('reports the cheapest and dearest option for a mulai-dari heading', function () {
    $product = Product::factory()->variable()->create(['base_price' => 100_000]);
    ProductVariant::factory()->for($product)->default()->create();
    ProductVariant::factory()->for($product)->overriding(175_000)->create();

    $this->get(route('shop.products.show', $product->slug))
        ->assertInertia(fn ($page) => $page
            ->where('product.price_from', 100_000)
            ->where('product.price_to', 175_000));
});

it('refuses to open the shop when no branch can sell', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    Branch::query()->forceDelete();

    $this->get(route('shop.products.show', $product->slug))->assertStatus(503);
});
