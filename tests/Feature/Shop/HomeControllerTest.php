<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchProductPrice;
use App\Models\Category;
use App\Models\Product;
use App\Services\Branch\StorefrontBranch;

/*
 * The storefront front page (P1-E5-T1).
 *
 * The rails are built from the same query as the catalogue, so the gates tested
 * there hold here too — including ADR-031: one catalogue at one price, with
 * only the stock badge per branch. What these tests pin down is what the front
 * page itself promises: a banner, the category entry points, featured products,
 * and the newest products as a deferred prop.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
});

it('is open to guests', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('shop/home')
            ->has('banners')
            ->has('categories')
            ->has('featured_products'));
});

it('shares the chosen branch and the category menu with the shell', function () {
    $root = Category::factory()->create(['name' => 'Elektronik']);
    Category::factory()->childOf($root)->create(['name' => 'Audio']);

    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('shop.branch.name', $this->branch->name)
            ->where('shop.has_chosen_branch', false)
            ->where('shop.categories.0.name', 'Elektronik')
            ->where('shop.categories.0.children.0.name', 'Audio'));
});

it('says the shopper has chosen once they pick a branch', function () {
    $picked = Branch::factory()->create();

    $this->withSession([StorefrontBranch::SESSION_KEY => $picked->getKey()])
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('shop.branch.id', $picked->getKey())
            ->where('shop.has_chosen_branch', true));
});

it('leaves inactive categories out of the menu', function () {
    Category::factory()->create(['name' => 'Elektronik']);
    Category::factory()->inactive()->create(['name' => 'Arsip']);

    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page->has('shop.categories', 1));
});

it('lists only featured products in the featured rail', function () {
    $featured = Product::factory()->withDefaultVariant()->featured()->create();
    Product::factory()->withDefaultVariant()->create();

    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->has('featured_products', 1)
            ->where('featured_products.0.id', $featured->getKey())
            ->where('featured_products.0.is_featured', true));
});

it('keeps a featured product the branch does not stock in the rail', function () {
    $withdrawn = Product::factory()->withDefaultVariant()->featured()->create();
    BranchProduct::factory()->for($this->branch)->for($withdrawn)->create(['is_available' => false]);

    // One website, one catalogue (ADR-031): the front page shows it and the
    // card says the branch does not stock it.
    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->has('featured_products', 1)
            ->where('featured_products.0.id', $withdrawn->getKey())
            ->where('featured_products.0.is_sold_at_branch', false));
});

it('prices the featured rail at the catalogue price', function () {
    $product = Product::factory()->withDefaultVariant()->featured()->create(['base_price' => 200_000]);

    BranchProductPrice::factory()
        ->for($this->branch)
        ->for($product->variants->first(), 'variant')
        ->at(140_000)
        ->create();

    // The branch's own price list stays behind the counter (ADR-031).
    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('featured_products.0.price', 200_000)
            ->where('featured_products.0.price_source', 'product_base_price'));
});

it('defers the newest-products rail and resolves it on request', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    // Below the fold, so the first paint does not wait for it.
    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page->missing('latest_products'));

    // The follow-up request Inertia sends by itself once the page is on
    // screen. The asset version has to match or Inertia answers 409, so it is
    // read from the middleware rather than guessed.
    $this->get(route('home'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'shop/home',
        'X-Inertia-Partial-Data' => 'latest_products',
    ])
        ->assertOk()
        // Asserted as JSON, not with `assertInertia`: that helper reads the
        // `page` view data of an HTML response, and a partial reload answers
        // with the page object as JSON.
        ->assertJsonCount(1, 'props.latest_products')
        ->assertJsonPath('props.latest_products.0.id', $product->getKey());
});

it('lists the top-level categories as the front page entry points', function () {
    $root = Category::factory()->sortedAt(1)->create(['name' => 'Fesyen']);
    Category::factory()->childOf($root)->create(['name' => 'Sepatu']);
    Category::factory()->sortedAt(0)->create(['name' => 'Elektronik']);

    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->has('categories', 2)
            ->where('categories.0.name', 'Elektronik')
            ->where('categories.1.name', 'Fesyen')
            ->where('categories.1.child_names.0', 'Sepatu'));
});

it('refuses to open the shop when no branch can sell', function () {
    Branch::query()->forceDelete();

    $this->get(route('home'))->assertStatus(503);
});

it('orders the featured rail by the position set in the admin (P2-E8-T4)', function () {
    $second = Product::factory()->withDefaultVariant()->featured()->create(['featured_position' => 2]);
    $first = Product::factory()->withDefaultVariant()->featured()->create(['featured_position' => 1]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('featured_products.0.id', $first->getKey())
            ->where('featured_products.1.id', $second->getKey()));
});
