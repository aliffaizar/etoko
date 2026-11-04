<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * Price, weight and dimensions are left null on purpose: a variant only
     * stores what differs from its product (ADR-020), and the `overriding`
     * state is there for the tests that need a difference.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => mb_strtoupper(fake()->unique()->bothify('SKU-????-####')),
            'barcode' => null,
            'name' => null,
            'price' => null,
            'compare_at_price' => null,
            'cost_price' => null,
            'weight_gram' => null,
            'length_cm' => null,
            'width_cm' => null,
            'height_cm' => null,
            'image_path' => null,
            'is_active' => true,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function named(string $name): static
    {
        return $this->state(['name' => $name]);
    }

    public function withBarcode(): static
    {
        return $this->state(['barcode' => fake()->unique()->ean13()]);
    }

    /**
     * A variant that overrides its product's price and weight.
     */
    public function overriding(int $price, ?int $weightGram = null): static
    {
        return $this->state([
            'price' => $price,
            'weight_gram' => $weightGram,
        ]);
    }
}
