<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Product names that read like a real catalogue, since Faker has no id_ID
     * commerce provider.
     *
     * @var array<int, string>
     */
    public const NAMES = [
        'Kipas Angin Berdiri', 'Setrika Uap', 'Blender Kaca', 'Rice Cooker Digital',
        'Kemeja Flanel', 'Kaos Polos Katun', 'Jaket Bomber', 'Sepatu Lari',
        'Tumbler Stainless', 'Lampu Meja LED', 'Keyboard Mekanik', 'Mouse Nirkabel',
        'Headphone Nirkabel', 'Powerbank 10000 mAh', 'Tas Ransel Laptop',
    ];

    /**
     * Prices are whole rupiah integers (ADR-008), rounded to the nearest
     * thousand so test expectations stay readable.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(self::NAMES).' '.fake()->unique()->bothify('??-###');
        $basePrice = fake()->numberBetween(15, 2500) * 1000;

        return [
            'category_id' => Category::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'type' => ProductType::Simple,
            'short_description' => fake()->sentence(),
            'description' => '<p>'.fake()->paragraph().'</p>',
            'base_price' => $basePrice,
            'compare_at_price' => null,
            'cost_price' => (int) ($basePrice * 0.7),
            'weight_gram' => fake()->numberBetween(1, 20) * 100,
            'length_cm' => fake()->numberBetween(5, 60),
            'width_cm' => fake()->numberBetween(5, 40),
            'height_cm' => fake()->numberBetween(2, 40),
            'is_active' => true,
            'is_featured' => false,
            'published_at' => now(),
            'meta_title' => null,
            'meta_description' => null,
        ];
    }

    public function variable(): static
    {
        return $this->state(['type' => ProductType::Variable]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function featured(): static
    {
        return $this->state(['is_featured' => true]);
    }

    /**
     * Active but never published, so it is invisible to buyers.
     */
    public function draft(): static
    {
        return $this->state(['published_at' => null]);
    }

    public function publishedAt(\DateTimeInterface $moment): static
    {
        return $this->state(['published_at' => $moment]);
    }

    public function discounted(int $compareAtPrice): static
    {
        return $this->state(['compare_at_price' => $compareAtPrice]);
    }

    /**
     * A product with its default variant, which is what every product has in
     * practice (ADR-020). Prefer this over `create()` in tests that touch
     * anything downstream of a variant.
     */
    public function withDefaultVariant(): static
    {
        return $this->afterCreating(function (Product $product): void {
            $product->variants()->create([
                'sku' => mb_strtoupper(fake()->unique()->bothify('SKU-????-####')),
                'is_default' => true,
                'is_active' => true,
            ]);
        });
    }
}
