<?php

use App\Actions\Review\WriteReview;
use App\Enums\OrderStatus;
use App\Enums\ReviewStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/*
 * Only a verified purchase may review (P2-E8-T3, ADR-068): the line belongs to
 * the customer, its order is delivered or completed, and it is reviewed once.
 */

function reviewableItem(User $customer, OrderStatus $status = OrderStatus::Delivered): OrderItem
{
    $product = Product::factory()->withDefaultVariant()->create();
    $order = Order::factory()->forCustomer($customer)->status($status)->create();

    return OrderItem::factory()
        ->forOrder($order)
        ->forVariant($product->variants()->firstOrFail())
        ->create();
}

it('writes a pending verified review for a delivered line', function () {
    $customer = User::factory()->customer()->create();
    $item = reviewableItem($customer);

    $review = app(WriteReview::class)->handle($customer, $item, [
        'rating' => 4,
        'title' => 'Bagus',
        'comment' => 'Sesuai deskripsi.',
    ]);

    expect($review->status)->toBe(ReviewStatus::Pending)
        ->and($review->is_verified_purchase)->toBeTrue()
        ->and($review->product_id)->toBe($item->product_id)
        ->and($review->rating)->toBe(4);
});

it('accepts a completed order too', function () {
    $customer = User::factory()->customer()->create();
    $item = reviewableItem($customer, OrderStatus::Completed);

    $review = app(WriteReview::class)->handle($customer, $item, ['rating' => 5, 'title' => null, 'comment' => null]);

    expect($review->exists)->toBeTrue();
});

it('keeps the photos in the order they were given', function () {
    $customer = User::factory()->customer()->create();
    $item = reviewableItem($customer);

    $review = app(WriteReview::class)->handle(
        $customer,
        $item,
        ['rating' => 5, 'title' => null, 'comment' => null],
        ['reviews/a.jpg', 'reviews/b.jpg'],
    );

    expect($review->images->pluck('path')->all())->toBe(['reviews/a.jpg', 'reviews/b.jpg'])
        ->and($review->images->pluck('sort_order')->all())->toBe([0, 1]);
});

it('refuses a line that has not been received yet', function (OrderStatus $status) {
    $customer = User::factory()->customer()->create();
    $item = reviewableItem($customer, $status);

    app(WriteReview::class)->handle($customer, $item, ['rating' => 5, 'title' => null, 'comment' => null]);
})->throws(ValidationException::class)->with([
    'paid' => [OrderStatus::Paid],
    'processing' => [OrderStatus::Processing],
    'cancelled' => [OrderStatus::Cancelled],
]);

it('refuses a line on someone else\'s order', function () {
    $owner = User::factory()->customer()->create();
    $stranger = User::factory()->customer()->create();
    $item = reviewableItem($owner);

    expect(fn () => app(WriteReview::class)->handle($stranger, $item, ['rating' => 5, 'title' => null, 'comment' => null]))
        ->toThrow(ValidationException::class);
    expect(Review::query()->count())->toBe(0);
});

it('refuses a second review of the same line', function () {
    $customer = User::factory()->customer()->create();
    $item = reviewableItem($customer);
    $writeReview = app(WriteReview::class);

    $writeReview->handle($customer, $item, ['rating' => 5, 'title' => null, 'comment' => null]);

    expect(fn () => $writeReview->handle($customer, $item, ['rating' => 1, 'title' => null, 'comment' => null]))
        ->toThrow(ValidationException::class);
    expect(Review::query()->count())->toBe(1);
});
