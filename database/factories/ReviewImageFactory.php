<?php

namespace Database\Factories;

use App\Models\Review;
use App\Models\ReviewImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewImage>
 */
class ReviewImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'review_id' => Review::factory(),
            'path' => 'reviews/sample-'.fake()->unique()->numberBetween(1, 99999).'.jpg',
            'sort_order' => 0,
        ];
    }
}
