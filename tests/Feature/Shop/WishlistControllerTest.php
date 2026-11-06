<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Wishlist;
use App\Services\Branch\StorefrontBranch;

/*
 * Customer wishlist (P1-E6-T6).
 *
 * Toggle: add if absent, remove if present.
 * The index page shows only products that are still offered in the catalogue.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    Warehouse::factory()->for($this->branch)->default()->create();
});

// ── Index ──────────────────────────────────────────────────────────────────────

it('redirects guests to the login page', function () {
    $this->get(route('shop.account.wishlist.index'))->assertRedirect(route('login'));
});

it('renders the wishlist page for the authenticated user', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('shop.account.wishlist.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('shop/wishlist'));
});

it('shows only offered products in the wishlist', function () {
    $customer = User::factory()->customer()->create();
    $offered = Product::factory()->withDefaultVariant()->create();
    $draft = Product::factory()->withDefaultVariant()->draft()->create();

    Wishlist::factory()->forUser($customer)->forProduct($offered)->create();
    Wishlist::factory()->forUser($customer)->forProduct($draft)->create();

    $this->actingAs($customer)
        ->withSession([StorefrontBranch::SESSION_KEY => $this->branch->getKey()])
        ->get(route('shop.account.wishlist.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('items', 1));
});

// ── Toggle ─────────────────────────────────────────────────────────────────────

it('adds a product to the wishlist', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs($customer)
        ->post(route('shop.account.wishlist.toggle', $product))
        ->assertOk()
        ->assertJsonFragment(['wishlisted' => true]);

    expect($customer->wishlists()->where('product_id', $product->getKey())->exists())->toBeTrue();
});

it('removes a product from the wishlist when it is already wishlisted', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->withDefaultVariant()->create();
    Wishlist::factory()->forUser($customer)->forProduct($product)->create();

    $this->actingAs($customer)
        ->post(route('shop.account.wishlist.toggle', $product))
        ->assertOk()
        ->assertJsonFragment(['wishlisted' => false]);

    expect($customer->wishlists()->where('product_id', $product->getKey())->exists())->toBeFalse();
});

it('returns 404 when toggling a non-existent product', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->post(route('shop.account.wishlist.toggle', 99999))
        ->assertNotFound();
});

it('redirects guests to the login page on toggle', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->post(route('shop.account.wishlist.toggle', $product))
        ->assertRedirect(route('login'));
});

it('returns the updated wishlist count after toggle', function () {
    $customer = User::factory()->customer()->create();
    $p1 = Product::factory()->withDefaultVariant()->create();
    $p2 = Product::factory()->withDefaultVariant()->create();
    Wishlist::factory()->forUser($customer)->forProduct($p1)->create();

    $this->actingAs($customer)
        ->post(route('shop.account.wishlist.toggle', $p2))
        ->assertJsonFragment(['count' => 2]);
});
