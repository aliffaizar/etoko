<?php

use App\Models\Product;
use App\Models\User;

/*
 * The featured products screen (P2-E8-T4). Central staff only.
 */

it('refuses the screen to a branch admin', function () {
    $this->actingAs(User::factory()->adminCabang()->create())
        ->get(route('admin.featured-products.index'))
        ->assertForbidden();
});

it('refuses a branch admin', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminCabang()->create())
        ->put(route('admin.featured-products.update'), ['product_ids' => [$product->getKey()]])
        ->assertForbidden();

    expect($product->fresh()->is_featured)->toBeFalse();
});

it('saves the list in the order it was sent', function () {
    $first = Product::factory()->withDefaultVariant()->create();
    $second = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.featured-products.update'), [
            'product_ids' => [$second->getKey(), $first->getKey()],
        ])
        ->assertSessionHasNoErrors();

    expect($second->fresh()->featured_position)->toBe(1)
        ->and($first->fresh()->featured_position)->toBe(2);
});

it('shows the featured list and the candidates to pick from', function () {
    Product::factory()->withDefaultVariant()->featured()->create(['featured_position' => 1]);
    Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.featured-products.index'))
        ->assertOk();
});
