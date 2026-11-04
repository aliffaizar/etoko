<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'path' => 'products/'.fake()->unique()->uuid().'.webp',
            'alt_text' => null,
            'sort_order' => 0,
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(['is_primary' => true]);
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state([
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->getKey(),
        ]);
    }

    public function sortedAt(int $position): static
    {
        return $this->state(['sort_order' => $position]);
    }
}
