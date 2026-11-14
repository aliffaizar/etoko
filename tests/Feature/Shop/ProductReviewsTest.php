<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Models\Warehouse;

/*
 * The reviews on the product page (P2-E8-T3, ADR-068): only those on show,
 * with the author reduced to an initial.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    Warehouse::factory()->for($this->branch)->default()->create();
});

it('shows only approved reviews, with the author as an initial', function () {
    $product = Product::factory()->withDefaultVariant()->create(['rating_avg' => 4.5, 'rating_count' => 1]);
    $author = User::factory()->customer()->create(['name' => 'Budi Santoso']);
    $shown = Review::factory()->create(['product_id' => $product->getKey(), 'user_id' => $author->getKey()]);
    Review::factory()->pending()->create(['product_id' => $product->getKey()]);
    Review::factory()->rejected()->create(['product_id' => $product->getKey()]);

    $this->get(route('shop.products.show', $product->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('reviews.summary.count', 1)
            ->where('reviews.summary.average', 4.5)
            ->has('reviews.items', 1)
            ->where('reviews.items.0.id', $shown->getKey())
            ->where('reviews.items.0.author', 'B.'));
});

it('keeps the review of a customer whose account was deleted', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $author = User::factory()->customer()->create(['name' => 'Sari']);
    Review::factory()->create(['product_id' => $product->getKey(), 'user_id' => $author->getKey()]);
    $author->delete();

    $this->get(route('shop.products.show', $product->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('reviews.items.0.author', 'S.'));
});
