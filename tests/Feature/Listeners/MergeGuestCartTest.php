<?php

use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Cart\CartService;
use Illuminate\Support\Str;

/*
 * Guest cart merge on login (P1-E6-T5).
 *
 * When a guest logs in, any items they added as a guest should be carried
 * into their authenticated cart. If they already had a cart, quantities are
 * set to the maximum of the two to avoid doubling.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    Warehouse::factory()->for($this->branch)->default()->create();
});

it('merges guest cart items into the user cart on login', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->withDefaultVariant()->create();
    $variant = $product->variants->first();

    // Set up a guest cart via the session token
    $guestToken = Str::uuid()->toString();
    $guestCart = Cart::factory()->guest()->create(['session_token' => $guestToken]);
    CartItem::factory()->forCart($guestCart)->forVariant($variant)->create(['quantity' => 3]);

    $this->withSession([CartService::SESSION_KEY => $guestToken])
        ->post(route('login'), [
            'email' => $customer->email,
            'password' => 'password',
        ]);

    expect($customer->cart->items()->where('product_variant_id', $variant->getKey())->value('quantity'))->toBe(3)
        ->and(Cart::where('session_token', $guestToken)->exists())->toBeFalse();
});

it('keeps the higher quantity when both carts have the same variant', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->withDefaultVariant()->create();
    $variant = $product->variants->first();

    // User already has 2 of this variant in their cart
    $userCart = Cart::factory()->forUser($customer)->create();
    CartItem::factory()->forCart($userCart)->forVariant($variant)->create(['quantity' => 2]);

    // Guest cart has 5
    $guestToken = Str::uuid()->toString();
    $guestCart = Cart::factory()->guest()->create(['session_token' => $guestToken]);
    CartItem::factory()->forCart($guestCart)->forVariant($variant)->create(['quantity' => 5]);

    $this->withSession([CartService::SESSION_KEY => $guestToken])
        ->post(route('login'), [
            'email' => $customer->email,
            'password' => 'password',
        ]);

    expect($customer->cart->items()->where('product_variant_id', $variant->getKey())->value('quantity'))->toBe(5);
});

it('does not merge when the guest has an empty cart', function () {
    $customer = User::factory()->customer()->create();
    $guestToken = Str::uuid()->toString();
    Cart::factory()->guest()->create(['session_token' => $guestToken]);

    $this->withSession([CartService::SESSION_KEY => $guestToken])
        ->post(route('login'), [
            'email' => $customer->email,
            'password' => 'password',
        ]);

    expect(Cart::where('user_id', $customer->getKey())->exists())->toBeFalse();
});

it('does nothing when there is no guest cart token in the session', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->withDefaultVariant()->create();
    $variant = $product->variants->first();
    $cart = Cart::factory()->forUser($customer)->create();
    CartItem::factory()->forCart($cart)->forVariant($variant)->create(['quantity' => 1]);

    $this->post(route('login'), [
        'email' => $customer->email,
        'password' => 'password',
    ]);

    // The user's existing cart item should be untouched
    expect($cart->items()->count())->toBe(1);
});
