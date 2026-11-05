<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockOpnameItem>
 */
class StockOpnameItemFactory extends Factory
{
    /**
     * A line that has been added to the session but not counted yet, which is
     * how every line starts.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_opname_id' => StockOpname::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'system_quantity' => fake()->numberBetween(0, 100),
            'counted_quantity' => null,
            'notes' => null,
        ];
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state(['product_variant_id' => $variant->getKey()]);
    }

    /**
     * A line the system believed held `$system` and that somebody counted as
     * `$counted`.
     */
    public function counted(int $system, int $counted): static
    {
        return $this->state([
            'system_quantity' => $system,
            'counted_quantity' => $counted,
        ]);
    }
}
