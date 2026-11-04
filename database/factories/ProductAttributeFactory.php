<?php

namespace Database\Factories;

use App\Enums\ProductAttributeType;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductAttribute>
 */
class ProductAttributeFactory extends Factory
{
    /**
     * @var array<int, string>
     */
    public const NAMES = ['Warna', 'Ukuran', 'Bahan', 'Kapasitas', 'Rasa', 'Aroma'];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(self::NAMES).' '.fake()->unique()->numberBetween(1, 99999);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'type' => ProductAttributeType::Select,
            'sort_order' => 0,
        ];
    }

    /**
     * A colour attribute, whose values carry a swatch.
     */
    public function color(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Warna '.fake()->unique()->numberBetween(1, 99999),
            'type' => ProductAttributeType::Color,
        ]);
    }

    /**
     * The attribute together with the given option values, in order.
     *
     * @param  array<int, string>  $values
     */
    public function withValues(array $values): static
    {
        return $this->afterCreating(function (ProductAttribute $attribute) use ($values): void {
            foreach (array_values($values) as $position => $value) {
                $attribute->values()->create([
                    'value' => $value,
                    'sort_order' => $position,
                ]);
            }
        });
    }
}
