<?php

use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;

/*
 * Shopping cart (P1-E6-T4).
 *
 * The cart works for both guests (session token) and authenticated users.
 * Stock validation happens at add/update time; the checkout will re-validate.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
});

// ── Index ──────────────────────────────────────────────────────────────────────

describe('index', function () {
    it('renders the cart page for a guest', function () {
        $this->get(route('shop.cart.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('shop/cart'));
    });

    it('renders the cart page for an authenticated user', function () {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->get(route('shop.cart.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('shop/cart'));
    });

    it('shows items already in the cart', function () {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->withDefaultVariant()->create();
        $variant = $product->variants->first();
        $cart = Cart::factory()->forUser($customer)->create();
        CartItem::factory()->forCart($cart)->forVariant($variant)->create(['quantity' => 2]);

        $this->actingAs($customer)
            ->get(route('shop.cart.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('cart.items', 1));
    });
});

/*
 * The header badge (P1-E7-T4).
 *
 * It counts units, not lines, because every "N item" the application prints is
 * a sum of quantities. A badge reading "1" beside a summary reading "4 item"
 * on the same screen is a disagreement nobody can resolve by looking.
 */
it('counts units rather than lines for the header badge', function () {
    $customer = User::factory()->customer()->create();
    $cart = Cart::factory()->forUser($customer)->create();
    $first = Product::factory()->withDefaultVariant()->create()->variants->first();
    $second = Product::factory()->withDefaultVariant()->create()->variants->first();

    CartItem::factory()->forCart($cart)->forVariant($first)->create(['quantity' => 3]);
    CartItem::factory()->forCart($cart)->forVariant($second)->create(['quantity' => 1]);

    $this->actingAs($customer)
        ->get(route('shop.cart.index'))
        ->assertInertia(fn ($page) => $page->where('shop.cart_count', 4));
});

it('shows no badge count for a visitor with no cart', function () {
    $this->get(route('shop.cart.index'))
        ->assertInertia(fn ($page) => $page->where('shop.cart_count', 0));
});

// ── Store ──────────────────────────────────────────────────────────────────────

describe('store', function () {
    it('adds an item for a guest', function () {
        $product = Product::factory()->withDefaultVariant()->create();
        $variant = $product->variants->first();
        Stock::factory()->inWarehouse($this->warehouse)->forVariant($variant)->quantity(10)->create();

        $this->post(route('shop.cart.store'), [
            'product_variant_id' => $variant->getKey(),
            'quantity' => 2,
        ])->assertOk()
            ->assertJsonStructure(['item_count']);

        expect(Cart::count())->toBe(1)
            ->and(CartItem::first()->quantity)->toBe(2);
    });

    it('adds an item for an authenticated user', function () {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->withDefaultVariant()->create();
        $variant = $product->variants->first();
        Stock::factory()->inWarehouse($this->warehouse)->forVariant($variant)->quantity(10)->create();

        $this->actingAs($customer)
            ->post(route('shop.cart.store'), [
                'product_variant_id' => $variant->getKey(),
                'quantity' => 1,
            ])->assertOk();

        expect($customer->cart->items()->count())->toBe(1);
    });

    it('increments quantity when the same variant is added again', function () {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->withDefaultVariant()->create();
        $variant = $product->variants->first();
        Stock::factory()->inWarehouse($this->warehouse)->forVariant($variant)->quantity(10)->create();

        $this->actingAs($customer)
            ->post(route('shop.cart.store'), ['product_variant_id' => $variant->getKey(), 'quantity' => 2]);
        $this->actingAs($customer)
            ->post(route('shop.cart.store'), ['product_variant_id' => $variant->getKey(), 'quantity' => 3]);

        expect(CartItem::first()->quantity)->toBe(5);
    });

    it('returns 422 when stock is insufficient', function () {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->withDefaultVariant()->create();
        $variant = $product->variants->first();
        Stock::factory()->inWarehouse($this->warehouse)->forVariant($variant)->quantity(1)->create();

        $this->actingAs($customer)
            ->post(route('shop.cart.store'), ['product_variant_id' => $variant->getKey(), 'quantity' => 5])
            ->assertStatus(422);
    });

    it('requires quantity to be at least 1', function () {
        $product = Product::factory()->withDefaultVariant()->create();
        $variant = $product->variants->first();

        $this->post(route('shop.cart.store'), ['product_variant_id' => $variant->getKey(), 'quantity' => 0])
            ->assertSessionHasErrors('quantity');
    });

    it('requires a valid product_variant_id', function () {
        $this->post(route('shop.cart.store'), ['product_variant_id' => 99999, 'quantity' => 1])
            ->assertSessionHasErrors('product_variant_id');
    });
});

