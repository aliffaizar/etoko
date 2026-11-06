<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchProductPrice;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Branch\StorefrontBranch;

/*
 * The storefront catalogue (P1-E5-T3, T4, T5, T7).
 *
 * Two rules shape most of these tests.
 *
 * **ADR-031** — one website, one catalogue, one price list: every published
 * product is listed at every branch, and its price is the catalogue price. A
 * branch's own price list never reaches the storefront.
 *
 * **BR-2** — stock is still genuinely per branch, so the availability figure,
 * the stock badge and the "tersedia di cabang ini" filter all read the branch
 * in the session, and nothing else does.
 *
 * On top of both sits the catalogue's own gate: a shopper only ever sees a
 * published product, in an active category, with an active variant.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
});

/** A product the catalogue should show, at the given price. */
function sellableProduct(int $basePrice = 100_000, array $attributes = []): Product
{
    return Product::factory()->withDefaultVariant()->create([
        'base_price' => $basePrice,
        ...$attributes,
    ]);
}

it('is open to guests', function () {
    sellableProduct();

    $this->get(route('shop.catalog.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('shop/catalog')
            ->has('products.data', 1));
});

it('charges the catalogue price at every branch, ignoring a branch price list', function () {
    $product = sellableProduct(150_000);
    $elsewhere = Branch::factory()->create();

    // A branch price exists, and the storefront must not use it: it belongs to
    // the counter in that branch (POS, Phase 3), not to the website (ADR-031).
    BranchProductPrice::factory()
        ->for($elsewhere)
        ->for($product->variants->first(), 'variant')
        ->at(99_000)
        ->create();

    foreach ([null, $elsewhere->getKey()] as $sessionBranch) {
        $this->withSession(
            $sessionBranch === null ? [] : [StorefrontBranch::SESSION_KEY => $sessionBranch],
        )
            ->get(route('shop.catalog.index'))
            ->assertInertia(fn ($page) => $page
                ->where('products.data.0.price', 150_000)
                ->where('products.data.0.price_source', 'product_base_price'));
    }
});

it('still lists a product the chosen branch does not stock, and says so', function () {
    $stocked = sellableProduct();
    $withdrawn = sellableProduct();

    BranchProduct::factory()->for($this->branch)->for($withdrawn)->create(['is_available' => false]);

    // This is an online store with one catalogue (ADR-031): the product stays
    // listed, and `is_sold_at_branch` is what the card turns into
    // "tidak dijual di cabang ini" instead of a misleading "stok habis".
    $this->get(route('shop.catalog.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('products.data', 2));

    $cards = collect(
        $this->get(route('shop.catalog.index'))->viewData('page')['props']['products']['data'],
    )->keyBy('id');

    expect($cards[$withdrawn->getKey()]['is_sold_at_branch'])->toBeFalse()
        ->and($cards[$stocked->getKey()]['is_sold_at_branch'])->toBeTrue();
});

it('marks a product as sold at a branch that has said nothing about it', function () {
    $product = sellableProduct();

    // Only deviations are stored, so no row means "yes, we sell it"
    // (BranchProduct::availabilityFor).
    $this->get(route('shop.catalog.index'))
        ->assertInertia(fn ($page) => $page
            ->where('products.data.0.id', $product->getKey())
            ->where('products.data.0.is_sold_at_branch', true));
});

it('hides products that are not published', function () {
    sellableProduct();
    Product::factory()->withDefaultVariant()->draft()->create();
    Product::factory()->withDefaultVariant()->inactive()->create();
    Product::factory()->withDefaultVariant()->publishedAt(now()->addWeek())->create();

    $this->get(route('shop.catalog.index'))
        ->assertInertia(fn ($page) => $page->has('products.data', 1));
});

it('hides a product whose category has been switched off', function () {
    sellableProduct();
    Product::factory()
        ->withDefaultVariant()
        ->for(Category::factory()->inactive())
        ->create();

    $this->get(route('shop.catalog.index'))
        ->assertInertia(fn ($page) => $page->has('products.data', 1));
});

it('hides a product whose only variant is inactive', function () {
    sellableProduct();
    $shelved = Product::factory()->create();
    ProductVariant::factory()->for($shelved)->default()->inactive()->create();

    $this->get(route('shop.catalog.index'))
        ->assertInertia(fn ($page) => $page->has('products.data', 1));
});

it('reports availability from the chosen branch only', function () {
    $product = sellableProduct();
    $variant = $product->variants->first();
    $elsewhere = Branch::factory()->create();
    $elsewhereWarehouse = Warehouse::factory()->for($elsewhere)->default()->create();

    Stock::factory()->inWarehouse($elsewhereWarehouse)->forVariant($variant)->quantity(40)->create();

    $this->get(route('shop.catalog.index'))
        ->assertInertia(fn ($page) => $page
            ->where('products.data.0.available_quantity', 0)
            ->where('products.data.0.is_in_stock', false));

    $this->withSession([StorefrontBranch::SESSION_KEY => $elsewhere->getKey()])
        ->get(route('shop.catalog.index'))
        ->assertInertia(fn ($page) => $page
            ->where('products.data.0.available_quantity', 40)
            ->where('products.data.0.is_in_stock', true));
});

it('counts reserved stock as unavailable', function () {
    $product = sellableProduct();

    Stock::factory()
        ->inWarehouse($this->warehouse)
        ->forVariant($product->variants->first())
        ->quantity(10)
        ->reserved(4)
        ->create();

    $this->get(route('shop.catalog.index'))
        ->assertInertia(fn ($page) => $page->where('products.data.0.available_quantity', 6));
});

it('filters to products this branch has in stock', function () {
    $stocked = sellableProduct();
    sellableProduct();

    Stock::factory()->inWarehouse($this->warehouse)->forVariant($stocked->variants->first())->quantity(3)->create();

    $this->get(route('shop.catalog.index', ['availability' => 'in_stock']))
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.id', $stocked->getKey()));
});

it('filters by a price range using the catalogue price', function () {
    $cheap = sellableProduct(50_000);
    $dear = sellableProduct(500_000);

    // A branch price must not move a product into or out of the range, because
    // it is not the price the shopper is quoted (ADR-031).
    BranchProductPrice::factory()
        ->for($this->branch)
        ->for($dear->variants->first(), 'variant')
        ->at(60_000)
        ->create();

    $this->get(route('shop.catalog.index', ['min_price' => 100_000]))
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.id', $dear->getKey()));

    $this->get(route('shop.catalog.index', ['max_price' => 55_000]))
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.id', $cheap->getKey()));
});

it('drops a maximum price that falls below the minimum instead of refusing it', function () {
    sellableProduct(50_000);

    $this->get(route('shop.catalog.index', ['min_price' => 10_000, 'max_price' => 5_000]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.max_price', '')
            ->has('products.data', 1));
});

it('sorts by the catalogue price, not by a branch price list', function () {
    $dear = sellableProduct(300_000);
    $cheap = sellableProduct(100_000);

    // Were the branch price used, `dear` would sort first under "termurah" —
    // and the card beside it would still read Rp 300.000. The sort has to agree
    // with the figure on screen (ADR-029, ADR-031).
    BranchProductPrice::factory()
        ->for($this->branch)
        ->for($dear->variants->first(), 'variant')
        ->at(50_000)
        ->create();

    $this->get(route('shop.catalog.index', ['sort' => 'cheapest']))
        ->assertInertia(fn ($page) => $page
            ->where('products.data.0.id', $cheap->getKey())
            ->where('products.data.0.price', 100_000)
            ->where('products.data.1.id', $dear->getKey())
            ->where('products.data.1.price', 300_000));

    $this->get(route('shop.catalog.index', ['sort' => 'priciest']))
        ->assertInertia(fn ($page) => $page
            ->where('products.data.0.id', $dear->getKey())
            ->where('products.data.1.id', $cheap->getKey()));
});

it('sorts by how much has been sold', function () {
    $quiet = sellableProduct();
    $popular = sellableProduct();
    $popular->forceFill(['sold_count' => 42])->save();

    $this->get(route('shop.catalog.index', ['sort' => 'bestselling']))
        ->assertInertia(fn ($page) => $page
            ->where('products.data.0.id', $popular->getKey())
            ->where('products.data.1.id', $quiet->getKey()));
});

it('sorts by newest first by default', function () {
    $older = sellableProduct(100_000, ['published_at' => now()->subWeek()]);
    $newer = sellableProduct(100_000, ['published_at' => now()->subDay()]);

    $this->get(route('shop.catalog.index'))
        ->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'latest')
            ->where('products.data.0.id', $newer->getKey())
            ->where('products.data.1.id', $older->getKey()));
});

it('searches a product by name', function () {
    sellableProduct(100_000, ['name' => 'Headphone Nirkabel ANC', 'slug' => 'headphone-nirkabel-anc']);
    sellableProduct(100_000, ['name' => 'Blender Kaca', 'slug' => 'blender-kaca']);

    $this->get(route('shop.catalog.index', ['search' => 'headph']))
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Headphone Nirkabel ANC'));
});

it('searches a product by its short description', function () {
    sellableProduct(100_000, ['short_description' => 'Tahan air dan ringan']);
    sellableProduct(100_000, ['short_description' => 'Berbahan kaca']);

    $this->get(route('shop.catalog.index', ['search' => 'tahan air']))
        ->assertInertia(fn ($page) => $page->has('products.data', 1));
});

it('refuses a search term longer than the field allows', function () {
    $this->get(route('shop.catalog.index', ['search' => str_repeat('a', 101)]))
        ->assertSessionHasErrors('search');
});

it('refuses a sort it does not offer', function () {
    $this->get(route('shop.catalog.index', ['sort' => 'random']))
        ->assertSessionHasErrors('sort');
});

it('includes products filed under a subcategory when browsing the parent', function () {
    $parent = Category::factory()->create();
    $child = Category::factory()->childOf($parent)->create();

    Product::factory()->withDefaultVariant()->for($child)->create();
    sellableProduct();

    $this->get(route('shop.catalog.category', $parent))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('shop/catalog')
            ->has('products.data', 1)
            ->where('category.slug', $parent->slug)
            ->where('filters.category', $parent->slug));
});

it('shows the trail above a nested category', function () {
    $root = Category::factory()->create(['name' => 'Elektronik']);
    $mid = Category::factory()->childOf($root)->create(['name' => 'Audio']);
    $leaf = Category::factory()->childOf($mid)->create(['name' => 'Headphone']);

    $this->get(route('shop.catalog.category', $leaf))
        ->assertInertia(fn ($page) => $page
            ->where('category.path.0.name', 'Elektronik')
            ->where('category.path.1.name', 'Audio')
            ->has('category.path', 2));
});

it('answers 404 for a category that has been switched off', function () {
    $hidden = Category::factory()->inactive()->create();

    $this->get(route('shop.catalog.category', $hidden))->assertNotFound();
});

it('shows an empty grid for a category slug that no longer exists', function () {
    sellableProduct();

    // Silently ignoring the filter would show the whole catalogue, which reads
    // as "this category has everything" rather than "this link is stale".
    $this->get(route('shop.catalog.index', ['category' => 'kategori-yang-hilang']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('products.data', 0));
});

it('quotes the catalogue price to signed-in branch staff too', function () {
    $product = sellableProduct(150_000);
    $staffBranch = Branch::factory()->create();

    BranchProductPrice::factory()
        ->for($staffBranch)
        ->for($product->variants->first(), 'variant')
        ->at(70_000)
        ->create();

    // A warehouse clerk browsing the shop is a shopper: their own branch does
    // not become the shop they are looking at (ADR-028), and their branch's
    // price list is not what the website quotes (ADR-031).
    $this->actingAs(User::factory()->staffGudang($staffBranch)->create())
        ->get(route('shop.catalog.index'))
        ->assertInertia(fn ($page) => $page->where('products.data.0.price', 150_000));
});

it('paginates the grid', function () {
    Product::factory()->count(14)->withDefaultVariant()->create();

    $this->get(route('shop.catalog.index'))
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 12)
            ->where('products.total', 14));

    $this->get(route('shop.catalog.index', ['page' => 2]))
        ->assertInertia(fn ($page) => $page->has('products.data', 2));
});

it('refuses to open the shop when no branch can sell', function () {
    Branch::query()->forceDelete();

    $this->get(route('shop.catalog.index'))->assertStatus(503);
});

it('throttles searching', function () {
    // Search is the one expensive public read: a `LIKE %term%` over the whole
    // product table, repeatable as fast as a script can type.
    foreach (range(1, 60) as $attempt) {
        $this->get(route('shop.catalog.index', ['search' => 'kipas']))->assertOk();
    }

    $this->get(route('shop.catalog.index', ['search' => 'kipas']))
        ->assertStatus(429);
});

it('never throttles plain browsing', function () {
    // Paging through a category must not be counted against anybody, so the
    // limiter exempts a request that carries no search term.
    foreach (range(1, 70) as $attempt) {
        $this->get(route('shop.catalog.index', ['sort' => 'cheapest']))->assertOk();
    }
});
