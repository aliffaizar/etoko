<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockEntryItem>
 */
class StockEntryItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_entry_id' => StockEntry::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => fake()->numberBetween(1, 50),
            'unit_cost' => fake()->numberBetween(5, 500) * 1000,
        ];
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state(['product_variant_id' => $variant->getKey()]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(['quantity' => $quantity]);
    }
}
