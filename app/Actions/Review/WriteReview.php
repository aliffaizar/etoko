<?php

namespace App\Actions\Review;

use App\Enums\OrderStatus;
use App\Enums\ReviewStatus;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer reviews a product they received (P2-E8-T3, ADR-068).
 *
 * Only a verified purchase may review: the order line must belong to the
 * customer, and its order must be `delivered` or `completed` — goods the
 * customer has in hand. One review per order line, so the same product bought
 * twice can be reviewed twice, once for each time the customer got it.
 *
 * The row starts `pending`; it is not shown and not counted until moderated.
 * The order line is locked before the check, so two submissions of the same
 * form cannot both pass it.
 */
final class WriteReview
{
    /**
     * @param  array{rating: int, title: string|null, comment: string|null}  $data
     * @param  array<int, string>  $imagePaths  Already stored on the `public` disk.
     *
     * @throws ValidationException When the purchase cannot be reviewed.
     */
    public function handle(User $customer, OrderItem $item, array $data, array $imagePaths = []): Review
    {
        return DB::transaction(function () use ($customer, $item, $data, $imagePaths): Review {
            $item = OrderItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $order = $item->order;

            $this->assertReviewable($customer, $item, $order->status, $order->user_id);

            $review = Review::query()->create([
                'product_id' => $item->product_id,
                'user_id' => $customer->getKey(),
                'order_item_id' => $item->getKey(),
                'branch_id' => $order->branch_id,
                'rating' => $data['rating'],
                'title' => $data['title'] ?? null,
                'comment' => $data['comment'] ?? null,
                'is_verified_purchase' => true,
                'status' => ReviewStatus::Pending,
            ]);

            foreach (array_values($imagePaths) as $position => $path) {
                $review->images()->create(['path' => $path, 'sort_order' => $position]);
            }

            return $review;
        });
    }

    private function assertReviewable(User $customer, OrderItem $item, OrderStatus $status, ?int $ownerId): void
    {
        if ($ownerId !== $customer->getKey()) {
            throw ValidationException::withMessages(['review' => 'Pesanan ini bukan milik Anda.']);
        }

        if ($item->product_id === null) {
            throw ValidationException::withMessages(['review' => 'Produk ini sudah tidak tersedia untuk diulas.']);
        }

        if (! in_array($status, [OrderStatus::Delivered, OrderStatus::Completed], strict: true)) {
            throw ValidationException::withMessages(['review' => 'Ulasan bisa ditulis setelah barang diterima.']);
        }

        if (Review::query()->where('user_id', $customer->getKey())->where('order_item_id', $item->getKey())->exists()) {
            throw ValidationException::withMessages(['review' => 'Anda sudah mengulas produk ini dari pesanan ini.']);
        }
    }
}
