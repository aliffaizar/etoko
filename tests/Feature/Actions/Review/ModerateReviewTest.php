<?php

use App\Actions\Review\ModerateReview;
use App\Enums\ReviewStatus;
use App\Models\Product;
use App\Models\Review;

/*
 * Showing or hiding a review moves the product's rating with it (P2-E8-T3).
 */

it('counts an approved review in the product rating', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $review = Review::factory()->pending()->rating(4)->create(['product_id' => $product->getKey()]);

    app(ModerateReview::class)->handle($review, ReviewStatus::Approved);

    expect($review->fresh()->status)->toBe(ReviewStatus::Approved)
        ->and($product->fresh()->rating_count)->toBe(1)
        ->and((float) $product->fresh()->rating_avg)->toBe(4.0);
});

it('takes a rejected review back out of the rating, and puts it back when shown again', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $review = Review::factory()->rating(2)->create(['product_id' => $product->getKey()]);
    $moderate = app(ModerateReview::class);

    $moderate->handle($review, ReviewStatus::Rejected);
    expect($product->fresh()->rating_count)->toBe(0);

    $moderate->handle($review, ReviewStatus::Approved);
    expect($product->fresh()->rating_count)->toBe(1)
        ->and((float) $product->fresh()->rating_avg)->toBe(2.0);
});
