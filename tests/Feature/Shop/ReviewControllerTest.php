<?php

use App\Enums\OrderStatus;
use App\Enums\ReviewStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * A customer reviews a line they received (P2-E8-T3, ADR-068). Another
 * customer's order is not found, rather than refused.
 */

beforeEach(function () {
    Storage::fake('public');
});

function receivedItem(User $customer, OrderStatus $status = OrderStatus::Delivered): OrderItem
{
    $product = Product::factory()->withDefaultVariant()->create();
    $order = Order::factory()->forCustomer($customer)->status($status)->create();

    return OrderItem::factory()
        ->forOrder($order)
        ->forVariant($product->variants()->firstOrFail())
        ->create();
}

it('redirects guests to the login page', function () {
    $item = receivedItem(User::factory()->customer()->create());

    $this->post(route('shop.account.orders.reviews.store', [$item->order, $item]))
        ->assertRedirect(route('login'));
});

it('stores a pending review with its photos on the public disk', function () {
    $customer = User::factory()->customer()->create();
    $item = receivedItem($customer);

    $this->actingAs($customer)
        ->post(route('shop.account.orders.reviews.store', [$item->order, $item]), [
            'rating' => 5,
            'title' => 'Mantap',
            'comment' => 'Barang sesuai.',
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')],
        ])
        ->assertRedirect(route('shop.account.orders.show', $item->order));

    $review = Review::query()->sole();

    expect($review->status)->toBe(ReviewStatus::Pending)
        ->and($review->images)->toHaveCount(2);
    $review->images->each(fn ($image) => Storage::disk('public')->assertExists($image->path));
});

it('answers 404 for a line on another customer\'s order', function () {
    $owner = User::factory()->customer()->create();
    $stranger = User::factory()->customer()->create();
    $item = receivedItem($owner);

    $this->actingAs($stranger)
        ->post(route('shop.account.orders.reviews.store', [$item->order, $item]), [
            'rating' => 1,
        ])
        ->assertNotFound();

    expect(Review::query()->count())->toBe(0);
});

it('keeps no photo when the purchase cannot be reviewed', function () {
    $customer = User::factory()->customer()->create();
    $item = receivedItem($customer, OrderStatus::Processing);

    $this->actingAs($customer)
        ->post(route('shop.account.orders.reviews.store', [$item->order, $item]), [
            'rating' => 5,
            'photos' => [UploadedFile::fake()->image('a.jpg')],
        ])
        ->assertSessionHasErrors('review');

    expect(Review::query()->count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});
