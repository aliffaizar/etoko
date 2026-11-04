<?php

namespace Database\Factories;

use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductAttributeValue>
 */
class ProductAttributeValueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_attribute_id' => ProductAttribute::factory(),
            'value' => fake()->unique()->word(),
            'label' => null,
            'color_hex' => null,
            'sort_order' => 0,
        ];
    }

    public function colored(string $hex = '#ff0000'): static
    {
        return $this->state(['color_hex' => $hex]);
    }

    public function labelled(string $value, string $label): static
    {
        return $this->state(['value' => $value, 'label' => $label]);
    }
}
