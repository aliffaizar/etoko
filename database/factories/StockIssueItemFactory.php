<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\StockIssue;
use App\Models\StockIssueItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockIssueItem>
 */
class StockIssueItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_issue_id' => StockIssue::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => fake()->numberBetween(1, 10),
            'unit_cost' => null,
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