// ── Update ─────────────────────────────────────────────────────────────────────

describe('update', function () {
    it('changes the quantity of a cart item', function () {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->withDefaultVariant()->create();
        $variant = $product->variants->first();
        Stock::factory()->inWarehouse($this->warehouse)->forVariant($variant)->quantity(10)->create();
        $cart = Cart::factory()->forUser($customer)->create();
        $item = CartItem::factory()->forCart($cart)->forVariant($variant)->create(['quantity' => 2]);

        $this->actingAs($customer)
            ->patch(route('shop.cart.update', $item), ['quantity' => 4])
            ->assertOk();

        expect($item->fresh()->quantity)->toBe(4);
    });

    it('removes the item when quantity is set to 0', function () {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->withDefaultVariant()->create();
        $variant = $product->variants->first();
        $cart = Cart::factory()->forUser($customer)->create();
        $item = CartItem::factory()->forCart($cart)->forVariant($variant)->create(['quantity' => 2]);

        $this->actingAs($customer)
            ->patch(route('shop.cart.update', $item), ['quantity' => 0])
            ->assertOk();

        expect(CartItem::find($item->getKey()))->toBeNull();
    });

    it('returns 404 when the item belongs to a different cart', function () {
        $customer = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        $product = Product::factory()->withDefaultVariant()->create();
        $variant = $product->variants->first();
        $otherCart = Cart::factory()->forUser($other)->create();
        $item = CartItem::factory()->forCart($otherCart)->forVariant($variant)->create();

        $this->actingAs($customer)
            ->patch(route('shop.cart.update', $item), ['quantity' => 1])
            ->assertNotFound();
    });

    it('returns 422 when new quantity exceeds available stock', function () {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->withDefaultVariant()->create();
        $variant = $product->variants->first();
        Stock::factory()->inWarehouse($this->warehouse)->forVariant($variant)->quantity(2)->create();
        $cart = Cart::factory()->forUser($customer)->forBranch($this->branch)->create();
        $item = CartItem::factory()->forCart($cart)->forVariant($variant)->create(['quantity' => 1]);

        $this->actingAs($customer)
            ->patch(route('shop.cart.update', $item), ['quantity' => 10])
            ->assertStatus(422);
    });
});

// ── Destroy ────────────────────────────────────────────────────────────────────

describe('destroy', function () {
    it('removes an item from the cart', function () {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->withDefaultVariant()->create();
        $variant = $product->variants->first();
        $cart = Cart::factory()->forUser($customer)->create();
        $item = CartItem::factory()->forCart($cart)->forVariant($variant)->create();

        $this->actingAs($customer)
            ->delete(route('shop.cart.destroy', $item))
            ->assertOk();

        expect(CartItem::find($item->getKey()))->toBeNull();
    });

    it('returns 404 when the item belongs to a different cart', function () {
        $customer = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        $product = Product::factory()->withDefaultVariant()->create();
        $variant = $product->variants->first();
        $otherCart = Cart::factory()->forUser($other)->create();
        $item = CartItem::factory()->forCart($otherCart)->forVariant($variant)->create();

        $this->actingAs($customer)
            ->delete(route('shop.cart.destroy', $item))
            ->assertNotFound();
    });
});
