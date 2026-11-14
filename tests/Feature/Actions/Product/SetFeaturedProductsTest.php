<?php

use App\Actions\Product\SetFeaturedProducts;
use App\Models\Product;

/*
 * The featured list is one ordered decision (P2-E8-T4): the screen sends the
 * whole list, and positions follow the order it was sent in.
 */

it('sets the positions in the order given', function () {
    $first = Product::factory()->withDefaultVariant()->create();
    $second = Product::factory()->withDefaultVariant()->create();

    app(SetFeaturedProducts::class)->handle([$second->getKey(), $first->getKey()]);

    expect($second->fresh())
        ->is_featured->toBeTrue()
        ->featured_position->toBe(1)
        ->and($first->fresh())
        ->is_featured->toBeTrue()
        ->featured_position->toBe(2);
});

it('unflags a product dropped from the list and clears its place', function () {
    $kept = Product::factory()->withDefaultVariant()->featured()->create(['featured_position' => 1]);
    $dropped = Product::factory()->withDefaultVariant()->featured()->create(['featured_position' => 2]);

    app(SetFeaturedProducts::class)->handle([$kept->getKey()]);

    expect($dropped->fresh())
        ->is_featured->toBeFalse()
        ->featured_position->toBeNull();
    expect($kept->fresh()->featured_position)->toBe(1);
});

it('clears the place when a product is unflagged by any other edit', function () {
    $product = Product::factory()->withDefaultVariant()->featured()->create(['featured_position' => 3]);

    $product->update(['is_featured' => false]);

    expect($product->fresh()->featured_position)->toBeNull();
});
