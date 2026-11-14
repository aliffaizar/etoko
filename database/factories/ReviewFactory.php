<?php

namespace Database\Factories;

use App\Enums\ReviewStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A verified, approved five-star review — the states change the rest.
 *
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'user_id' => User::factory(),
            'order_item_id' => null,
            'branch_id' => null,
            'rating' => 5,
            'title' => fake()->sentence(3),
            'comment' => fake()->paragraph(),
            'is_verified_purchase' => true,
            'status' => ReviewStatus::Approved,
            'reply' => null,
            'replied_by' => null,
            'replied_at' => null,
            'helpful_count' => 0,
        ];
    }

    public function rating(int $rating): static
    {
        return $this->state(['rating' => $rating]);
    }

    public function pending(): static
    {
        return $this->state(['status' => ReviewStatus::Pending]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => ReviewStatus::Rejected]);
    }

    public function forOrderItem(OrderItem $item): static
    {
        return $this->state([
            'product_id' => $item->product_id,
            'order_item_id' => $item->getKey(),
            'branch_id' => $item->order->branch_id,
            'user_id' => $item->order->user_id,
        ]);
    }
}
